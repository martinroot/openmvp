<?php
declare(strict_types=1);
/**
 * Направления (проекты) с каналами — из них сторож чатов берёт работу автономно.
 *
 * GET /api/directions.php
 *   → {"ok":true,"directions":[{"project_ref":"WINTASK","title":"WINTASK","icon":"🪟",
 *        "chat_id":-1004434028499,"agent_name":"@win1956312899_bot","agent_code":"win-195",
 *        "tasks":10,"open":1}]}
 *
 * Скрипт не знает про проекты заранее: он берёт отсюда ID чата и @агента
 * и сам решает, за каким чатом следить. Добавили проект с ID чата — он уже работает.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$rows = all("SELECT id, title, icon, agent_chat_id, agent_name, agent_code, server_ip
             FROM projects WHERE kind='project' AND agent_chat_id IS NOT NULL
             ORDER BY id");

$out = [];
foreach ($rows as $r) {
    $chat = (int)$r['agent_chat_id'];
    if (!$chat) {
        continue;
    }
    $tasks = one("SELECT COUNT(*) c FROM projects WHERE parent_id=? AND kind='task'",
                 'i', [(int)$r['id']]);
    $open = one("SELECT COUNT(*) c FROM projects WHERE parent_id=? AND kind='task' AND status<>'done'",
                'i', [(int)$r['id']]);
    $out[] = [
        'project_id'  => (int)$r['id'],
        'project_ref' => (string)$r['title'],
        'title'       => (string)$r['title'],
        'icon'        => (string)$r['icon'],
        'chat_id'     => $chat,
        'agent_name'  => $r['agent_name'] ?: null,
        'agent_code'  => $r['agent_code'] ?: null,
        'server_ip'   => $r['server_ip'] ?: null,
        'tasks'       => (int)($tasks['c'] ?? 0),
        'open'        => (int)($open['c'] ?? 0),
    ];
}

ingest_json(['ok' => true, 'directions' => $out]);
