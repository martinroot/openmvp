<?php
declare(strict_types=1);
/**
 * Периодические задачи — для статичного сторожа чата.
 *
 * GET  /api/recurring.php?project_ref=WINTASK
 *      → {"ok":true,"items":[{"id":3,"every_min":60,"title":"...","due":1,"last_sent":"..."}]}
 * POST /api/recurring.php  {"type":"sent","id":3}   → отметить, что задача отдана
 */
require __DIR__ . '/../lib/ingest.php';
require __DIR__ . '/../lib/recurring.php';

ingest_check_key();

$raw   = file_get_contents('php://input') ?: '';
$body  = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$type  = (string)($body['type'] ?? $_GET['type'] ?? '');

if ($type === 'sent') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        ingest_json(['ok' => false, 'error' => 'id required'], 400);
    }
    recurring_mark_sent($id);
    ingest_json(['ok' => true]);
}

$ref = trim((string)($_GET['project_ref'] ?? ''));
$proj = find_node_by_ref($ref);
if (!$proj) {
    ingest_json(['ok' => false, 'error' => 'project_ref not found: ' . $ref], 404);
}
$pid  = (int)$proj['id'];
$ids  = direction_scope_ids($pid);
$items = recurring_for_scope($ids);
foreach ($items as &$it) {
    $it['id']       = (int)$it['id'];
    $it['every_min'] = (int)$it['every_min'];
    $it['due']      = (int)$it['due'];
}
unset($it);

ingest_json([
    'ok'      => true,
    'project' => [
        'id'            => $pid,
        'ref'           => $ref,
        'title'         => (string)$proj['title'],
        'agent_chat_id' => $proj['agent_chat_id'] !== null ? (int)$proj['agent_chat_id'] : null,
    ],
    'items'   => $items,
]);
