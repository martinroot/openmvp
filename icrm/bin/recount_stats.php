<?php
declare(strict_types=1);
/** Пересчёт агрегатов projects из таблиц-источников (llm_calls + agent_runs).
 *  Канон: токены/стоимость/вызовы/длительность = llm_calls; запуски/последний запуск = agent_runs.
 *  Ноды, шлющие только type=run (без llm_calls): токены/стоимость берём из agent_runs.
 *  Проект-узел: всё по project_id; задача/подзадача: только строки со своим task_id.
 *  NOTE: 'd'-параметры при 8+ плейсхолдерах MariaDB режет до int — cost шлём как 's'. */
require_once dirname(__DIR__) . '/lib/core.php';

$rows = all("SELECT id, kind FROM projects");
$n = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $isTask = in_array($r['kind'], ['task', 'subtask'], true);
    $col = $isTask ? 'task_id' : 'project_id';

    $c = one(
        "SELECT COALESCE(SUM(call_no),0) calls, COALESCE(SUM(tok_in),0) ti,
                COALESCE(SUM(tok_cache),0) tc, COALESCE(SUM(tok_out),0) to_,
                COALESCE(SUM(cost_usd),0) cost, COALESCE(SUM(duration_ms),0) dur,
                MAX(ts) last_ts
           FROM llm_calls WHERE {$col} = ?", 'i', [$id]);

    $g = one(
        "SELECT COUNT(*) runs, MAX(ts) run_ts FROM agent_runs WHERE {$col} = ?", 'i', [$id]);

    if ((int)$c['calls'] === 0 && (int)$g['runs'] > 0) {
        $c = one(
            "SELECT 0 calls, COALESCE(SUM(tok_in),0) ti, COALESCE(SUM(tok_cache),0) tc,
                    COALESCE(SUM(tok_out),0) to_, COALESCE(SUM(cost_usd),0) cost,
                    COALESCE(SUM(duration_ms),0) dur, MAX(ts) last_ts
               FROM agent_runs WHERE {$col} = ?", 'i', [$id]);
    }

    $cur = one("SELECT last_run_at FROM projects WHERE id = ?", 'i', [$id]);
    $last = max((string)($c['last_ts'] ?? ''), (string)($g['run_ts'] ?? ''), (string)($cur['last_run_at'] ?? ''));
    if ($last === '') { $last = null; }

    q("UPDATE projects SET
         calls_count = ?, tok_in = ?, tok_cache = ?, tok_out = ?,
         duration_ms = ?, runs_count = ?
       WHERE id = ?",
      'iiiiiii',
      [(int)$c['calls'], (int)$c['ti'], (int)$c['tc'], (int)$c['to_'],
       (int)$c['dur'], (int)$g['runs'], $id]);

    // cost отдельно ('d' при 2 параметрах не режется), datetime тоже отдельно
    q("UPDATE projects SET cost_total = ? WHERE id = ?", 'di', [(float)$c['cost'], $id]);
    q("UPDATE projects SET last_run_at = ? WHERE id = ?", 'si', [$last, $id]);
    $n++;
}
echo "recounted {$n} nodes\n";
