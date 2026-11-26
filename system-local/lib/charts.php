<?php
declare(strict_types=1);
/**
 * Данные для графиков. Один источник и для страницы, и для JSON-ответа,
 * поэтому переключение периода не требует перезагрузки страницы.
 */

/**
 * Срезы по времени за период.
 *
 * @param int    $sec  размер среза в секундах (60 / 300 / 3600)
 * @param string $from начало периода 'Y-m-d H:i:s'
 * @param string $to   конец периода
 * @return array{series:array, totals:array, from:string, to:string, sec:int}
 */
function charts_data(int $sec, string $from, string $to): array
{
    // ApexCharts ждёт миллисекунды на datetime-оси
    $ts = static fn(string $b): int => (strtotime($b) ?: 0) * 1000;

    // --- закрытые задачи за срез ---
    $rowsTasks = all(
        "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/?)*?) b, COUNT(*) c
         FROM events
         WHERE kind='system' AND title='task-status' AND ts>=? AND ts<=?
         GROUP BY b ORDER BY b",
        'iiss',
        [$sec, $sec, $from, $to]
    );
    $seriesTasks = array_map(static fn($r) => [$ts((string)$r['b']), (int)$r['c']], $rowsTasks);

    // --- токены ---
    $rowsTok = all(
        "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/?)*?) b,
                SUM(tok_in) ti, SUM(tok_cache) tc, SUM(tok_out) tout, SUM(tok_think) tt
         FROM llm_calls
         WHERE ts>=? AND ts<=?
         GROUP BY b ORDER BY b",
        'iiss',
        [$sec, $sec, $from, $to]
    );
    $tokens = ['in' => [], 'cache' => [], 'out' => [], 'think' => []];
    foreach ($rowsTok as $r) {
        $t = $ts((string)$r['b']);
        $tokens['in'][] = [$t, (int)$r['ti']];
        $tokens['cache'][] = [$t, (int)$r['tc']];
        $tokens['out'][] = [$t, (int)$r['tout']];
        $tokens['think'][] = [$t, (int)$r['tt']];
    }

    // --- спенд ---
    $rowsMoney = all(
        "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/?)*?) b, SUM(cost_usd) c
         FROM llm_calls
         WHERE ts>=? AND ts<=?
         GROUP BY b ORDER BY b",
        'iiss',
        [$sec, $sec, $from, $to]
    );
    $seriesMoney = array_map(
        static fn($r) => [$ts((string)$r['b']), round((float)$r['c'], 6)],
        $rowsMoney
    );

    // --- проекты: задачи по направлениям ---
    $rowsProj = all(
        "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/?)*?) b, project_id, COUNT(*) c
         FROM agent_runs
         WHERE ts>=? AND ts<=? AND project_id IS NOT NULL
         GROUP BY b, project_id ORDER BY b",
        'iiss',
        [$sec, $sec, $from, $to]
    );
    $projNames = [];
    foreach (all("SELECT id, title, icon FROM projects WHERE kind='project' ORDER BY id") as $p) {
        $projNames[(int)$p['id']] = trim(((string)($p['icon'] ?? '')) . ' ' . (string)$p['title']);
    }
    $byProject = [];
    foreach ($rowsProj as $r) {
        $pid = (int)$r['project_id'];
        $byProject[$pid][$ts((string)$r['b'])] = (int)$r['c'];
    }
    $seriesProj = [];
    foreach ($byProject as $pid => $points) {
        ksort($points);
        $data = [];
        foreach ($points as $t => $c) {
            $data[] = [$t, $c];
        }
        $seriesProj[] = ['name' => $projNames[$pid] ?? ('#' . $pid), 'data' => $data];
    }

    // --- итоги ---
    $sumTasks = 0;
    foreach ($rowsTasks as $r) {
        $sumTasks += (int)$r['c'];
    }
    $sumTok = 0;
    foreach ($rowsTok as $r) {
        $sumTok += (int)$r['ti'] + (int)$r['tc'] + (int)$r['tout'] + (int)$r['tt'];
    }
    $sumMoney = 0.0;
    foreach ($rowsMoney as $r) {
        $sumMoney += (float)$r['c'];
    }

    return [
        'series' => [
            'tasks'    => $seriesTasks,
            'tokens'   => $tokens,
            'money'    => $seriesMoney,
            'projects' => $seriesProj,
        ],
        'totals' => [
            'tasks'    => $sumTasks,
            'tokens'   => $sumTok,
            'money'    => round($sumMoney, 6),
            'projects' => count($byProject),
            'buckets'  => count($rowsTasks),
        ],
        'from' => $from,
        'to'   => $to,
        'sec'  => $sec,
    ];
}

/** Период отображения: сегодня — с полуночи, неделя — за 7 суток. */
function charts_period(string $range): array
{
    if ($range === 'week') {
        return [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s')];
    }
    return [date('Y-m-d 00:00:00'), date('Y-m-d H:i:s')];
}
