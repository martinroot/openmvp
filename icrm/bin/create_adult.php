<?php
declare(strict_types=1);
/**
 * Создание проекта ADULT в трекере (реальный чат и агент, без выдумок).
 * Второе направление: сторож ведёт оба чата, проект определяется по ID чата.
 * Запуск: php bin/create_adult.php
 */
require __DIR__ . '/../lib/core.php';

$AGENT_CODE = 'edge-147';
$AGENT_BOT  = '@mainmaclaude_bot';
$AGENT_CHAT = -1004469933182;
$SERVER_IP  = '145.223.69.147';

$n = one("SELECT id FROM projects WHERE title = ? LIMIT 1", 's', ['ADULT']);
if ($n) {
    q("UPDATE projects SET agent_code=?, agent_name=?, agent_chat_id=?, server_ip=?,
                             status='active', prio=2 WHERE id=?",
      'ssisi', [$AGENT_CODE, $AGENT_BOT, $AGENT_CHAT, $SERVER_IP, (int)$n['id']]);
    echo "проект ADULT обновлён (id " . (int)$n['id'] . ")\n";
    exit(0);
}

q("INSERT INTO projects (parent_id, kind, title, icon, agent_code, agent_name, agent_chat_id,
                          server_ip, status, priority, summary, pos)
   VALUES (NULL, 'project', 'ADULT', '🔞', ?, ?, ?, ?, 'active', 2, ?, 1)",
  'ssiss', [$AGENT_CODE, $AGENT_BOT, $AGENT_CHAT, $SERVER_IP,
            "Второе направление. Агент: $AGENT_BOT, чат $AGENT_CHAT, сервер $SERVER_IP.\n"
            . "Задачи добавляет оператор через дерево (кнопка «+» у направления)."]);
echo "проект ADULT создан (id " . (int)db()->insert_id . ")\n";
