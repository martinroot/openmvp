<?php
declare(strict_types=1);
/**
 * Создание проекта WINTASK в трекере (данные от оператора, без выдумок).
 * Запуск: php bin/create_wintask.php
 */
require __DIR__ . '/../lib/core.php';

function upsert(string $title, array $data): int
{
    $n = one("SELECT id FROM projects WHERE title = ? LIMIT 1", 's', [$title]);
    if ($n) {
        $cols = array_keys($data);
        $set = implode(',', array_map(fn($c) => "$c=?", $cols));
        q("UPDATE projects SET $set WHERE id=?", str_repeat('s', count($cols) + 1),
            array_merge(array_values($data), [(int)$n['id']]));
        return (int)$n['id'];
    }
    $data['title'] = $title;
    $cols = array_keys($data);
    $sql = "INSERT INTO projects (" . implode(',', $cols) . ") VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")";
    q($sql, str_repeat('s', count($cols)), array_values($data));
    return (int)db()->insert_id;
}

$AGENT_CODE = 'win-195';
$AGENT_BOT  = '@win1956312899_bot';
$AGENT_CHAT = -1004434028499;
$SERVER_IP  = '195.63.128.99';
$MODEL      = 'minimax-m3.1-flash:free';

$summary = "Выполняем заказы на бирже.\n\n"
    . "Агент: $AGENT_BOT, чат $AGENT_CHAT, сервер $SERVER_IP, модель $MODEL.\n\n"
    . "Текущее состояние:\n"
    . "- 3 Dolphin-аккаунта (Яндекс) — для открытий на Яндекс.Картах; за сегодня выполнено 3 заказа.\n"
    . "- Куплено ещё 6 аккаунтов: агент учится и прогревает их, вводит через прокси.\n"
    . "- Фокус: задачи отладки. Постоянно просить агента гонять цикл.\n\n"
    . "Правило общения: писать агенту только когда он явно идёт не туда или выполнил "
    . "предыдущую задачу. На каждое его сообщение отвечать не нужно.";

$p = upsert('WINTASK', [
    'parent_id' => null,
    'kind' => 'project',
    'icon' => '🪟',
    'agent_code' => $AGENT_CODE,
    'agent_name' => $AGENT_BOT,
    'agent_chat_id' => $AGENT_CHAT,
    'server_ip' => $SERVER_IP,
    'model' => $MODEL,
    'status' => 'active',
    'priority' => 1,
    'summary' => $summary,
    'pos' => 0,
]);

$subs = [];

$subs['Dolphin-аккаунты (Яндекс)'] = upsert('Dolphin-аккаунты (Яндекс)', [
    'parent_id' => $p, 'kind' => 'subproject', 'icon' => '🐬',
    'agent_code' => $AGENT_CODE, 'agent_name' => $AGENT_BOT, 'agent_chat_id' => $AGENT_CHAT,
    'server_ip' => $SERVER_IP, 'model' => $MODEL,
    'status' => 'active', 'pos' => 0,
    'summary' => '3 аккаунта Яндекс для открытий на Яндекс.Картах. За сегодня — 3 заказа.',
]);

upsert('Открытия на Яндекс.Картах', [
    'parent_id' => $subs['Dolphin-аккаунты (Яндекс)'], 'kind' => 'task', 'icon' => '🗺',
    'agent_code' => $AGENT_CODE, 'agent_name' => $AGENT_BOT, 'agent_chat_id' => $AGENT_CHAT,
    'server_ip' => $SERVER_IP, 'model' => $MODEL,
    'status' => 'active', 'pos' => 0,
    'summary' => '3 заказа выполнено за сегодня. Продолжать по отработанной схеме.',
]);

upsert('Прогрев 6 новых аккаунтов (ввод через прокси)', [
    'parent_id' => $subs['Dolphin-аккаунты (Яндекс)'], 'kind' => 'task', 'icon' => '♨️',
    'agent_code' => $AGENT_CODE, 'agent_name' => $AGENT_BOT, 'agent_chat_id' => $AGENT_CHAT,
    'server_ip' => $SERVER_IP, 'model' => $MODEL,
    'status' => 'active', 'pos' => 1,
    'summary' => 'Агент учится и прогревает 6 купленных аккаунтов, ввод идёт через прокси.',
]);

$subs['Отладка'] = upsert('Отладка', [
    'parent_id' => $p, 'kind' => 'subproject', 'icon' => '🐛',
    'agent_code' => $AGENT_CODE, 'agent_name' => $AGENT_BOT, 'agent_chat_id' => $AGENT_CHAT,
    'server_ip' => $SERVER_IP, 'model' => $MODEL,
    'status' => 'active', 'pos' => 1,
    'summary' => 'Фокус на задачах отладки. Постоянно просить агента гонять цикл.',
]);

upsert('Гонять цикл до стабильности', [
    'parent_id' => $subs['Отладка'], 'kind' => 'task', 'icon' => '🔁',
    'agent_code' => $AGENT_CODE, 'agent_name' => $AGENT_BOT, 'agent_chat_id' => $AGENT_CHAT,
    'server_ip' => $SERVER_IP, 'model' => $MODEL,
    'status' => 'active', 'pos' => 0,
    'summary' => 'Просить агента гонять цикл, пока не перестанет уходить не туда.',
]);

echo "WINTASK project_id={$p}\n";
foreach (all("SELECT id,title,kind,status FROM projects WHERE parent_id = ? OR id = ? ORDER BY kind, pos",
    'ii', [$p, $p]) as $n) {
    echo "  [{$n['id']}] {$n['title']} ({$n['kind']}, {$n['status']})\n";
}
