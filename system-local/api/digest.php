<?php
declare(strict_types=1);
/**
 * Дайджест по агентам — что изменилось, чтобы решать, а не читать весь поток.
 *
 * GET /api/digest.php?project_ref=WINTASK
 *   по каждому агенту: активная задача, последние сообщения (обрезанные),
 *   последний отчёт агента, сколько минут он молчит, есть ли свежие ошибки.
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();

$ref = trim((string)($_GET['project_ref'] ?? ''));
$proj = find_node_by_ref($ref);
if (!$proj) {
    ingest_json(['ok' => false, 'error' => 'project_ref not found: ' . $ref], 404);
}
$pid = (int)$proj['id'];

// агенты проекта: узлы с каналом
$agents = all("SELECT id, title, agent_code, agent_chat_id, agent_name
               FROM projects WHERE kind='project' AND (parent_id = ? OR id = ?)
               ORDER BY id", 'ii', [$pid, $pid]);

$out = [];
foreach ($agents as $a) {
    $chatId = (int)($a['agent_chat_id'] ?? 0);

    // активная задача
    $active = one("SELECT id, title, status FROM projects
                   WHERE parent_id = ? AND kind='task' AND status <> 'done'
                   ORDER BY prio, pos, id LIMIT 1", 'i', [(int)$a['id']]);

    // последние сообщения агента из лога
    $msgs = $chatId
        ? all("SELECT id, ts, kind, text FROM events
               WHERE server = ? AND kind IN ('agent','chat')
               ORDER BY id DESC LIMIT 6", 's', [(string)$a['agent_code']])
        : [];

    $lastAgent = null;
    foreach ($msgs as $m) {
        if ($m['kind'] === 'agent') {
            $lastAgent = $m;
            break;
        }
    }

    // последний отчёт по протоколу (по активной задаче — если есть)
    $report = null;
    if ($active) {
        $report = one("SELECT id, ts, text, meta FROM events
                       WHERE project_id = ? AND title='task-status' AND meta LIKE ?
                       ORDER BY id DESC LIMIT 1", 'is', [(int)$a['id'], '%' . (int)$active['id'] . '%']);
    }
    if (!$report) {
        $report = one("SELECT id, ts, text, meta FROM events
                       WHERE title='task-status' ORDER BY id DESC LIMIT 1");
    }

    // зависшая задача: активна, но последний статус не DONE
    $stalled = false;
    $stallMin = 0;
    if ($active && $report) {
        $m = json_decode((string)$report['meta'], true);
        $st = strtoupper((string)($m['status'] ?? ''));
        if ($st && $st !== 'DONE') {
            $stallMin = (int)round((time() - strtotime((string)$report['ts'])) / 60);
            $stalled = $stallMin >= 10;
        }
    }

    $silentMin = 0;
    if ($lastAgent) {
        $silentMin = (int)round((time() - strtotime((string)$lastAgent['ts'])) / 60);
    }

    $out[] = [
        'agent' => $a['title'],
        'agent_code' => $a['agent_code'],
        'chat_id' => $chatId,
        'active_task' => $active ? ['id' => (int)$active['id'], 'title' => $active['title']] : null,
        'stalled' => $stalled,
        'stalled_min' => $stallMin,
        'silent_min' => $silentMin,
        'last_report' => $report ? ['ts' => $report['ts'],
                                    'status' => (string)(json_decode((string)$report['meta'], true)['status'] ?? ''),
                                    'task_id' => (int)(json_decode((string)$report['meta'], true)['task_id'] ?? 0),
                                    'text' => mb_substr((string)$report['text'], 0, 1500)] : null,
        'messages' => array_map(fn($m) => [
            'id' => (int)$m['id'],
            'ts' => $m['ts'],
            'kind' => $m['kind'],
            'text' => mb_substr((string)$m['text'], 0, 400),
        ], $msgs),
    ];
}

ingest_json(['ok' => true, 'project_ref' => $proj['title'], 'agents' => $out]);
