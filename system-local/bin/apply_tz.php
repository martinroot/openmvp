<?php
declare(strict_types=1);
/**
 * Заносит ТЗ оператора в проект WINTASK: текст в карточку проекта,
 * 5 задач по порядку, запись в очередь диспетчера (status=new, НЕ отправлена).
 * Запуск: php bin/apply_tz.php
 */
require __DIR__ . '/../lib/core.php';

$project = one("SELECT * FROM projects WHERE title='WINTASK' LIMIT 1");
if (!$project) {
    fwrite(STDERR, "нет проекта WINTASK\n");
    exit(1);
}
$pid = (int)$project['id'];

$tz = <<<TXT
Текущее состояние (на 21.10.2026):
— 3 профиля Dolphin (Яндекс) — по ним сегодня выполнено 3 заказа на Яндекс.Картах.
— 6 новых купленных аккаунтов, 6 прокси, 6 профилей Dolphin.

ТЗ:
1) Войти в 6 профилей, подтвердить что прокси ок (проверка через IP), записать в базу.
2) Сделать отчёт по заявкам сегодня и закрытым задачам.
3) Приступить к дальнейшему мониторингу.
4) Постоянно итеративно улучшать механизм ответа, внимательно читать ТЗ.
5) Составить список сервисов 2,3 которые купим и добавим в текущие профили
   (Отзвоик, Авито, Гугл-аккаунт и т.д.) — найти сервисы, найти магазины, скинуть ссылки на оплату.
   Дальше: фарм, прикрепление, расширение списка фильтров и начало ответов на биржах.

Правило общения: агенту писать только когда он явно идёт не туда или выполнил предыдущую задачу.
На каждое его сообщение не отвечать.
TXT;

// текст ТЗ — в карточку проекта
q("UPDATE projects SET summary=? WHERE id=?", 'si', [$tz, $pid]);

// 5 задач по порядку
$tasks = [
    "1) Войти в 6 профилей Dolphin: проверить прокси по IP, войти, записать в базу",
    "2) Отчёт по заявкам за сегодня и закрытым задачам",
    "3) Дальнейший мониторинг",
    "4) Итеративно улучшать механизм ответа, внимательно читать ТЗ",
    "5) Список сервисов 2,3 к покупке (Отзвоик, Авито, Гугл-аккаунт) + ссылки на оплату; фарм, прикрепление, расширение фильтров, начало ответов на биржах",
];

// чистим прежние «черновые» задачи этого же набора (кроме уже закрытой «Открытия на Яндекс.Картах»)
$old = all("SELECT id, title FROM projects WHERE parent_id=? AND kind='task' AND pos>1", 'i', [$pid]);
foreach ($old as $o) {
    if (str_starts_with((string)$o['title'], '1)') || str_starts_with((string)$o['title'], '2)')
        || str_starts_with((string)$o['title'], '3)') || str_starts_with((string)$o['title'], '4)')
        || str_starts_with((string)$o['title'], '5)')) {
        q("DELETE FROM projects WHERE id=?", 'i', [(int)$o['id']]);
    }
}

$pos = 2;
foreach ($tasks as $t) {
    $exists = one("SELECT id FROM projects WHERE parent_id=? AND title=? LIMIT 1", 'is', [$pid, $t]);
    if ($exists) {
        q("UPDATE projects SET pos=?, status=IF(status='done','done','active') WHERE id=?",
          'ii', [$pos, (int)$exists['id']]);
    } else {
        q("INSERT INTO projects (parent_id, kind, title, pos, status, agent_code, agent_chat_id, server_ip)
           VALUES (?,'task',?,?,'active','win-195',-1004434028499,'195.63.128.99')",
          'isi', [$pid, $t, $pos]);
    }
    $pos++;
}

// в очередь диспетчера — НЕ отправлено, ждёт команды оператора
$payload = "ТЗ (21.10):\n" . $tz;
$q = one("SELECT id FROM dispatch_queue WHERE project_id=? AND status='new' LIMIT 1", 'i', [$pid]);
if (!$q) {
    q("INSERT INTO dispatch_queue (project_id, agent_code, payload, status) VALUES (?,'win-195',?,'new')",
      'is', [$pid, $payload]);
}

$rows = all("SELECT id, pos, title, status FROM projects WHERE parent_id=? AND kind='task' ORDER BY pos, id", 'i', [$pid]);
foreach ($rows as $r) {
    printf("  pos=%d [%s] %s\n", (int)$r['pos'], $r['status'], $r['title']);
}
echo "ТЗ записан, задача в очереди диспетчера (не отправлена)\n";
