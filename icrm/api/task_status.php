<?php
declare(strict_types=1);
/**
 * Отметка задачи С МОЕЙ стороны — без участия агента.
 *
 * POST /api/task_status.php
 *   task_id=15&status=DONE&note=текст
 *   status: DONE | ACTIVE | BLOCKED
 *
 * Агент может присылать отчёт сам (TASK|id|СТАТУС), но если он этого не делает —
 * отметку ставит управляющий (я), разобрав его сообщения глазами.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$b = ingest_body();
$taskId = (int)($b['task_id'] ?? 0);
$status = strtoupper(trim((string)($b['status'] ?? '')));
$note = trim((string)($b['note'] ?? ''));

if ($taskId <= 0) {
    ingest_json(['ok' => false, 'error' => 'task_id required']);
}
$task = node($taskId);
if (!$task) {
    ingest_json(['ok' => false, 'error' => 'task not found: ' . $taskId], 404);
}

$res = set_task_status($taskId, $status);

// событие в лог задачи — чтобы было видно, кто и почему поставил отметку
$projId = (int)($task['parent_id'] ?: $taskId);
// отметку пишем в лог ЗАДАЧИ (task_id), иначе в карточке задачи не видно,
// чем и почему закрыли — а именно это и смотрит оператор
q("INSERT INTO events (project_id, task_id, kind, level, title, text, server)
   VALUES (?, ?, 'system', ?, 'task-status', ?, ?)",
  'iisss', [$projId, $taskId, $status === 'DONE' ? 'ok' : 'info',
             $note !== '' ? $note : $status, 'управляющий']);

ingest_json(['ok' => true, 'task_id' => $taskId, 'status' => $status, 'result' => $res]);
