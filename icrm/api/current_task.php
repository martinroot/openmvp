<?php
declare(strict_types=1);
/**
 * GET /api/current_task.php?project_ref=WINTASK
 *
 * Возвращает активную (первую не-готово) задачу проекта — мост пишет сообщения
 * агента именно в неё, чтобы лог вёл к текущей задаче, а не к проекту.
 */
require __DIR__ . '/../lib/ingest.php';   // тянет core.php (а тот chat.php) — второй раз не подключаем

ingest_check_key();

$ref = trim((string)($_GET['project_ref'] ?? ''));
$proj = find_node_by_ref($ref);
if (!$proj) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'project_ref not found: ' . $ref], JSON_UNESCAPED_UNICODE);
    exit;
}

$tasks = all("SELECT id, title, pos, status FROM projects
              WHERE parent_id = ? AND kind='task' ORDER BY prio, pos, id", 'i', [(int)$proj['id']]);
$active = null;
foreach ($tasks as $t) {
    if ($t['status'] !== 'done') {
        $active = $t;
        break;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'project_id' => (int)$proj['id'],
    'project_ref' => (string)$proj['title'],
    'task_id' => $active ? (int)$active['id'] : 0,
    'task_ref' => $active ? (string)$active['title'] : '',
], JSON_UNESCAPED_UNICODE);
