<?php
declare(strict_types=1);
/**
 * Экспорт / импорт плана и выгрузка лога.
 *
 * План — это дерево узлов трекера (направления, подпроекты, задачи).
 * Идентификаторы сохраняются: для агента id задачи — это контракт.
 */

/** План целиком в массив (порядок дерева). */
function plan_export_nodes(): array
{
    $rows = all("SELECT id, parent_id, kind, title, icon, status, prio, pos, summary, criteria,
                        agent_code, agent_name, agent_chat_id, server_ip, model
                 FROM projects ORDER BY id");
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[(int)$r['parent_id']][] = $r;
    }
    $out = [];
    $walk = function (?int $parent) use (&$walk, $byParent, &$out): void {
        foreach ($byParent[$parent ?? 0] ?? [] as $r) {
            $out[] = [
                'id'            => (int)$r['id'],
                'parent_id'     => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                'kind'          => (string)$r['kind'],
                'title'         => (string)$r['title'],
                'icon'          => (string)$r['icon'],
                'status'        => (string)$r['status'],
                'prio'          => (int)$r['prio'],
                'pos'           => (int)$r['pos'],
                'summary'       => $r['summary'] !== null ? (string)$r['summary'] : null,
                'criteria'      => $r['criteria'] !== null ? (string)$r['criteria'] : null,
                'agent_code'    => $r['agent_code'] !== null ? (string)$r['agent_code'] : null,
                'agent_name'    => $r['agent_name'] !== null ? (string)$r['agent_name'] : null,
                'agent_chat_id' => $r['agent_chat_id'] !== null ? (int)$r['agent_chat_id'] : null,
                'server_ip'     => $r['server_ip'] !== null ? (string)$r['server_ip'] : null,
                'model'         => $r['model'] !== null ? (string)$r['model'] : null,
            ];
            $walk((int)$r['id']);
        }
    };
    $walk(null);
    return $out;
}

/** Отдать файл в браузер. */
function send_download(string $filename, string $body, string $mime = 'application/json'): void
{
    header('Content-Type: ' . $mime . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/** Экспорт плана: JSON-файл. */
function plan_export(): void
{
    $payload = [
        'exported_at' => date('c'),
        'tracker'     => 'http://' . ($_SERVER['HTTP_HOST'] ?? '5.144.179.170:8080'),
        'nodes'       => plan_export_nodes(),
    ];
    send_download('plan-' . date('Ymdd-His') . '.json',
                  json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * Выгрузка лога: переписка по направлениям, по задачам.
 * $projectId — ограничить направлением (проект + его подпроекты);
 * $taskId    — ограничить одной задачей.
 */
function log_export(int $projectId = 0, int $taskId = 0): void
{
    $where = [];
    $args  = [];
    $types = '';
    if ($taskId > 0) {
        $where[] = 'e.task_id = ?';
        $args[]  = $taskId;
        $types  .= 'i';
    } elseif ($projectId > 0) {
        $ids = direction_scope_ids($projectId);
        if (!$ids) {
            $ids = [$projectId];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "e.project_id IN ($ph)";
        $args    = $ids;
        $types   = str_repeat('i', count($ids));
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $rows = all("SELECT e.id, e.ts, e.kind, e.level, e.title, e.text, e.server,
                        p.title AS project, p.id AS project_id,
                        t.id AS task_id, t.title AS task_title
                 FROM events e
                 LEFT JOIN projects p ON p.id = e.project_id
                 LEFT JOIN projects t ON t.id = e.task_id
                 $w ORDER BY e.id", $types, $args);
    $lines = [];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "%s | %-8s | %-10s | %s%s | %s",
            dt((string)$r['ts'], 'd.m.Y H:i:s'),
            (string)$r['project'],
            (string)$r['kind'],
            $r['task_id'] ? '[' . (int)$r['task_id'] . '] ' . (string)$r['task_title'] : '—',
            $r['title'] ? ' ' . (string)$r['title'] : '',
            str_replace("\n", "\n    ", (string)($r['text'] ?? ''))
        );
    }
    $scope = '';
    if ($taskId > 0) {
        $scope = '-task' . $taskId;
    } elseif ($projectId > 0) {
        $p = node($projectId);
        $scope = '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', mb_strtolower((string)($p['title'] ?? $projectId)));
    }
    $head = "Лог трекера SYSTEM · выгружено " . date('d.m.Y H:i:s') . " · записей: " . count($rows) . "\n"
          . str_repeat('=', 100) . "\n";
    send_download('log' . $scope . '-' . date('Ymdd-His') . '.txt',
                  $head . implode("\n", $lines) . "\n", 'text/plain');
}

/**
 * Импорт плана: создать/обновить узлы по данным файла.
 * Возвращает ['created' => N, 'updated' => N, 'errors' => [...]].
 */
function plan_import(array $nodes): array
{
    $res = ['created' => 0, 'updated' => 0, 'errors' => []];
    $maxId = (int)(scalar("SELECT COALESCE(MAX(id),0) FROM projects") ?? 0);
    $byId = [];
    foreach (all("SELECT id, kind FROM projects") as $r) {
        $byId[(int)$r['id']] = (string)$r['kind'];
    }

    // сначала родители (по порядку входа), потом остальные
    $ordered = [];
    $pending = $nodes;
    for ($pass = 0; $pass < 5 && $pending; $pass++) {
        foreach ($pending as $i => $n) {
            $pid = isset($n['parent_id']) && $n['parent_id'] !== null ? (int)$n['parent_id'] : null;
            if ($pid !== null && !isset($byId[$pid]) && !in_array($pid, array_column($ordered, 'id'), true)) {
                continue; // родитель ещё не создан — ждём следующий проход
            }
            $ordered[] = $n;
            unset($pending[$i]);
        }
    }
    foreach ($pending as $n) {
        $res['errors'][] = 'не удалось создать родителя для «' . (string)($n['title'] ?? '?') . '»';
    }

    foreach ($ordered as $n) {
        $id = (int)($n['id'] ?? 0);
        if ($id <= 0) {
            $id = ++$maxId;
        }
        $kind = (string)($n['kind'] ?? 'task');
        if (!in_array($kind, ['project', 'subproject', 'task'], true)) {
            $kind = 'task';
        }
        $title = trim((string)($n['title'] ?? ''));
        if ($title === '') {
            $res['errors'][] = 'пропущен узел без названия (id ' . $id . ')';
            continue;
        }
        $parentId = isset($n['parent_id']) && $n['parent_id'] !== null ? (int)$n['parent_id'] : null;
        if ($parentId !== null && !isset($byId[$parentId])) {
            $res['errors'][] = 'нет родителя ' . $parentId . ' для «' . $title . '»';
            continue;
        }
        $status = (string)($n['status'] ?? 'planned');
        if (!in_array($status, ['planned', 'active', 'done', 'blocked'], true)) {
            $status = 'planned';
        }
        $fields = [
            $parentId,
            $kind,
            $title,
            (string)($n['icon'] ?? ''),
            $status,
            (int)($n['prio'] ?? 2),
            (int)($n['pos'] ?? 0),
            $n['summary'] !== null ? (string)$n['summary'] : null,
            $n['criteria'] !== null ? (string)$n['criteria'] : null,
            $n['agent_code'] !== null ? (string)$n['agent_code'] : null,
            $n['agent_name'] !== null ? (string)$n['agent_name'] : null,
            isset($n['agent_chat_id']) && $n['agent_chat_id'] !== null ? (int)$n['agent_chat_id'] : null,
            $n['server_ip'] !== null ? (string)$n['server_ip'] : null,
            $n['model'] !== null ? (string)$n['model'] : null,
        ];
        if (isset($byId[$id])) {
            q("UPDATE projects SET parent_id=?, kind=?, title=?, icon=?, status=?, prio=?, pos=?,
                   summary=?, criteria=?, agent_code=?, agent_name=?, agent_chat_id=?, server_ip=?, model=?
                 WHERE id=?",
              'issssiissssissi', array_merge($fields, [$id]));
            $res['updated']++;
        } else {
            q("INSERT INTO projects (parent_id, kind, title, icon, status, prio, pos, summary, criteria,
                                    agent_code, agent_name, agent_chat_id, server_ip, model, id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              'issssiissssissi', array_merge($fields, [$id]));
            $byId[$id] = $kind;
            $res['created']++;
        }
    }
    return $res;
}
