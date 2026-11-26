<?php
declare(strict_types=1);
/**
 * Очередь диспетчера для Telegram-моста.
 *
 * GET  /api/pending.php?chat_id=<id>  — неотправленные задачи для чата
 * POST /api/sent.php?id=<id>          — отметить отправленной (или с ошибкой)
 *
 * Авторизация: X-Ingest-Key.
 */
require_once __DIR__ . '/ingest.php';

ingest_check_key();

$path = basename($_SERVER['SCRIPT_NAME'] ?? '');

if ($path === 'pending.php') {
    $chatId = req_int('chat_id');
    $items = all("SELECT d.id, d.agent_code, d.payload, d.created_at, d.project_id,
                         p.title AS ptitle, p.agent_chat_id
                  FROM dispatch_queue d LEFT JOIN projects p ON p.id = d.project_id
                  WHERE d.status = 'new' ORDER BY d.id ASC LIMIT 20");
    $out = [];
    foreach ($items as $it) {
        // задача уходит в чат, привязанный к её узлу
        $targetChat = (int)($it['agent_chat_id'] ?? 0);
        if ($chatId !== 0 && $targetChat !== $chatId) {
            continue;
        }
        $out[] = [
            'id' => (int)$it['id'],
            'agent_code' => $it['agent_code'],
            'payload' => $it['payload'],
            'project' => $it['ptitle'],
            'chat_id' => $targetChat,
            'created_at' => $it['created_at'],
        ];
    }
    ingest_json(['ok' => true, 'items' => $out]);
}

if ($path === 'sent.php') {
    $id = req_int('id');
    $b = ingest_body();
    $err = trim((string)($b['error'] ?? ''));
    q("UPDATE dispatch_queue SET status = ?, err = ?, sent_at = NOW() WHERE id = ?",
      'ssi', [$err !== '' ? 'failed' : 'sent', $err !== '' ? $err : null, $id]);
    ingest_json(['ok' => true, 'id' => $id]);
}

ingest_json(['ok' => false, 'error' => 'unknown endpoint'], 404);
