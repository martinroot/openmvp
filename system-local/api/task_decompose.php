<?php
declare(strict_types=1);
/**
 * Декомпозиция зависшей задачи на ходу.
 *
 * POST /api/task_decompose.php
 *   {project_ref, task_id, pieces: ["первый кусок", "второй", ...]}
 *
 * Задачу-родителя переименовываем в первый кусок (id сохраняется — вся история лога
 * остаётся при ней), остальные куски создаём следующими по порядку и сдвигаем
 * последующие задачи. Дальше обычная маршрутизация сама отправит первый кусок агенту.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$b = ingest_body();
$ref = trim((string)($b['project_ref'] ?? ''));
$taskId = (int)($b['task_id'] ?? 0);
$pieces = $b['pieces'] ?? [];
if (!is_array($pieces)) {
    $pieces = [];
}
$pieces = array_values(array_filter(array_map('trim', array_map('strval', $pieces)), fn($s) => $s !== ''));

$proj = find_node_by_ref($ref);
if (!$proj) {
    ingest_json(['ok' => false, 'error' => 'project_ref not found: ' . $ref], 404);
}
if ($taskId <= 0 || count($pieces) < 2) {
    ingest_json(['ok' => false, 'error' => 'нужны task_id и минимум 2 куска']);
}
$parent = node($taskId);
if (!$parent || (int)$parent['parent_id'] !== (int)$proj['id']) {
    ingest_json(['ok' => false, 'error' => 'task not found в этом проекте'], 404);
}

$pos = (int)$parent['pos'];

// 1) родитель становится первым куском
q("UPDATE projects SET title=?, status='active', closed_at=NULL WHERE id=?", 'si', [$pieces[0], $taskId]);

// 2) сдвигаем последующие задачи вправо
$after = all("SELECT id FROM projects WHERE parent_id=? AND pos > ? ORDER BY pos DESC", 'ii', [(int)$proj['id'], $pos]);
foreach ($after as $t) {
    q("UPDATE projects SET pos=pos+? WHERE id=?", 'ii', [count($pieces) - 1, (int)$t['id']]);
}

// 3) создаём остальные куски
$newIds = [$taskId];
$newPos = $pos + 1;
foreach (array_slice($pieces, 1) as $title) {
    q("INSERT INTO projects (parent_id, kind, title, pos, status, agent_code, agent_chat_id, server_ip)
       VALUES (?,'task',?,?,'active',?,?,?)",
      'isisis', [(int)$proj['id'], $title, $newPos,
                   (string)($parent['agent_code'] ?? ''), (int)($parent['agent_chat_id'] ?? 0),
                   (string)($parent['server_ip'] ?? '')]);
    $newIds[] = (int)db()->insert_id;
    $newPos++;
}

// 4) событие в лог — что и почему разложили
q("INSERT INTO events (project_id, kind, level, title, text, server)
   VALUES (?, 'system', 'info', 'decomposed', ?, ?)",
  'iss', [(int)$proj['id'],
          'Задача ' . $taskId . ' разложена на ' . count($pieces) . ' части: ' . implode(' | ', $pieces),
          (string)($parent['agent_code'] ?? '')]);

ingest_json(['ok' => true, 'task_ids' => $newIds, 'count' => count($pieces)]);
