<?php
declare(strict_types=1);
/** Крон-тик оркестратора: каждый включённый проект — не чаще своего интервала. */
require_once dirname(__DIR__) . '/lib/orch.php';

$rows = all("SELECT project_id FROM orch WHERE enabled=1", '', []);
foreach ($rows as $r) {
    try { orch_tick((int)$r['project_id']); } catch (Throwable $e) { /* следующий тик повторит */ }
}
echo "orch tick done\n";
