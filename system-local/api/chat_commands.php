<?php
declare(strict_types=1);
/**
 * Команды в чат агента (например /model ... --provider ...).
 * Трекер создаёт команду, сторож забирает и отправляет в чат.
 *
 * GET  /api/chat_commands.php?project_ref=WINTASK      → неотправленные команды
 * POST /api/chat_commands.php  {"type":"sent","id":12}  → отметить отправленной
 */
require_once __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$b       = ingest_body();
$type    = (string)($b['type'] ?? '');
$project = null;

if ($type === 'sent') {
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) {
        ingest_json(['ok' => false, 'error' => 'id required'], 400);
    }
    q("UPDATE chat_commands SET sent_at=? WHERE id=?", 'si', [date('Y-m-d H:i:s'), $id]);
    ingest_json(['ok' => true, 'id' => $id]);
}

$ref = trim((string)($_GET['project_ref'] ?? ''));
if ($ref === '') {
    ingest_json(['ok' => false, 'error' => 'project_ref required'], 400);
}
$project = find_node_by_ref($ref);
if (!$project) {
    ingest_json(['ok' => false, 'error' => 'проект не найден: ' . $ref], 404);
}
$pid = (int)$project['id'];

$items = all("SELECT id, cmd, note, created_at FROM chat_commands
              WHERE project_id=? AND sent_at IS NULL ORDER BY id LIMIT 20", 'i', [$pid]) ?: [];

ingest_json(['ok' => true, 'project_id' => $pid, 'project_ref' => $ref, 'items' => $items]);
