<?php
declare(strict_types=1);
/**
 * Список задач направления — для статичного сторожа чата.
 *
 * GET /api/tasks.php?project_ref=WINTASK
 *   → {"ok":true,"project":{...},"tasks":[{"id":22,"title":"...","criteria":"...",
 *      "status":"planned","prio":1,"pos":3}]}
 *
 * В список входят задачи самого направления и его подпроектов.
 *
 * Сторож берёт отсюда следующую задачу и сюда же пишет статус.
 */
require __DIR__ . '/../lib/ingest.php';
require __DIR__ . '/../lib/dispatch.php';

ingest_check_key();

$ref = trim((string)($_GET['project_ref'] ?? ''));
$proj = find_node_by_ref($ref);
if (!$proj) {
    ingest_json(['ok' => false, 'error' => 'project_ref not found: ' . $ref], 404);
}
$pid = (int)$proj['id'];

// задачи направления + задачи его подпроектов (подпроект — часть направления)
$ids = direction_scope_ids($pid);

$ph = implode(',', array_fill(0, count($ids), '?'));
$tasks = all("SELECT id, parent_id, title, criteria, task_desc, status, prio, pos, agent_code,
                     ready_at, auto_chain, auto_step, goal, deadline, plan_id
              FROM projects WHERE parent_id IN ($ph) AND kind='task'
              ORDER BY prio, pos, id", str_repeat('i', count($ids)), $ids);

// трекер решает, что можно выдавать сейчас: окно выдачи (МСК) + готовность + голова цепочки
$elig = dispatch_eligible_map($tasks, $pid);
foreach ($tasks as &$tk) {
    $tk['issuable'] = !empty($elig[(int)$tk['id']]) ? 1 : 0;
    // «срок» в том же виде, в каком его ждёт сторож (due), + цель этапа
    $tk['due']       = (string)($tk['deadline'] ?? '');
    $tk['stage_goal'] = (string)($tk['goal'] ?? '');
}
unset($tk);

ingest_json([
    'ok' => true,
    'project' => [
        'id'            => $pid,
        'ref'           => $ref,
        'title'         => (string)$proj['title'],
        'agent_code'    => $proj['agent_code'] ?: null,
        'agent_chat_id' => $proj['agent_chat_id'] !== null ? (int)$proj['agent_chat_id'] : null,
        'agent_name'    => $proj['agent_name'] ?: null,
    ],
    'tasks' => $tasks,
]);
