<?php
declare(strict_types=1);
/**
 * GET /api/task_state.php?task_id=15
 * Статус задачи — мост спрашивает, не закрыли ли её вручную.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$id = req_int('task_id');
$t = $id > 0 ? node($id) : null;
if (!$t) {
    ingest_json(['ok' => false, 'error' => 'task not found']);
}
ingest_json([
    'ok' => true,
    'task_id' => (int)$t['id'],
    'title' => (string)$t['title'],
    'status' => (string)$t['status'],
    'pos' => (int)$t['pos'],
    'parent_id' => (int)($t['parent_id'] ?: 0),
]);
