<?php
declare(strict_types=1);
/**
 * Поставить агенту следующую задачу по порядку.
 *
 * POST /api/queue_next.php   {project_ref, agent_code}
 *
 * Находит первую незакрытую задачу проекта, собирает сообщение для агента
 * (задача + короткий контекст последнего отчёта + формат отчёта) и кладёт
 * её в очередь диспетчера. Идемпотентно: если для проекта уже есть неотправленная
 * задача — вернёт её и ничего нового не создаст.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$b = ingest_body();
$ref = trim((string)($b['project_ref'] ?? ''));
$agentCode = trim((string)($b['agent_code'] ?? ''));

$proj = find_node_by_ref($ref);
if (!$proj) {
    ingest_json(['ok' => false, 'error' => 'project_ref not found: ' . $ref], 404);
}
$pid = (int)$proj['id'];

// уже есть неотправленная задача — не плодим
$pending = one("SELECT id, payload FROM dispatch_queue WHERE project_id = ? AND status = 'new' LIMIT 1",
                'i', [$pid]);
if ($pending) {
    ingest_json(['ok' => true, 'queued' => false, 'id' => (int)$pending['id'],
                 'payload' => $pending['payload']]);
}

// какую задачу ставим: указанную в запросе или первую незакрытую по порядку
$wantId = (int)($b['task_id'] ?? 0);
if ($wantId > 0) {
    $next = one("SELECT id, title, criteria FROM projects WHERE id=? AND kind='task' AND status<>'done' LIMIT 1",
                 'i', [$wantId]);
} else {
    $next = one("SELECT id, title, criteria FROM projects
                 WHERE parent_id = ? AND kind='task' AND status <> 'done'
                 ORDER BY prio, pos, id LIMIT 1", 'i', [$pid]);
}
if (!$next) {
    ingest_json(['ok' => true, 'queued' => false, 'error' => 'нет незакрытых задач']);
}
$taskId = (int)$next['id'];
$title = (string)$next['title'];

// контекст: последнее сообщение агента с протокольной строкой — там тело отчёта
$lastRep = one("SELECT text FROM events WHERE project_id = ? AND kind='agent'
                AND text LIKE 'TASK|%' ORDER BY id DESC LIMIT 1", 'i', [$pid]);
if (!$lastRep) {
    $lastRep = one("SELECT text FROM events WHERE project_id = ? AND kind='agent'
                    AND text IS NOT NULL AND text <> '' ORDER BY id DESC LIMIT 1", 'i', [$pid]);
}
$ctx = '';
if ($lastRep && trim((string)$lastRep['text']) !== '') {
    $ctx = "\n\nКонтекст — твой последний отчёт (с причиной, если задача не закрыта):\n"
         . mb_substr(trim((string)$lastRep['text']), 0, 1200);
}

$bot = trim((string)($b['agent_bot'] ?? ''));
if ($bot === '') {
    $bot = (string)($proj['agent_name'] ?? '');
}
$mention = $bot && str_starts_with($bot, '@') ? $bot : '';

$payload = trim((string)($b['payload'] ?? ''));
if ($payload === '') {
    $payload = ($mention ? $mention . ' ' : '')
        . "Новая задача.\n\n"
        . "Задача {$taskId} — {$title}\n"
        . (trim((string)($next['criteria'] ?? '')) !== ''
            ? "\nБриф задачи:\n" . trim((string)$next['criteria']) . "\n"
            : '')
        . $ctx . "\n\n"
        . "Формат отчёта тот же, отдельной строкой в конце:\n"
        . "TASK|{$taskId}|ACTIVE … TASK|{$taskId}|DONE\n"
        . "В отчёте указывай id трекера: {$taskId}. Свою внутреннюю нумерацию (17.1, 17.3 и т.п.) "
        . "не используй — трекер сверяет номер с активной задачей, иначе отметка не применится.\n\n"
        . "Правило прежнее: работаем по одной задаче, следующую ждёшь от меня.";
}

q("INSERT INTO dispatch_queue (project_id, agent_code, payload, status) VALUES (?,?,?,'new')",
  'iss', [$pid, $agentCode ?: (string)($proj['agent_code'] ?? ''), $payload]);

ingest_json(['ok' => true, 'queued' => true, 'id' => (int)db()->insert_id,
             'task_id' => $taskId, 'title' => $title]);
