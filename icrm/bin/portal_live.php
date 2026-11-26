<?php
declare(strict_types=1);
/** Генератор live-данных портала openmvp.online: сегодняшняя активность по проектам.
 *  Пишет /var/www/openmvp-portal/live.json (портал подхватывает его на клиенте).
 *  Запуск cron:* cron: каждые 5 минут */
require_once dirname(__DIR__) . '/lib/core.php';

const OUT = '/var/www/openmvp-portal/live.json';

// имя проекта в БД -> id карточки портала
const MAP = [
    'apimedia'      => 'apimedia',
    'worker.skin'   => 'workerskin',
    'cpark.click'   => 'cpark',
    'faptubes.click'=> 'faptubes',
    'fabra.live'    => 'fabra',
    'gitgame.live'  => 'gitgame',
    'dildo mll'     => 'dildomll',
    'fapa'          => 'fapa',
    'tapapp'        => 'tapapp',
    'dagi'          => 'dagi',
    'vibemetrics'   => 'vibemetrics',
    'ai spy'        => 'aispy',
    'tapapp crm'   => 'tapapp',
    'ibody'        => 'ibody',
    'aiwebback'    => 'aiwebback',
    'apiadult'     => 'apiadult',
    'spottygun'    => 'spottygun',
    'bi gpu'       => 'bigpu',
    'humanbro'     => 'humanbro',
    'facebook ads studio' => 'fas',
    'bigquery.live'=> 'bigquery',
    'openpay'      => 'openpay',
    'gsb api'      => 'gsbapi',
    'openmvp.online' => 'openmvp',
];

$today = date('Y-m-d');

// запуски (задачи) за сегодня по проектам
$runs = [];
foreach (all(
    "SELECT p.title, COUNT(*) n FROM agent_runs r JOIN projects p ON p.id = r.project_id
      WHERE r.ts >= CURDATE() GROUP BY p.id"
) as $r) {
    $runs[strtolower((string)$r['title'])] = (int)$r['n'];
}

// вызовы LLM за сегодня: токены и стоимость
$calls = [];
foreach (all(
    "SELECT p.title, SUM(c.tok_in + c.tok_cache + c.tok_out) tok, SUM(c.cost_usd) cost,
            SUM(c.call_no) calls
       FROM llm_calls c JOIN projects p ON p.id = c.project_id
      WHERE c.ts >= CURDATE() GROUP BY p.id"
) as $r) {
    $calls[strtolower((string)$r['title'])] = [
        'tokens' => (int)$r['tok'],
        'cost'   => round((float)$r['cost'], 4),
        'calls'  => (int)$r['calls'],
    ];
}

$projects = [];
$totals = ['jobs' => 0, 'tokens' => 0, 'cost' => 0.0, 'calls' => 0];
foreach (array_unique(array_merge(array_keys($runs), array_keys($calls))) as $name) {
    $pid = MAP[$name] ?? null;
    if ($pid === null) continue;
    $jobs = $runs[$name] ?? 0;
    $c = $calls[$name] ?? ['tokens' => 0, 'cost' => 0.0, 'calls' => 0];
    $projects[$pid] = [
        'jobs'   => $jobs,
        'calls'  => $c['calls'],
        'tokens' => $c['tokens'],
        'cost'   => $c['cost'],
    ];
    $totals['jobs'] += $jobs;
    $totals['tokens'] += $c['tokens'];
    $totals['cost'] += $c['cost'];
    $totals['calls'] += $c['calls'];
}
$totals['cost'] = round($totals['cost'], 4);

// спенд/доход сети по дням (из money.daily): 14 дней
$daily = [];
$map = [];
foreach (all(
    "SELECT d, ROUND(SUM(cost),4) spend, ROUND(SUM(revenue),4) revenue
       FROM money.daily WHERE d >= CURDATE() - INTERVAL 13 DAY GROUP BY d"
) as $r) {
    $map[(string)$r['d']] = [(float)$r['spend'], (float)$r['revenue']];
}
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $v = $map[$d] ?? [0.0, 0.0];
    $daily[] = ['date' => $d, 'spend' => $v[0], 'revenue' => $v[1]];
}

$out = [
    'date'        => $today,
    'generated_at'=> date('c'),
    'projects'    => $projects,
    'totals'      => $totals,
    'daily'       => $daily,
];

$tmp = OUT . '.tmp';
file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
rename($tmp, OUT);
chmod(OUT, 0644);
echo "live.json written: {$totals['jobs']} jobs, {$totals['tokens']} tok, \${$totals['cost']}\n";
