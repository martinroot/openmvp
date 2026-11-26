<?php
declare(strict_types=1);
/**
 * Постановка задачи агенту изнутри системы — например, после ошибки.
 *
 * Задача попадает в план (как обычная задача в дереве), дальше её забирает
 * сторож и отдаёт агенту. Агент видит только текст задачи.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/chat.php';

/**
 * Создать задачу в плане направления.
 * @return array{ok:bool,error?:string,code?:int,task_id?:int,dup?:bool,dry_run?:bool}
 */
function queue_task(array $b): array
{
    $ref  = trim((string)($b['project_ref'] ?? ''));
    $chat = isset($b['chat_id']) ? (int)$b['chat_id'] : 0;

    $proj = null;
    if ($ref !== '') {
        $proj = find_node_by_ref($ref);
    }
    if (!$proj && $chat !== 0) {
        $row = one("SELECT * FROM projects WHERE agent_chat_id=? AND kind='project' LIMIT 1", 'i', [$chat]);
        $proj = $row ?: null;
    }
    if (!$proj && !empty($b['project_id'])) {
        // прямое указание проекта/подпроекта (например из интерфейса трекера)
        $row = one("SELECT * FROM projects WHERE id=? AND kind IN ('project','subproject') LIMIT 1",
                   'i', [(int)$b['project_id']]);
        $proj = $row ?: null;
        if ($proj && $ref === '') {
            $ref = (string)$proj['title'];
        }
    }
    if (!$proj) {
        return ['ok' => false, 'error' => 'направление не найдено: укажи project_ref или chat_id', 'code' => 404];
    }
    $projId = (int)$proj['id'];

    $title = trim(preg_replace('/\s+/u', ' ', (string)($b['title'] ?? '')));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title обязателен', 'code' => 400];
    }
    if (mb_strlen($title) > 250) {
        $title = mb_substr($title, 0, 250);
    }

    $scope = direction_scope_ids($projId);
    $parentId = isset($b['parent_id']) ? (int)$b['parent_id'] : 0;
    if ($parentId > 0) {
        if (!in_array($parentId, $scope, true)) {
            return ['ok' => false, 'error' => 'parent_id не входит в это направление', 'code' => 400];
        }
    } else {
        $parentId = $projId;
    }

    // такая же незакрытая задача уже есть — не плодим
    $dup = one("SELECT id FROM projects WHERE parent_id=? AND kind='task' AND status<>'done' AND title=? LIMIT 1",
               'is', [$parentId, $title]);
    if ($dup) {
        return ['ok' => true, 'dup' => true, 'task_id' => (int)$dup['id'],
                'project_id' => $projId, 'project_ref' => $ref ?: (string)$proj['title'],
                'title' => $title];
    }

    $source = trim((string)($b['source'] ?? 'system')) ?: 'system';
    if (mb_strlen($source) > 40) {
        $source = mb_substr($source, 0, 40);
    }
    // защита от бесконечного потока: не больше 3 открытых задач от одного источника
    $sameSource = scalar("SELECT COUNT(*) FROM projects
                          WHERE parent_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")
                            AND kind='task' AND status<>'done' AND criteria LIKE ?",
                         str_repeat('i', count($scope)) . 's',
                         array_merge($scope, ['источник: ' . $source . '%']));
    if ((int)$sameSource >= 3) {
        return ['ok' => false, 'code' => 429,
                'error' => 'от источника "' . $source . '" уже 3 открытые задачи в направлении — не добавляю'];
    }

    $prio = (int)($b['prio'] ?? 2);
    if ($prio < 1 || $prio > 3) {
        $prio = 2;
    }
    $note = trim((string)($b['note'] ?? ''));
    $criteria = 'источник: ' . $source . ($note !== '' ? "\n" . $note : null);

    if (!empty($b['dry_run'])) {
        return ['ok' => true, 'dry_run' => true, 'task_id' => 0, 'dup' => false,
                'project_id' => $projId, 'project_ref' => $ref ?: (string)$proj['title'],
                'title' => $title, 'parent_id' => $parentId];
    }

    $pos    = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$parentId]) ?? 0) + 1;
    $id     = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
    $parent = node($parentId);
    q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                             agent_code, server_ip)
       VALUES (?, ?, 'task', ?, '', 'planned', ?, ?, ?, ?, ?)",
      'iisiisss',
      [$id, $parentId, $title, $prio, $pos, $criteria,
       (string)($parent['agent_code'] ?? ''),
       (string)($parent['server_ip'] ?? '')]);

    // событие в ленту: видно, откуда задача пришла
    q("INSERT INTO events (project_id, task_id, kind, level, title, text)
       VALUES (?,?,?,?,?,?)",
      'iissss',
      [$projId, $id, 'system', 'warn', 'задача от системы: ' . $source,
       $title . ($note !== '' ? "\n" . $note : '')]);

    return ['ok' => true, 'dup' => false, 'task_id' => $id, 'project_id' => $projId,
            'project_ref' => $ref ?: (string)$proj['title'], 'title' => $title,
            'parent_id' => $parentId];
}
