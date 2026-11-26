<?php
declare(strict_types=1);
/**
 * Восстановление списка задач WINTASK (список был утрачен).
 * Названия берём из очереди отправок (dispatch_queue — там полные тексты задач),
 * критерии — из бэкапа плана сторожа (SQLite), статусы/приоритеты — из состояния
 * на момент последней сверки.
 *
 * Запуск: php bin/restore_tasks.php [путь к бэкапу плана .sqlite3]
 */
require __DIR__ . '/../lib/core.php';

$WD = $argv[1] ?? '/opt/wintask_watchdog/backup-20261022-070224.sqlite3';

// id => [статус, приоритет, pos]
$STATE = [
    '7'  => ['done',    5, 5],
    '15' => ['done',    5, 6],
    '16' => ['done',    5, 7],
    '17' => ['done',    5, 8],
    '18' => ['done',    1, 2],
    '19' => ['active',  1, 3],
    '20' => ['done',    5, 9],
    '22' => ['done',    1, 1],
    '23' => ['done',    5, 10],
    '24' => ['done',    2, 4],
];

// 1. названия из очереди отправок
$titles = [];
foreach (all("SELECT payload FROM dispatch_queue ORDER BY id") as $r) {
    $p = (string)$r['payload'];
    if (preg_match('/Задача\s+(\d+)\s*[—\-:]\s*([^\n]+)/u', $p, $m)) {
        $id = $m[1];
        $t  = trim($m[2]);
        if (!isset($titles[$id]) || strlen($t) > strlen($titles[$id])) {
            $titles[$id] = $t;
        }
    }
}
// заголовки из плана сторожа — они полнее
$criteria = [];
if (is_readable($WD)) {
    $cmd = '/home/main/tguser/venv/bin/python -c ' . escapeshellarg(
        'import sqlite3,json;c=sqlite3.connect(' . var_export($WD, true) . ');'
        . 'print(json.dumps([{"id":r[0],"title":r[1],"criteria":json.loads(r[2])} '
        . 'for r in c.execute("SELECT id,title,criteria FROM tasks")],ensure_ascii=False))');
    $out = shell_exec($cmd);
    $rows = json_decode((string)$out, true);
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $titles[(string)$r['id']] = (string)$r['title'];
            if (!empty($r['criteria'])) {
                $criteria[(string)$r['id']] = implode("\n", $r['criteria']);
            }
        }
    }
} else {
    echo "бэкап плана недоступен: $WD\n";
}

// названия, которых нет ни в очереди отправок, ни в плане сторожа
$OVERRIDE = [
    '7'  => 'Открытия на Яндекс.Картах',
    '20' => 'КУСОК B (id 20) — Сбор ситуации по конвейеру (СБОР ФАКТОВ, не починка)',
    '23' => 'КУСОК E (id 23) — Снять аварию нагрузки: по фактам из КУСКА B',
    '24' => 'КУСОК F (id 24) — ПРИМЕНИТЬ ЛИМИТЫ: kill монитора по возрасту > 6 ч в watchdog + лимит 4 монитора; профили <= 3, закрывать после задачи; 3 парсера, watch-chat только под заказы',
];
foreach ($OVERRIDE as $k => $v) {
    $titles[$k] = $v;
}

// 2. восстанавливаем
$parent = one("SELECT id FROM projects WHERE title=? LIMIT 1", 's', ['WINTASK']);
if (!$parent) {
    echo "нет проекта WINTASK\n";
    exit(1);
}
$pid = (int)$parent['id'];
$restored = 0;
foreach ($STATE as $id => [$status, $prio, $pos]) {
    $title = $titles[$id] ?? ('Задача ' . $id);
    $crit  = $criteria[$id] ?? null;
    q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                            agent_code, server_ip)
       VALUES (?, ?, 'task', ?, '', ?, ?, ?, ?, 'win-195', '195.63.128.99')
       ON DUPLICATE KEY UPDATE title=VALUES(title), status=VALUES(status), prio=VALUES(prio),
                               pos=VALUES(pos), criteria=VALUES(criteria)",
      'iissiis', [(int)$id, $pid, $title, $status, $prio, $pos, $crit]);
    $restored++;
    echo "задача [$id] восстановлена: " . mb_substr($title, 0, 70) . "\n";
}
echo "восстановлено: $restored\n";
