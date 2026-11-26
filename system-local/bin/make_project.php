<?php
declare(strict_types=1);
/**
 * Создание направления (проекта) с каналом — UTF-8 без плясок с шеллом.
 *
 * Запуск: php bin/make_project.php <title> <icon> <agent_chat_id> <@agent> [server_ip] [agent_code]
 */
require __DIR__ . '/../lib/core.php';

$title = $argv[1] ?? '';
$icon = $argv[2] ?? '';
$chat = (int)($argv[3] ?? 0);
$agent = $argv[4] ?? '';
$server = $argv[5] ?? '';
$code = $argv[6] ?? '';

if ($title === '' || $chat === 0 || $agent === '') {
    echo "usage: php bin/make_project.php <title> <icon> <chat_id> <@agent> [ip] [code]\n";
    exit(1);
}

$exists = one("SELECT id FROM projects WHERE title=? AND kind='project' LIMIT 1", 's', [$title]);
if ($exists) {
    q("UPDATE projects SET icon=?, agent_chat_id=?, agent_name=?, server_ip=?, agent_code=?,
                              status='active'
        WHERE id=?",
        'sisssi', [$icon, $chat, $agent, $server, $code, (int)$exists['id']]);
    echo "направление {$title} уже было — обновлено (id {$exists['id']})\n";
} else {
    $id = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
    q("INSERT INTO projects (id, parent_id, kind, title, icon, agent_code, agent_name,
                            agent_chat_id, server_ip, status, prio, pos, summary)
       VALUES (?, NULL, 'project', ?, ?, ?, ?, ?, ?, 'active', 2, 0, ?)",
        'issssiss',
        [$id, $title, $icon, $code, $agent, $chat, $server, "Направление {$title}. Канал: {$chat}."]);
    echo "направление {$title} создано (id {$id})\n";
}

$n = one("SELECT id,title,HEX(icon) hx,agent_chat_id,agent_name,server_ip FROM projects
          WHERE title=? AND kind='project' LIMIT 1", 's', [$title]);
echo "в трекере: id {$n['id']} | иконка hex {$n['hx']} | чат {$n['agent_chat_id']} | {$n['agent_name']} | сервер {$n['server_ip']}\n";
