<?php
declare(strict_types=1);
/**
 * Узел «Сервер» в направлении ADULT (текст от оператора).
 * Запуск: php bin/adult_server_node.php
 */
require __DIR__ . '/../lib/core.php';

$TEXT = 'ADULT — SERVER 145.223.69.147 (Чат-агент -1004469933182)';

$proj = one("SELECT * FROM projects WHERE title=? LIMIT 1", 's', ['ADULT']);
if (!$proj) {
    echo "нет проекта ADULT\n";
    exit(1);
}
$pid = (int)$proj['id'];

// карточка направления показывает ту же строку
q("UPDATE projects SET summary=? WHERE id=?", 'si', [$TEXT, $pid]);

// первый узел направления — подпроект «Сервер» (не задача: его нельзя закрыть)
$ICON = '🖥';
$node = one("SELECT id FROM projects WHERE parent_id=? AND title=? LIMIT 1", 'is', [$pid, 'Сервер']);
if ($node) {
    q("UPDATE projects SET kind='subproject', icon=?, summary=?, status='active', criteria=NULL WHERE id=?",
      'ssi', [$ICON, $TEXT, (int)$node['id']]);
    echo "подпроект «Сервер» обновлён (id " . (int)$node['id'] . ")\n";
} else {
    $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$pid]) ?? 0) + 1;
    $id  = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
    q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, summary,
                            agent_code, server_ip)
       VALUES (?, ?, 'subproject', 'Сервер', ?, 'active', 5, ?, ?, ?, ?)",
      'iisisss', [$id, $pid, $ICON, $pos, $TEXT,
                   $proj['agent_code'] ?: null, $proj['server_ip'] ?: null]);
    echo "подпроект «Сервер» создан (id $id)\n";
}
echo "направление: " . $pid . " (ADULT)\n";
