<?php
declare(strict_types=1);
/**
 * Общая логика ingest API: авторизация по ключу, разбор тела, запись метрик/событий.
 */
require_once __DIR__ . '/core.php';

function ingest_key(): string
{
    try {
        $row = one("SELECT v FROM settings WHERE k='ingest_key'");
        $k = (string)($row['v'] ?? '');
        if ($k !== '') {
            return $k;
        }
    } catch (Throwable $e) {
        // settings недоступны: это проблема сервера, а не «неверный ключ».
        // Молча отдавать 401 нельзя — клиент (сторож) решит, что сломан ключ.
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'tracker temporarily unavailable'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'ingest key not configured'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Ключ совпал? Без exit — для вызывающих, которые сами решают судьбу запроса. */
function ingest_key_ok(): bool
{
    // ключ принимаем из заголовка (с обрезкой пробелов), из Bearer или из тела —
    // агенты шлют по-разному, а отказ по ключу молча ломает их работу.
    $sent = trim((string)($_SERVER['HTTP_X_INGEST_KEY'] ?? ''));
    if ($sent === '') {
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($auth, 'bearer ') === 0) {
            $sent = trim(substr($auth, 7));
        }
    }
    if ($sent === '') {
        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $sent = trim((string)($body['ingest_key'] ?? $body['key'] ?? ''));
        }
    }
    return hash_equals(ingest_key(), $sent);
}

/**
 * Доверенный источник: сам трекер и серверы агентов (IP из карточек проектов).
 * С них запросы принимаются и без ключа — агенту не нужно ничего настраивать.
 */
function ingest_trusted_ip(): bool
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return false;
    }
    $ips = ['127.0.0.1', '::1', trim((string)($_SERVER['SERVER_ADDR'] ?? ''))];
    try {
        foreach (all("SELECT DISTINCT server_ip FROM projects
                       WHERE server_ip IS NOT NULL AND server_ip <> ''") as $r) {
            $ips[] = trim((string)$r['server_ip']);
        }
    } catch (Throwable $e) {
        // projects недоступны — остаются только локальные адреса
    }
    return in_array($ip, array_values(array_unique(array_filter($ips))), true);
}

function ingest_check_key(): void
{
    // ключ принимаем из заголовка (с обрезкой пробелов), из Bearer или из тела —
    // агенты шлют по-разному, а отказ по ключу молча ломает их работу.
    $sent = trim((string)($_SERVER['HTTP_X_INGEST_KEY'] ?? ''));
    if ($sent === '') {
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($auth, 'bearer ') === 0) {
            $sent = trim(substr($auth, 7));
        }
    }
    if ($sent === '') {
        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $sent = trim((string)($body['ingest_key'] ?? $body['key'] ?? ''));
        }
    }
    if (!hash_equals(ingest_key(), $sent)) {
        // лог неудачи: длина присланного ключа и источник — чтобы понять, что прислал агент
        @file_put_contents('/root/system/var/ingest_auth.log', sprintf(
            "%s [ingest] bad key: len=%d ip=%s uri=%s\n",
            date('Y-m-d H:i:s'), strlen($sent),
            (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
            (string)($_SERVER['REQUEST_URI'] ?? '-')), FILE_APPEND);
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'bad ingest key'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function ingest_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        $j = $_POST;
    }
    return $j;
}

function ingest_json(array $out, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Записать запуск агента: agent_runs + пересчёт агрегатов узла + событие в лог */
function ingest_run(array $b): array
{
    $agent = trim((string)($b['agent_code'] ?? ''));
    if ($agent === '') {
        ingest_json(['ok' => false, 'error' => 'agent_code required'], 400);
    }
    $proj = null;
    if (!empty($b['project_ref'])) {
        $proj = find_node_by_ref((string)$b['project_ref']);
    }
    $projectId = $proj ? (int)$proj['id'] : null;

    $tokIn   = (int)($b['tok_in'] ?? 0);
    $tokCach = (int)($b['tok_cache'] ?? 0);
    $tokOut  = (int)($b['tok_out'] ?? 0);
    $cost    = (float)($b['cost_usd'] ?? 0);
    $dur     = (int)($b['duration_ms'] ?? 0);
    $model   = trim((string)($b['model'] ?? '')) ?: null;
    $server  = trim((string)($b['server'] ?? '')) ?: null;
    $jobId   = trim((string)($b['job_id'] ?? '')) ?: null;
    $errTxt  = trim((string)($b['error'] ?? '')) ?: null;

    // ts: epoch или ISO-строка; по умолчанию — сейчас
    $ts = trim((string)($b['ts'] ?? ''));
    if ($ts === '') {
        $tsSql = date('Y-m-d H:i:s');
    } else {
        if (is_numeric($ts)) {
            $tsSql = date('Y-m-d H:i:s', (int)$ts);
        } else {
            $t = strtotime($ts);
            if ($t === false) {
                $tsSql = date('Y-m-d H:i:s');
            } else {
                $tsSql = date('Y-m-d H:i:s', $t);
            }
        }
    }

    q("INSERT INTO agent_runs (project_id, agent_code, server, model,
         tok_in, tok_cache, tok_out, cost_usd, duration_ms, job_id, error, ts)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      'isssiiidisss', [$projectId, $agent, $server, $model,
                       $tokIn, $tokCach, $tokOut, $cost, $dur, $jobId, $errTxt, $tsSql]);
    $runId = (int)db()->insert_id;

    if ($projectId) {
        q("UPDATE projects SET
             tok_in = tok_in + ?, tok_cache = tok_cache + ?, tok_out = tok_out + ?,
             cost_total = cost_total + ?, runs_count = runs_count + 1,
             duration_ms = duration_ms + ?, last_run_at = GREATEST(COALESCE(last_run_at, '1970-01-01'), ?),
             model = COALESCE(?, model), server_ip = COALESCE(NULLIF(?, ''), server_ip)
           WHERE id = ?",
          'iiidisssi', [$tokIn, $tokCach, $tokOut, $cost, $dur, $tsSql, $model, $server, $projectId]);

        q("INSERT INTO events (project_id, kind, level, title, text, server)
           VALUES (?, 'metric', ?, ?, ?, ?)",
          'issss', [$projectId,
                     $errTxt ? 'error' : 'ok',
                     $agent . ' · ' . ($model ?: 'модель не указана'),
                     sprintf('in=%d cache=%d out=%d · $%s · %d ms',
                             $tokIn, $tokCach, $tokOut, number_format($cost, 4), $dur),
                     $server]);
    }
    return ['ok' => true, 'run_id' => $runId, 'project_id' => $projectId];
}

/** Время из payload: epoch или ISO; по умолчанию — сейчас */
function ingest_ts(array $b): string
{
    $ts = trim((string)($b['ts'] ?? ''));
    if ($ts === '') {
        return date('Y-m-d H:i:s');
    }
    if (is_numeric($ts)) {
        return date('Y-m-d H:i:s', (int)$ts);
    }
    $t = strtotime($ts);
    return $t === false ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $t);
}

/** Проект направления: по ref, по ID чата или по коду агента */
function ingest_project(array $b): ?array
{
    if (!empty($b['project_ref'])) {
        $p = find_node_by_ref((string)$b['project_ref']);
        if ($p) {
            return $p;
        }
    }
    if (isset($b['chat_id']) && (int)$b['chat_id'] !== 0) {
        $p = one("SELECT * FROM projects WHERE agent_chat_id=? AND kind='project' LIMIT 1",
                  'i', [(int)$b['chat_id']]);
        if ($p) {
            return $p;
        }
    }
    if (!empty($b['agent_code'])) {
        $p = one("SELECT * FROM projects WHERE agent_code=? AND kind='project' LIMIT 1",
                  's', [(string)$b['agent_code']]);
        if ($p) {
            return $p;
        }
    }
    return null;
}

/** Текущая задача направления: активная, иначе последняя выданная */
function ingest_active_task(int $projectId): ?array
{
    // задача может лежать в подпроекте — он часть направления
    $ids = direction_scope_ids($projectId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $t = one("SELECT * FROM projects WHERE parent_id IN ($ph) AND kind='task' AND status='active'
              ORDER BY id DESC LIMIT 1", $types, $ids);
    if ($t) {
        return $t;
    }
    return one("SELECT * FROM projects WHERE parent_id IN ($ph) AND kind='task' AND status IN ('planned','active')
                 ORDER BY prio, pos, id LIMIT 1", $types, $ids);
}

/**
 * Учёт вызовов моделей.
 * Одна запись — один отчёт агента: либо конкретный вызов (call_no=1),
 * либо агрегат за ход (call_no = число вызовов за ход).
 */
function ingest_call(array $b): array
{
    $agent = trim((string)($b['agent_code'] ?? ''));
    if ($agent === '') {
        $agent = 'unknown';
    }
    $proj = ingest_project($b);
    $projectId = $proj ? (int)$proj['id'] : null;

    $taskId = null;
    if (!empty($b['task_id'])) {
        $t = node((int)$b['task_id']);
        if ($t && (int)$t['parent_id'] === $projectId) {
            $taskId = (int)$t['id'];
        }
    }
    if ($taskId === null && $projectId) {
        $t = ingest_active_task($projectId);
        if ($t) {
            $taskId = (int)$t['id'];
        }
    }

    $model  = trim((string)($b['model'] ?? '')) ?: null;
    $server = trim((string)($b['server'] ?? '')) ?: null;
    $turnId = trim((string)($b['turn_id'] ?? '')) ?: null;
    $callNo = max(0, (int)($b['call_no'] ?? $b['calls'] ?? 1));
    $tokIn   = (int)($b['tok_in'] ?? 0);
    $tokCach = (int)($b['tok_cache'] ?? 0);
    $tokCachW = (int)($b['tok_cache_write'] ?? 0);
    $tokOut  = (int)($b['tok_out'] ?? 0);
    $tokTh   = (int)($b['tok_think'] ?? 0);
    $cost    = (float)($b['cost_usd'] ?? 0);
    $dur     = (int)($b['duration_ms'] ?? 0);
    $errTxt  = trim((string)($b['error'] ?? '')) ?: null;
    $tsSql   = ingest_ts($b);

    // идемпотентно: хук может штамповать ход несколько раз (кумулятивно) —
    // повторный штамп ОБНОВЛЯЕТ строку хода, а не создаёт дубль
    q("INSERT INTO llm_calls (project_id, task_id, agent_code, server, model, turn_id, call_no,
                              ts, tok_in, tok_cache, tok_cache_write, tok_out, tok_think, cost_usd, duration_ms, error)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
         ts=VALUES(ts), tok_in=VALUES(tok_in), tok_cache=VALUES(tok_cache),
         tok_cache_write=VALUES(tok_cache_write), tok_out=VALUES(tok_out),
         tok_think=VALUES(tok_think), cost_usd=VALUES(cost_usd),
         duration_ms=VALUES(duration_ms), error=VALUES(error)",
      'iissssisiiiiidis',
      [$projectId, $taskId, $agent, $server, $model, $turnId, $callNo,
       $tsSql, $tokIn, $tokCach, $tokCachW, $tokOut, $tokTh, $cost, $dur, $errTxt]);
    $id = $turnId !== null ? (int)(one("SELECT id FROM llm_calls WHERE turn_id=? AND call_no=? LIMIT 1", 'si', [$turnId, $callNo])['id'] ?? 0) : (int)db()->insert_id;

    if ($projectId) {
        q("UPDATE projects SET
             tok_in = tok_in + ?, tok_cache = tok_cache + ?, tok_out = tok_out + ?,
             cost_total = cost_total + ?, calls_count = calls_count + ?,
             duration_ms = duration_ms + ?,
             last_run_at = GREATEST(COALESCE(last_run_at, '1970-01-01'), ?),
             model = COALESCE(?, model), server_ip = COALESCE(NULLIF(?, ''), server_ip)
           WHERE id = ?",
          'iiidiisssi',
          [$tokIn, $tokCach, $tokOut, $cost, $callNo, $dur, $tsSql, $model, $server, $projectId]);
    }
    if ($taskId) {
        q("UPDATE projects SET
             tok_in = tok_in + ?, tok_cache = tok_cache + ?, tok_out = tok_out + ?,
             cost_total = cost_total + ?, calls_count = calls_count + ?,
             duration_ms = duration_ms + ?
           WHERE id = ?",
          'iiidiii', [$tokIn, $tokCach, $tokOut, $cost, $callNo, $dur, $taskId]);
    }
    return ['ok' => true, 'call_id' => $id, 'project_id' => $projectId, 'task_id' => $taskId];
}

/** Ход агента: агрегат в agent_runs + запись в llm_calls + событие в лог */
function ingest_turn(array $b): array
{
    $res = ingest_call($b);
    $calls = max(1, (int)($b['calls'] ?? $b['call_no'] ?? 1));

    $proj = ingest_project($b);
    $projectId = $proj ? (int)$proj['id'] : null;
    $taskId = $res['task_id'] ?? null;
    $agent = trim((string)($b['agent_code'] ?? '')) ?: 'unknown';
    $model = trim((string)($b['model'] ?? '')) ?: null;
    $server = trim((string)($b['server'] ?? '')) ?: null;
    $errTxt = trim((string)($b['error'] ?? '')) ?: null;
    $tsSql = ingest_ts($b);
    $jobId = trim((string)($b['turn_id'] ?? '')) ?: null;

    // идемпотентно: один ход = одна строка; повторный штамп (промежуточный/после
    // рестарта) обновляет метрики той же строки — дублей на ганте больше нет
    q("INSERT INTO agent_runs (project_id, task_id, agent_code, server, model,
         tok_in, tok_cache, tok_out, cost_usd, duration_ms, job_id, error, ts)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
         ts=VALUES(ts), tok_in=VALUES(tok_in), tok_cache=VALUES(tok_cache),
         tok_out=VALUES(tok_out), cost_usd=VALUES(cost_usd),
         duration_ms=VALUES(duration_ms), error=VALUES(error)",
      'iisssiiidisss',
      [$projectId, $taskId, $agent, $server, $model,
       (int)($b['tok_in'] ?? 0), (int)($b['tok_cache'] ?? 0), (int)($b['tok_out'] ?? 0),
       (float)($b['cost_usd'] ?? 0), (int)($b['duration_ms'] ?? 0), $jobId, $errTxt, $tsSql]);
    $runId = $jobId !== null ? (int)(one("SELECT id FROM agent_runs WHERE job_id=? LIMIT 1", 's', [$jobId])['id'] ?? 0) : (int)db()->insert_id;

    if ($projectId) {
        q("INSERT INTO events (project_id, kind, level, title, text, server)
           VALUES (?, 'metric', ?, ?, ?, ?)",
          'issss', [$projectId,
                    $errTxt ? 'error' : 'ok',
                    ($model ?: 'модель не указана') . ' · ' . $calls . ' вызовов'
                      . ($taskId ? ' · задача [' . $taskId . ']' : ''),
                    sprintf('in=%d cache=%d out=%d think=%d · $%s · %d ms',
                            (int)($b['tok_in'] ?? 0), (int)($b['tok_cache'] ?? 0),
                            (int)($b['tok_out'] ?? 0), (int)($b['tok_think'] ?? 0),
                            number_format((float)($b['cost_usd'] ?? 0), 4),
                            (int)($b['duration_ms'] ?? 0)),
                    $server]);
    }
    return ['ok' => true, 'run_id' => $runId, 'call_id' => $res['call_id'] ?? null,
            'project_id' => $projectId, 'task_id' => $taskId];
}

/** Записать событие в лог узла */
function ingest_event(array $b): array
{
    $ref = trim((string)($b['project_ref'] ?? ''));
    $proj = $ref !== '' ? find_node_by_ref($ref) : null;
    if (!$proj) {
        // направление можно передать ID чата — тогда событие ляжет в нужный проект
        $proj = ingest_project($b);
    }
    if (!$proj) {
        ingest_json(['ok' => false, 'error' => 'project not found: ' . $ref], 404);
    }
    $kind  = trim((string)($b['kind'] ?? 'agent')) ?: 'agent';
    $level = trim((string)($b['level'] ?? 'info')) ?: 'info';
    if (!in_array($kind, ['agent','chat','system','metric','file','note'], true)) $kind = 'agent';
    if (!in_array($level, ['info','ok','warn','error'], true)) $level = 'info';

    $msgId = isset($b['chat_msg_id']) && (int)$b['chat_msg_id'] > 0 ? (int)$b['chat_msg_id'] : null;

    // привязка к задаче: сообщение относится к задаче, которая сейчас в работе
    $taskId = null;
    if (!empty($b['task_id'])) {
        $tid = (int)$b['task_id'];
        $t = node($tid);
        if ($t) {
            $parentId = (int)$t['parent_id'];
            if (in_array($parentId, direction_scope_ids((int)$proj['id']), true)) {
                $taskId = $tid;
            }
        }
    }

    if ($msgId !== null) {
        $dup = one("SELECT id, text FROM events WHERE project_id = ? AND chat_msg_id = ? LIMIT 1",
                   'ii', [(int)$proj['id'], $msgId]);
        if ($dup) {
            // сообщение могло быть отредактировано — обновляем текст, а не плодим новую запись
            $newText = trim((string)($b['text'] ?? ''));
            $meta = isset($b['meta']) && is_array($b['meta']) ? $b['meta'] : [];
            q("UPDATE events SET text=?, level=?, meta=?, task_id=COALESCE(?, task_id) WHERE id=?",
              'sssii', [$newText ?: null, $level, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                        $taskId, (int)$dup['id']]);
            return ['ok' => true, 'event_id' => (int)$dup['id'], 'project_id' => (int)$proj['id'], 'updated' => true];
        }
    }

    q("INSERT INTO events (project_id, task_id, kind, level, title, text, server, chat_msg_id)
       VALUES (?,?,?,?,?,?,?,?)",
      'iisssssi', [(int)$proj['id'], $taskId, $kind, $level,
                    trim((string)($b['title'] ?? '')) ?: null,
                    trim((string)($b['text'] ?? '')) ?: null,
                    trim((string)($b['server'] ?? '')) ?: null,
                    $msgId]);
    $eventId = (int)db()->insert_id;

    // сигнал "задача выполнена" — закрываем текущую задачу и активируем следующую
    if ($kind === 'system' && strtolower(trim((string)($b['title'] ?? ''))) === 'task-done') {
        $done = complete_active_task((int)$proj['id']);
        if ($done['closed']) {
            q("UPDATE events SET text = CONCAT(COALESCE(text,''), '\n— задача отмечена выполненной, следующая активна: ', ?)
               WHERE id=?", 'si', [(string)$done['next_title'], $eventId]);
        }
        return ['ok' => true, 'event_id' => $eventId, 'project_id' => (int)$proj['id'],
                'closed' => $done['closed'], 'next' => $done['next']];
    }

    // протокол агента: TASK|<id задачи>|<СТАТУС> -> ставим отметку в трекере
    if ($kind === 'system' && strtolower(trim((string)($b['title'] ?? ''))) === 'watchdog-task') {
        // Сторож — источник правды по статусам: пишем его статус задачи как есть.
        $meta   = isset($b['meta']) && is_array($b['meta']) ? $b['meta'] : [];
        $taskId = (int)($meta['task_id'] ?? 0);
        $status = strtoupper(trim((string)($meta['status'] ?? '')));
        $t = $taskId ? node($taskId) : null;
        if (!$t) {
            return ['ok' => true, 'event_id' => $eventId, 'project_id' => (int)$proj['id'],
                    'note' => 'задача ' . $taskId . ' в трекере не найдена'];
        }
        if ($status === 'DONE') {
            q("UPDATE projects SET status='done', closed_at=NOW() WHERE id=?", 'i', [$taskId]);
        } elseif ($status === 'ACTIVE' || $status === 'IN_PROGRESS' || $status === 'WORK') {
            q("UPDATE projects SET status='active', closed_at=NULL WHERE id=?", 'i', [$taskId]);
            // активная задача в направлении одна — как и у сторожа
            q("UPDATE projects SET status='planned', closed_at=NULL
               WHERE parent_id=? AND kind='task' AND id<>? AND status<>'done'",
              'ii', [(int)$t['parent_id'], $taskId]);
        }
        return ['ok' => true, 'event_id' => $eventId, 'project_id' => (int)$proj['id'],
                'task_id' => $taskId, 'status' => $status];
    }

    if ($kind === 'system' && strtolower(trim((string)($b['title'] ?? ''))) === 'task-status') {
        $meta = isset($b['meta']) && is_array($b['meta']) ? $b['meta'] : [];
        $taskId = (int)($meta['task_id'] ?? 0);
        $status = (string)($meta['status'] ?? '');
        $res = $taskId ? set_task_status($taskId, $status, true) : ['ok' => false, 'error' => 'no task_id'];
        return ['ok' => true, 'event_id' => $eventId, 'project_id' => (int)$proj['id'],
                'task' => $res];
    }

    return ['ok' => true, 'event_id' => $eventId, 'project_id' => (int)$proj['id']];
}

/**
 * Создать задачу из сообщения пользователя в чате.
 * type=chat {project_ref|chat_id, text, title?, kind?, priority?}
 * Создаёт задачу в направлении с текстом сообщения.
 */
/**
 * Создать задачу из сообщения пользователя в чате.
 * type=chat {project_ref|chat_id, text, title?, kind?, priority?}
 * Создаёт задачу в направлении с текстом сообщения.
 */
function ingest_chat_message(array $b): array
{
    require_once __DIR__ . '/dispatch.php';
    require_once __DIR__ . '/core.php';
    $ref = trim((string)($b['project_ref'] ?? ''));
    $proj = $ref !== '' ? find_node_by_ref($ref) : null;
    if (!$proj) {
        $proj = ingest_project($b);
    }
    if (!$proj) {
        ingest_json(['ok' => false, 'error' => 'project not found: ' . $ref], 404);
    }

    $text = trim((string)($b['text'] ?? ''));
    if ($text === '') {
        ingest_json(['ok' => false, 'error' => 'text is required'], 400);
    }

    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') {
        // авто-заголовок из первых 80 символов текста
        $title = mb_substr($text, 0, 80) . (mb_strlen($text) > 80 ? '…' : '');
    }

    $prio = (int)($b['priority'] ?? 2);

    $scope = direction_scope_ids((int)$proj['id']);
    $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")", str_repeat('i', count($scope)), $scope) ?? 0) + 1;
    $nextId = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);

    $cfg = dispatch_settings((int)$proj['id']);
    $gapMin = dispatch_gap_minutes((int)$proj['id'], $cfg);
    $ready = dispatch_slot(dispatch_now()->modify('+' . $gapMin . ' minutes'), $cfg, (int)$proj['id']);

    // Используем прямой запрос с экранированием вместо prepared statement
    // чтобы избежать проблем с bind_param и NULL значениями
    $db = db();
    $titleEsc = $db->real_escape_string($title);
    $textEsc = $db->real_escape_string($text);
    $criteriaEsc = $db->real_escape_string('source: chat\n' . $text);
    $agentCodeEsc = $db->real_escape_string((string)($proj['agent_code'] ?? ''));
    $serverIpEsc = $db->real_escape_string((string)($proj['server_ip'] ?? ''));
    $readyEsc = $db->real_escape_string($ready);

    $sql = "INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                     task_desc, agent_code, server_ip, ready_at, auto_chain, auto_step,
                     plan_id, goal, deadline, stage_goal)
           VALUES ($nextId, " . (int)$proj['id'] . ", 'task', '$titleEsc', '', 'planned', 2, $pos,
                   '$criteriaEsc', NULL, '$agentCodeEsc', '$serverIpEsc', '$readyEsc', '', 0, NULL, '', '', '')";

    if (!$db->query($sql)) {
        ingest_json(['ok' => false, 'error' => 'DB error: ' . $db->error], 500);
    }

    $taskId = (int)$db->insert_id;

    // Событие в лог
    $eventSql = "INSERT INTO events (project_id, task_id, kind, level, title, text, server)
                 VALUES (" . (int)$proj['id'] . ", $taskId, 'chat', 'info', '$titleEsc', '$textEsc', '" . $db->real_escape_string((string)($proj['server_ip'] ?? '')) . "')";
    $db->query($eventSql);

    return ['ok' => true, 'task_id' => $taskId, 'project_id' => (int)$proj['id'], 'title' => $title];
}
