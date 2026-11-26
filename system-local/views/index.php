<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/dispatch.php';

$page = req_str('page', 'dashboard');
$act = req_str('act');
// Состояние раскрытия дерева (?open=27,28) — переживает добавление/правку/удаление.
$openRaw = preg_replace('/[^0-9,]/', '', (string)($_GET['open'] ?? $_POST['open'] ?? ''));
$openQ = $openRaw !== '' ? '&open=' . $openRaw : '';

/* ------------------------------------------------------------------ */
/*  actions                                                            */
/* ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($act) {
        /* --- создание/правка узла --- */
        case 'create':
        case 'update': {
            $id = req_int('id');
            $data = [
                'parent_id'   => req_int('parent_id') ?: null,
                'kind'        => req_str('kind', 'task'),
                'title'       => req_str('title'),
                'icon'        => req_str('icon', '📁') ?: '📁',
                'agent_code'  => req_str('agent_code') ?: null,
                'agent_name'  => req_str('agent_name') ?: null,
                'agent_chat_id' => req_int('agent_chat_id') ?: null,
                'server_ip'   => req_str('server_ip') ?: null,
                'model'       => req_str('model') ?: null,
                'status'      => req_str('status', 'planned'),
                'priority'    => req_int('priority', 0),
                'goal_amount' => req_str('goal_amount') !== '' ? (float)req_str('goal_amount') : null,
                'goal_date'   => req_str('goal_date') ?: null,
                'summary'     => req_str('summary') ?: null,
                'prio'        => req_int('prio', 2) ?: 2,
                'criteria'    => req_str('criteria') ?: null,
            ];
            if ($data['title'] === '') {
                flash('warning', 'Название обязательно');
                break;
            }
            // Проект и подпроект — не задачи: их нельзя закрыть статусом.
            if ($data['kind'] !== 'task') {
                $data['status'] = 'active';
            }
            $back = req_str('back', 'node');
            if ($act === 'create') {
                $cols = array_keys($data);
                $sql = "INSERT INTO projects (" . implode(',', $cols) . ") VALUES (" .
                    implode(',', array_fill(0, count($cols), '?')) . ")";
                q($sql, str_repeat('s', count($cols)), array_values($data));
                $newId = (int)db()->insert_id;
                flash('success', 'Узел создан');
                header($back === 'tree' ? ('Location: ?page=tree' . $openQ) : "Location: ?page=node&id=$newId");
                exit;
            }
            $cols = array_keys($data);
            $set = implode(',', array_map(fn($c) => "$c=?", $cols));
            q("UPDATE projects SET $set WHERE id=?", str_repeat('s', count($cols) + 1),
                array_merge(array_values($data), [$id]));
            flash('success', 'Сохранено');
            header($back === 'tree' ? ('Location: ?page=tree' . $openQ) : "Location: ?page=node&id=$id");
            exit;
        }

        /* --- удалить узел --- */
        case 'delete': {
            $id = req_int('id');
            q("DELETE FROM projects WHERE id=?", 'i', [$id]);
            flash('success', 'Узел удалён');
            header('Location: ?page=tree' . $openQ);
            exit;
        }

        /* --- добавить задачу в направление --- */
        case 'add_task': {
            $projectId = req_int('parent_id');
            $title     = trim(req_str('title'));
            $prio      = req_int('prio', 2) ?: 2;
            $raw       = req_str('criteria');
            if ($title === '') {
                flash('warning', 'Название обязательно');
                break;
            }
            $criteria = array_values(array_filter(array_map('trim',
                preg_split('/\R/', $raw) ?: [])));
            $parent = node($projectId);
            if (!$parent) {
                flash('danger', 'Направление не найдено');
                break;
            }
            $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?",
                                'i', [$projectId]) ?? 0) + 1;
            $id = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
            q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                                    agent_code, server_ip)
               VALUES (?, ?, 'task', ?, '', 'planned', ?, ?, ?, ?, ?)",
              'iisiisss', [$id, $projectId, $title, $prio ?: 2, $pos,
                            $criteria ? implode("\n", $criteria) : null,
                            $parent['agent_code'] ?: null, $parent['server_ip'] ?: null]);
            flash('success', 'Задача [' . $id . '] добавлена');
            header('Location: ?page=tree' . $openQ);
            exit;
        }

        /* --- создать направление (проект) в корне --- */
        case 'create_project': {
            $title = trim(req_str('title'));
            if ($title === '') {
                flash('warning', 'Название направления обязательно');
                break;
            }
            $chat   = req_int('agent_chat_id');
            $agent  = req_str('agent_name') ?: null;
            $code   = req_str('agent_code') ?: null;
            $ip     = req_str('server_ip') ?: null;
            $model  = req_str('model') ?: null;
            $icon   = req_str('icon', '📁') ?: '📁';
            $exists = one("SELECT id FROM projects WHERE title=? LIMIT 1", 's', [$title]);
            if ($exists) {
                flash('warning', 'Направление «' . $title . '» уже есть');
                break;
            }
            $id = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
            q("INSERT INTO projects (id, parent_id, kind, title, icon, agent_code, agent_name,
                                    agent_chat_id, server_ip, model, status, prio, pos, summary)
               VALUES (?, NULL, 'project', ?, ?, ?, ?, ?, ?, ?, 'active', 1, 0, ?)",
              'issssisss', [$id, $title, $icon, $code, $agent, $chat, $ip, $model,
                             'Канал: ' . $title . ($chat ? ' (чат ' . $chat . ')' : '')]);
            flash('success', 'Направление «' . $title . '» создано' . ($chat ? '' : ' — укажи ID чата, иначе сторож его не возьмёт'));
            header('Location: ?page=tree' . $openQ);
            exit;
        }

        /* --- создать подпроект внутри проекта --- */
        case 'create_subproject': {
            $parentId = req_int('parent_id');
            $title    = trim(req_str('title'));
            if ($title === '') {
                flash('warning', 'Название подпроекта обязательно');
                break;
            }
            $parent = node($parentId);
            if (!$parent) {
                flash('danger', 'Родитель не найден');
                break;
            }
            $icon = req_str('icon', '📁') ?: '📁';
            $pos  = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?",
                                 'i', [$parentId]) ?? 0) + 1;
            $id   = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
            q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, summary,
                                    agent_code, server_ip)
               VALUES (?, ?, 'subproject', ?, ?, 'active', 5, ?, ?, ?, ?)",
              'iississs', [$id, $parentId, $title, $icon, $pos,
                             req_str('summary') ?: null,
                             $parent['agent_code'] ?: null, $parent['server_ip'] ?: null]);
            flash('success', 'Подпроект «' . $title . '» создан');
            header('Location: ?page=node&id=' . (int)$parentId);
            exit;
        }

        /* --- удалить задачу --- */
        case 'del_task': {
            $id = req_int('id');
            $n  = node($id);
            if (!$n) {
                flash('danger', 'Задача не найдена');
                break;
            }
            q("DELETE FROM projects WHERE id=?", 'i', [$id]);
            flash('success', 'Задача [' . $id . '] удалена');
            header('Location: ?page=tree' . $openQ);
            exit;
        }

        /* --- событие в лог --- */
        case 'add_event': {
            $id = req_int('id');
            q("INSERT INTO events (project_id, kind, level, title, text, server)
               VALUES (?, 'note', ?, ?, ?, ?)",
              'issss', [$id, req_str('level', 'info'), req_str('title') ?: null,
                        req_str('text') ?: null, req_str('server') ?: null]);
            flash('success', 'Событие добавлено');
            header("Location: ?page=node&id=$id");
            exit;
        }

        /* --- цели --- */
        case 'add_goal':
        case 'upd_goal': {
            $scope = req_str('scope', 'global');
            $period = req_str('period_key') ?: ($scope === 'day' ? date('Y-m-d') : 'global');
            $title = req_str('title');
            $target = (float)req_str('target_usd', '0');
            $note = req_str('note') ?: null;
            if ($act === 'add_goal') {
                q("INSERT INTO goals (scope, period_key, title, target_usd, note)
                   VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),
                   target_usd=VALUES(target_usd), note=VALUES(note)",
                  'sssds', [$scope, $period, $title, $target, $note]);
                flash('success', 'Цель поставлена');
            } else {
                q("UPDATE goals SET scope=?, period_key=?, title=?, target_usd=?, note=? WHERE id=?",
                  'sssdsi', [$scope, $period, $title, $target, $note, req_int('gid')]);
                flash('success', 'Цель обновлена');
            }
            header('Location: ?page=finance');
            exit;
        }
        case 'del_goal': {
            q("DELETE FROM goals WHERE id=?", 'i', [req_int('gid')]);
            flash('success', 'Цель удалена');
            header('Location: ?page=finance');
            exit;
        }

        /* --- диспетчер --- */
        case 'create_dispatch': {
            $agent = req_str('agent_code');
            $payload = req_str('payload');
            $proj = req_int('project_id') ?: null;
            q("INSERT INTO dispatch_queue (project_id, agent_code, payload) VALUES (?,?,?)",
              'iss', [$proj, $agent, $payload]);
            flash('success', 'Задача в очереди');
            header('Location: ?page=dispatch');
            exit;
        }
        case 'send_dispatch': {
            $did = req_int('did');
            $row = one("SELECT * FROM dispatch_queue WHERE id=?", 'i', [$did]);
            if (!$row) {
                flash('danger', 'Запись не найдена');
            } else {
                $ref = 'system-' . $did;
                $ok = chat_post('task', $ref, (string)$row['payload']);
                if ($ok) {
                    q("UPDATE dispatch_queue SET status='sent', sent_at=NOW() WHERE id=?", 'i', [$did]);
                    flash('success', 'Задача отправлена в чат агентов');
                } else {
                    q("UPDATE dispatch_queue SET status='failed', err=? WHERE id=?", 'si', [$ok['error'] ?? 'ошибка', $did]);
                    flash('danger', 'Не отправилось: ' . ($ok['error'] ?? 'ошибка'));
                }
            }
            header('Location: ?page=dispatch');
            exit;
        }

        /* --- раздел моделей --- */
        case 'models_pinned': {
            require_once __DIR__ . '/lib/models.php';
            $raw = (string)req_str('slugs');
            $slugs = [];
            foreach (preg_split('/[,\s]+/', $raw) as $s) {
                $s = trim($s);
                if ($s !== '' && !in_array($s, $slugs, true)) {
                    $slugs[] = $s;
                }
            }
            $bad = [];
            foreach ($slugs as $s) {
                if (!model_get($s)) {
                    $bad[] = $s;
                }
            }
            models_setting_set('pinned_models', implode(',', $slugs));
            flash($bad ? 'warning' : 'success',
                  $bad ? ('Закреплены, но в каталоге нет: ' . implode(', ', $bad))
                       : ('Закреплено моделей: ' . count($slugs)));
            header('Location: ?page=models');
            exit;
        }
        case 'models_sync': {
            require_once __DIR__ . '/lib/models.php';
            $res = models_sync();
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('Каталог обновлён: ' . (int)$res['count'] . ' моделей')
                             : ('Ошибка каталога: ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'models_rank_sync': {
            require_once __DIR__ . '/lib/models.php';
            $res = models_sync_rankings();
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('Рейтинг обновлён: ' . (int)$res['count'] . ' моделей')
                             : ('Ошибка рейтинга: ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'models_provider_mode': {
            require_once __DIR__ . '/lib/models.php';
            $mode = trim(req_str('mode'));
            models_setting_set('or_provider_mode', $mode);
            $desc = $mode === '' ? 'по умолчанию (OpenRouter сам выбирает)'
                   : ($mode === 'price' ? 'по цене (самый дешёвый провайдер)' : ('провайдер: ' . $mode));
            flash('success', 'Маршрутизация провайдера: ' . $desc);
            header('Location: ?page=models');
            exit;
        }
        case 'models_endpoints': {
            require_once __DIR__ . '/lib/models.php';
            $slugs = [];
            foreach (models_top(50, false, 3 / 1e6) as $t) {
                $slugs[] = (string)$t['slug'];
            }
            $res = models_sync_endpoints($slugs);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('Цены провайдеров обновлены: ' . (int)$res['count'] . ' моделей')
                             : ('Ошибка: ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'model_routing_command': {
            require_once __DIR__ . '/lib/queue.php';
            $pid  = req_int('project_id');
            $sort = req_str('sort', 'price');
            if (!in_array($sort, ['price', 'throughput', 'latency'], true)) {
                $sort = 'price';
            }
            if ($pid <= 0) {
                flash('warning', 'Нужен проект');
                header('Location: ?page=models');
                exit;
            }
            $proj = node($pid);
            $ref  = $proj ? (string)$proj['title'] : '';
            $title = 'Выполни в терминале: hermes config set provider_routing.sort ' . $sort
                   . ' — чтобы весь трафик агента в OpenRouter шёл к самому дешёвому провайдеру; '
                   . 'затем выполни /restart и проверь /status, что модель и провайдер на месте.';
            $res = queue_task(['project_id' => $pid, 'title' => $title,
                                'source' => 'provider-routing', 'note' => 'provider_routing.sort=' . $sort]);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('Команда отправлена агенту: задача #' . (int)$res['task_id'])
                             : ('Ошибка: ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'gml_apply_roadmap': {
            require_once __DIR__ . '/lib/gml.php';
            require_once __DIR__ . '/lib/roadmap.php';
            $chatId  = req_int('chat_id');
            $idx     = req_int('idx');
            $payload = '';
            foreach (gml_chat(200) as $m) {
                if ((int)$m['id'] === $chatId) {
                    $payload = (string)($m['payload'] ?? '');
                    break;
                }
            }
            $data = $payload ? json_decode($payload, true) : null;
            $rm = is_array($data) ? ((array)($data['roadmaps'] ?? [])[$idx] ?? null) : null;
            if (!is_array($rm)) {
                flash('danger', 'Предложение не найдено');
                header('Location: ?page=gml');
                exit;
            }
            $pid   = (int)($rm['project'] ?? 0);
            $steps = array_values(array_filter(array_map(static fn($s) => trim((string)$s), (array)($rm['steps'] ?? [])),
                                               static fn($s) => $s !== ''));
            if ($pid <= 0 || !$steps) {
                flash('danger', 'Пустое предложение');
                header('Location: ?page=gml');
                exit;
            }
            $proj = node($pid);
            $title = trim((string)($rm['title'] ?? ''));
            if ($title === '') {
                $title = 'RoadMap от глобального MLL';
            }
            $verId = roadmap_new_version($pid, $steps, 'global-mll', $title);
            if ($verId > 0) {
                roadmap_approve($verId);   // апрув + закрепление за проектом
                flash('success', 'RoadMap применён: ' . ($proj ? (string)$proj['title'] : ('#' . $pid))
                      . ' — версия v' . (int)(scalar("SELECT version FROM roadmaps WHERE id=?", 'i', [$verId])['version'] ?? 0));
            } else {
                flash('danger', 'Не удалось создать версию RoadMap');
            }
            header('Location: ?page=gml');
            exit;
        }
        case 'plan_save': {
            require_once __DIR__ . '/lib/plan.php';
            $pid = req_int('project_id');
            if ($pid > 0) {
                $planId = plan_save($pid, [
                    'goal'   => trim((string)req_str('goal')),
                    'metric' => trim((string)req_str('metric')),
                    'day'    => trim((string)req_str('day')) ?: date('Y-m-d'),
                ]);
                if ($planId > 0) {
                    plan_set_direction_goal($pid, trim((string)req_str('goal')));
                    flash('success', 'План на сегодня сохранён');
                }
            }
            header('Location: ?page=node&id=' . $pid);
            exit;
        }
        case 'dispatch_settings': {
            $pid = req_int('project_id');
            if ($pid > 0) {
                $map = [
                    'dispatch_window_from' => trim((string)req_str('window_from')),
                    'dispatch_window_to'   => trim((string)req_str('window_to')),
                    'dispatch_min_gap_min' => max(0, min(1440, req_int('min_gap_min'))),
                    'dispatch_max_gap_min' => max(0, min(1440, req_int('max_gap_min'))),
                    'dispatch_adaptive'    => req_int('adaptive') ? 1 : 0,
                    'dispatch_auto_next'   => req_int('auto_next') ? 1 : 0,
                    'dispatch_auto_next_delay_min' => max(0, min(1440, req_int('auto_next_delay_min'))),
                ];
                foreach ($map as $col => $val) {
                    q("UPDATE projects SET $col=? WHERE id=?", 'si', [(string)$val, $pid]);
                }
                flash('success', 'Планировщик выдачи сохранён');
            }
            header('Location: ' . (req_str('back') ?: '?page=tree'));
            exit;
        }
        case 'gml_log_depth': {
            require_once __DIR__ . '/lib/gml.php';
            $d = gml_log_depth(req_int('depth'));
            flash('success', 'Глубина истории лога: ' . $d . ' сообщений по направлению');
            header('Location: ?page=gml');
            exit;
        }
        case 'project_auto_roadmap': {
            $pid = req_int('project_id');
            $on  = req_int('on') ? 1 : 0;
            if ($pid > 0) {
                q("UPDATE projects SET auto_roadmap=? WHERE id=?", 'ii', [$on, $pid]);
                flash('success', $on
                    ? 'Авто-постановка задач из RoadMap включена: после утверждения плана шаги сами станут задачами'
                    : 'Авто-постановка задач из RoadMap выключена');
            }
            header('Location: ' . (req_str('back') ?: '?page=tree'));
            exit;
        }
        case 'gml_apply_info_roadmap': {
            require_once __DIR__ . '/lib/gml.php';
            require_once __DIR__ . '/lib/roadmap.php';
            $chatId  = req_int('chat_id');
            $idx     = req_int('idx');
            $payload = '';
            foreach (gml_chat(200) as $m) {
                if ((int)$m['id'] === $chatId) {
                    $payload = (string)($m['payload'] ?? '');
                    break;
                }
            }
            $data = $payload ? json_decode($payload, true) : null;
            $rm = is_array($data) ? ((array)($data['info_roadmaps'] ?? [])[$idx] ?? null) : null;
            if (!is_array($rm)) {
                flash('danger', 'Предложение не найдено');
                header('Location: ?page=gml');
                exit;
            }
            $pid   = (int)($rm['project'] ?? 0);
            $steps = array_values(array_filter(array_map(static fn($s) => trim((string)$s), (array)($rm['steps'] ?? [])),
                                               static fn($s) => $s !== ''));
            if ($pid <= 0 || !$steps) {
                flash('danger', 'Пустое предложение');
                header('Location: ?page=gml');
                exit;
            }
            $proj = node($pid);
            $title = trim((string)($rm['title'] ?? '')) ?: ('Информационный RoadMap: сбор данных');
            $why   = trim((string)($rm['why'] ?? ''));
            // информационный план — черновик: ждёт утверждения оператора
            $verId = roadmap_new_version($pid, $steps, 'global-mll', $title,
                                        'информационный: ' . ($why !== '' ? $why : 'сбор данных для следующего прохода'));
            flash('ok', $verId > 0
                ? ('Информационный RoadMap сохранён черновиком в ' . ($proj ? (string)$proj['title'] : ('#' . $pid))
                   . ' — ждёт утверждения')
                : 'Не удалось сохранить информационный RoadMap');
            header('Location: ' . ($proj ? ('?page=roadmap&project=' . $pid) : '?page=gml'));
            exit;
        }
        case 'gml_apply_today': {
            require_once __DIR__ . '/lib/gml.php';
            require_once __DIR__ . '/lib/plan.php';
            $chatId  = req_int('chat_id');
            $idx     = req_int('idx');
            $payload = '';
            foreach (gml_chat(200) as $m) {
                if ((int)$m['id'] === $chatId) {
                    $payload = (string)($m['payload'] ?? '');
                    break;
                }
            }
            $data = $payload ? json_decode($payload, true) : null;
            $tp = is_array($data) ? ((array)($data['today'] ?? [])[$idx] ?? null) : null;
            if (!is_array($tp)) {
                flash('danger', 'Предложение не найдено');
                header('Location: ?page=gml');
                exit;
            }
            $pid = (int)($tp['project'] ?? 0);
            if ($pid <= 0) {
                flash('danger', 'Пустое предложение');
                header('Location: ?page=gml');
                exit;
            }
            $planId = plan_save($pid, [
                'goal'       => (string)($tp['goal'] ?? ''),
                'metric'     => (string)($tp['metric'] ?? ''),
                'why'        => (string)($tp['why'] ?? ''),
                'first_move' => (string)($tp['first_move'] ?? ''),
                'day'        => (string)req_str('day', date('Y-m-d')),
                'prio'       => 1,
            ]);
            if ($planId > 0) {
                plan_set_direction_goal($pid, (string)($tp['goal'] ?? ''), (string)($tp['metric'] ?? ''));
                $proj = node($pid);
                flash('success', 'План на сегодня зафиксирован: ' . ($proj ? (string)$proj['title'] : ('#' . $pid))
                      . ' — ' . mb_substr((string)($tp['goal'] ?? ''), 0, 90));
            } else {
                flash('danger', 'Не удалось сохранить план дня');
            }
            header('Location: ?page=node&id=' . $pid);
            exit;
        }
        case 'gml_send': {
            require_once __DIR__ . '/lib/gml.php';
            $text = trim((string)req_str('text'));
            $model = trim((string)req_str('model'));
            $_SESSION['gml_hint'] = $text;
            $res = gml_ask($text, $model);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('Глобальный план: ' . count($res['steps']) . ' задач · $' . number_format((float)$res['cost'], 6))
                             : ('Ошибка: ' . $res['error']));
            header('Location: ?page=gml');
            exit;
        }
        case 'gml_clear': {
            require_once __DIR__ . '/lib/gml.php';
            gml_chat_clear();
            flash('success', 'Переписка очищена');
            header('Location: ?page=gml');
            exit;
        }
        case 'gml_note': {
            require_once __DIR__ . '/lib/gml.php';
            $pid = req_int('project_id');
            if ($pid > 0) {
                gml_note_set($pid, (string)req_str('text'));
                flash('success', 'Пояснение сохранено');
            }
            header('Location: ?page=gml');
            exit;
        }
        case 'gml_set_model': {
            require_once __DIR__ . '/lib/models.php';
            $slug = trim((string)req_str('slug'));
            models_setting_set('gml_model', $slug);
            flash('success', 'Модель глобального MLL: ' . ($slug !== '' ? $slug : 'по умолчанию'));
            header('Location: ?page=gml');
            exit;
        }
        case 'roadmap_enqueue': {
            require_once __DIR__ . '/lib/roadmap.php';
            $pid = req_int('project_id');
            $pin = $pid > 0 ? roadmap_current($pid) : null;
            if (!$pin) {
                flash('danger', 'Нет утверждённого плана — нечего ставить в задачи');
                header('Location: ?page=tree');
                exit;
            }
            $steps = roadmap_step_details($pin);
            $res = roadmap_auto_queue($pid, $steps, (int)$pin['version'], 'roadmap-v' . (int)$pin['version']);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('В план поставлено задач: ' . (int)$res['count'] . ' (версия v' . (int)$pin['version'] . ')')
                             : ('Ошибка: ' . (string)($res['error'] ?? 'неизвестно')));
            header('Location: ?page=node&id=' . (int)$pid);
            exit;
        }
        case 'roadmap_decompose': {
            require_once __DIR__ . '/lib/roadmap.php';
            require_once __DIR__ . '/lib/models.php';
            $pid = req_int('project_id');
            $model = trim((string)req_str('model'));
            if ($pid <= 0) {
                flash('warning', 'Не выбран проект');
                header('Location: ?page=node');
                exit;
            }
            if (!roadmap_current($pid)) {
                flash('warning', 'Сначала утверди и закрепи RoadMap — разлагать нечего');
                header('Location: ?page=roadmap&project=' . $pid);
                exit;
            }
            if ($model === '') {
                $model = (string)(project_model($pid) ?: '');
            }
            if ($model === '') {
                $mm = mll_models();
                $model = $mm ? (string)($mm[0]['id'] ?? '') : '';
            }
            $prompt = roadmap_decompose_prompt($pid);
            if ($prompt === '') {
                flash('warning', 'Нет утверждённого RoadMap');
                header('Location: ?page=roadmap&project=' . $pid);
                exit;
            }
            $res = mll_call($model, $prompt);
            if ($res['ok']) {
                $steps = roadmap_parse_steps((string)$res['text']);
                $projId = $pid;
                $row = node($pid);
                if ($row && $row['parent_id']) {
                    $projId = (int)$row['parent_id'];
                }
                q("INSERT INTO mll_runs (project_id, model, ts, tok_in, tok_out, cost_usd, steps)
                   VALUES (?, ?, ?, ?, ?, ?, ?)",
                  'issiidi',
                  [$projId, $model, date('Y-m-d H:i:s'), (int)$res['in'], (int)$res['out'],
                   (float)$res['cost'], count($steps)]);
                $_SESSION['mll_steps'] = array_map(static fn($s) => rm_one_field($s), $steps);
                $_SESSION['mll_details'] = [];
                $_SESSION['mll_usage'] = ['in' => $res['in'], 'out' => $res['out'],
                                           'cache' => $res['cache'] ?? 0, 'cost' => $res['cost'],
                                           'model' => $model, 'raw' => $res['text'],
                                           'target' => $pid, 'target_name' => (string)(node($pid)['title'] ?? '')];
                flash('success', 'RoadMap разложен на технические задачи: ' . count($steps));
            } else {
                flash('danger', 'Модель не ответила: ' . $res['error']);
            }
            header('Location: ?page=roadmap&project=' . $pid);
            exit;
        }
        case 'roadmap_next_manual': {
            require_once __DIR__ . '/lib/roadmap.php';
            require_once __DIR__ . '/lib/models.php';
            $pid = req_int('project_id');
            if ($pid <= 0) {
                flash('warning', 'Не выбран проект');
                header('Location: ?page=node');
                exit;
            }
            $res = roadmap_next_manual($pid);
            if ($res['ok']) {
                flash('success', 'Задача #' . $res['task_id'] . ' поставлена (шаг ' . $res['step'] . '), выдача ' . dispatch_human($res['ready_at']) . ' — модель: ' . $res['model']);
            } else {
                flash('danger', $res['error']);
            }
            header('Location: ?page=node&id=' . $pid);
            exit;
        }
        case 'roadmap_send': {
            require_once __DIR__ . '/lib/roadmap.php';
            $pid = req_int('project_id');
            $txt = req_str('text');
            $withLog = req_str('with_log') === '1';
            $model = req_str('model');
            if ($pid <= 0) {
                flash('warning', 'Не выбран проект');
                header('Location: ?page=tree');
                exit;
            }
            $res = roadmap_ask($pid, $txt, $withLog, $model);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok']
                    ? ('Новая версия RoadMap: ' . count($res['steps']) . ' шагов · $' . number_format((float)$res['cost'], 6))
                    : ('Ошибка: ' . $res['error']));
            header('Location: ?page=roadmap&project=' . $pid);
            exit;
        }
        case 'roadmap_clear': {
            require_once __DIR__ . '/lib/roadmap.php';
            $pid = req_int('project_id');
            roadmap_chat_clear($pid);
            flash('success', 'Переписка очищена — начинаем с нуля');
            header('Location: ?page=roadmap&project=' . $pid);
            exit;
        }
        case 'roadmap_approve': {
            require_once __DIR__ . '/lib/roadmap.php';
            $id = req_int('id');
            $row = roadmap_version($id);
            if (roadmap_approve($id)) {
                flash('success', 'Версия ' . (int)$row['version'] . ' апрувнута и закреплена за проектом');
            }
            header('Location: ?page=roadmap&project=' . (int)$row['project_id']);
            exit;
        }
        case 'roadmap_pin': {
            require_once __DIR__ . '/lib/roadmap.php';
            $id = req_int('id');
            $row = roadmap_version($id);
            if (roadmap_pin($id)) {
                flash('success', 'Версия ' . (int)$row['version'] . ' закреплена за проектом');
            }
            header('Location: ?page=roadmap&project=' . (int)$row['project_id']);
            exit;
        }
        case 'roadmap_edit': {
            require_once __DIR__ . '/lib/roadmap.php';
            $id = req_int('id');
            $row = roadmap_version($id);
            $steps = preg_split('/\r\n|\r|\n/', (string)($_POST['steps'] ?? ''));
            if (roadmap_update($id, $steps ?: [])) {
                flash('success', 'Версия ' . (int)$row['version'] . ' скорректирована');
            } else {
                flash('warning', 'Нечего сохранять');
            }
            header('Location: ?page=roadmap&project=' . (int)$row['project_id']);
            exit;
        }
        case 'roadmap_set_model': {
            require_once __DIR__ . '/lib/models.php';
            $pid = req_int('project_id');
            $slug = req_str('slug');
            project_model_set($pid, $slug !== '' ? $slug : null);
            flash('success', 'Модель проекта: ' . ($slug !== '' ? $slug : 'не назначена'));
            header('Location: ?page=roadmap&project=' . $pid);
            exit;
        }
        case 'model_assign_command': {
            require_once __DIR__ . '/lib/models.php';
            $pid  = req_int('project_id');
            $slug = req_str('slug');
            $prov = trim(req_str('provider'));
            $scope = req_str('scope', 'global');
            if ($pid <= 0 || $slug === '') {
                flash('warning', 'Нужен проект и модель');
                header('Location: ?page=models');
                exit;
            }
            project_model_set($pid, $slug);
            $cmd = '/model ' . $slug;
            if ($prov !== '') {
                $cmd .= ' --provider ' . $prov;
            }
            if ($scope === 'global') {
                $cmd .= ' --global';
            } elseif ($scope === 'session') {
                $cmd .= ' --session';
            }
            q("INSERT INTO chat_commands (project_id, cmd, note, created_at) VALUES (?,?,?,?)",
              'isss', [$pid, $cmd, 'назначение модели', date('Y-m-d H:i:s')]);
            $cid = (int)db()->insert_id;
            q("INSERT INTO events (project_id, kind, level, title, text, server)
               VALUES (?, 'system', 'info', 'model-assign', ?, 'трекер')",
              'is', [$pid, $cmd]);
            flash('success', 'Модель назначена: ' . $slug . ' · команда #' . $cid . ' уйдёт в чат: ' . $cmd);
            header('Location: ?page=models');
            exit;
        }
        case 'model_rotate': {
            require_once __DIR__ . '/lib/models.php';
            $pid = req_int('project_id');
            $res = models_rotate($pid);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok'] ? ('Новая модель: ' . $res['name'] . ' (' . $res['slug'] . ')')
                             : ('Ротация: ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'models_limits': {
            require_once __DIR__ . '/lib/models.php';
            $k = or_key_info();
            flash($k['ok'] ? 'success' : 'danger',
                  $k['ok'] ? 'Лимиты обновлены' : ('Ошибка: ' . $k['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'models_prompt': {
            require_once __DIR__ . '/lib/models.php';
            models_setting_set('model_test_prompt', req_str('prompt'));
            flash('success', 'Тестовый промт сохранён');
            header('Location: ?page=models');
            exit;
        }
        case 'model_test': {
            require_once __DIR__ . '/lib/models.php';
            $slug = req_str('slug');
            $res  = model_run_test($slug);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok']
                    ? ($slug . ': ' . (int)$res['latency'] . ' мс, ' . (int)$res['in'] . '→' . (int)$res['out']
                       . ' ток, $' . number_format((float)$res['cost'], 6))
                    : ($slug . ': ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'model_code_test': {
            require_once __DIR__ . '/lib/models.php';
            $slug = req_str('slug');
            $res  = model_run_code_test($slug);
            flash($res['ok'] ? 'success' : 'danger',
                  $res['ok']
                    ? ($slug . ': ' . (int)$res['latency'] . ' мс, ' . (int)$res['in'] . '→' . (int)$res['out']
                       . ' ток, $' . number_format((float)$res['cost'], 6) . ' · ' . $res['detail'])
                    : ($slug . ': ' . $res['error']));
            header('Location: ?page=models');
            exit;
        }
        case 'project_income': {
            require_once __DIR__ . '/lib/models.php';
            $pid = req_int('project_id');
            $val = (float)str_replace(',', '.', req_str('income'));
            if ($pid > 0) {
                q("UPDATE projects SET income_usd=? WHERE id=?", 'di', [$val, $pid]);
                flash('success', 'Доход проекта сохранён: $' . number_format($val, 2));
            }
            header('Location: ?page=models');
            exit;
        }
        case 'model_score': {
            require_once __DIR__ . '/lib/models.php';
            $id = req_int('id');
            $sc = req_int('score');
            if (model_set_score($id, $sc, req_str('note'))) {
                flash('success', 'Оценка ' . $sc . ' сохранена');
            } else {
                flash('warning', 'Оценка должна быть от 1 до 5');
            }
            header('Location: ?page=models');
            exit;
        }
        case 'project_model': {
            require_once __DIR__ . '/lib/models.php';
            $pid = req_int('project_id');
            $slug = req_str('slug');
            project_model_set($pid, $slug !== '' ? $slug : null);
            flash('success', $slug !== '' ? ('Модель назначена: ' . $slug) : 'Назначение снято');
            header('Location: ?page=models');
            exit;
        }
        case 'models_retest': {
            require_once __DIR__ . '/lib/models.php';
            $scope = req_str('scope', 'tested');
            $slugs = [];
            if ($scope === 'selected') {
                foreach (explode(',', req_str('slugs')) as $s) {
                    $s = trim($s);
                    if ($s !== '') {
                        $slugs[] = $s;
                    }
                }
            } else {
                foreach (models_ranking(60) as $r) {
                    $slugs[] = (string)$r['slug'];
                }
                $slugs = array_slice($slugs, 0, 10);
            }
            if (!$slugs) {
                flash('warning', 'Нет моделей для ретеста');
                header('Location: ?page=models');
                exit;
            }
            models_setting_set('model_retest_queue', json_encode([
                'slugs'  => $slugs,
                'status' => 'run',
                'done'   => 0,
                'total'  => count($slugs),
                'log'    => [],
                'start'  => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE));
            // Фоновый процесс наследует пользователя FPM (www-data) — лог в /tmp.
            $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && nohup php bin/retest.php >> /tmp/model_retest.log 2>&1 &';
            @exec($cmd);
            flash('success', 'Ретест запущен в фоне: ' . count($slugs) . ' моделей');
            header('Location: ?page=models');
            exit;
        }

        /* --- периодические задачи --- */
        case 'recurring_add': {
            require_once __DIR__ . '/lib/recurring.php';
            $pid   = req_int('project_id');
            $every = req_int('every_min');
            $title = trim((string)req_str('title'));
            if ($pid <= 0 || $every <= 0 || $title === '') {
                flash('warning', 'Нужны интервал в минутах и текст задачи');
            } else {
                recurring_add($pid, $every, $title);
                flash('success', 'Периодическая задача добавлена: каждые ' . $every . ' мин');
            }
            header('Location: ?page=node&id=' . $pid);
            exit;
        }
        case 'recurring_del': {
            require_once __DIR__ . '/lib/recurring.php';
            // id берём именно из POST: в URL страницы уже есть id проекта
            $rid = (int)($_POST['id'] ?? 0);
            $row = one("SELECT project_id FROM recurring WHERE id=?", 'i', [$rid]);
            recurring_delete($rid);
            flash('success', 'Периодическая задача удалена');
            $back = req_str('back') === 'tree'
                ? '?page=tree' . $openQ
                : '?page=node&id=' . (int)($row['project_id'] ?? 0);
            header('Location: ' . $back);
            exit;
        }

        /* --- настройки --- */
        case 'save_dispatch_settings': {
            foreach ([
                'dispatch_window_from' => trim((string)req_str('window_from')),
                'dispatch_window_to'   => trim((string)req_str('window_to')),
                'dispatch_min_gap_min' => (string)max(0, min(1440, req_int('min_gap_min'))),
                'dispatch_max_gap_min' => (string)max(0, min(1440, req_int('max_gap_min'))),
                'dispatch_adaptive'    => req_int('adaptive') ? '1' : '0',
                'dispatch_auto_next'   => req_int('auto_next') ? '1' : '0',
                'dispatch_auto_next_delay_min' => (string)max(0, min(1440, req_int('auto_next_delay_min'))),
            ] as $k => $v) {
                q("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", 'ss', [$k, $v]);
            }
            flash('success', 'Планировщик выдачи сохранён');
            header('Location: ?page=settings');
            exit;
        }
        case 'save_settings': {
            foreach (['chat_url','chat_server','ingest_key'] as $k) {
                $v = req_str($k);
                q("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", 'ss', [$k, $v]);
            }
            flash('success', 'Настройки сохранены');
            header('Location: ?page=settings');
            exit;
        }
    }
}

/* ------------------------------------------------------------------ */
/*  GET actions                                                        */
/* ------------------------------------------------------------------ */
if ($act === 'toggle') {
    $id = req_int('id');
    $n = node($id);
    if ($n) {
        $new = $n['status'] === 'done' ? 'active' : 'done';
        q("UPDATE projects SET status = ?, closed_at = ? WHERE id = ?",
          'ssi', [$new, $new === 'done' ? date('Y-m-d H:i:s') : null, $id]);
        if ($new === 'done') {
            // последовательные задачи: следующая становится активной
            $parent = $n['parent_id'] ? (int)$n['parent_id'] : (int)$n['id'];
            $next = one("SELECT id, title FROM projects
                         WHERE parent_id = ? AND kind='task' AND status <> 'done' AND pos > ?
                         ORDER BY pos ASC LIMIT 1", 'ii', [$parent, (int)$n['pos']]);
            if ($next) {
                q("UPDATE projects SET status='active' WHERE id=?", 'i', [(int)$next['id']]);
                flash('success', 'Готово. Следующая: ' . $next['title']);
            } else {
                flash('success', 'Готово');
            }
        }
        header("Location: ?page=node&id=$id");
        exit;
    }
}

if ($act === 'close') {
    $id = req_int('id');
    $n = node($id);
    if ($n) {
        $imported = import_chat_to_node($id, $n);
        q("UPDATE projects SET status='done', closed_at=NOW() WHERE id=?", 'i', [$id]);
        flash('success', "Задача закрыта" . ($imported ? ", из чата забрано событий: $imported" : ''));
        header("Location: ?page=node&id=$id");
        exit;
    }
}

if ($act === 'export_plan') {
    require_once __DIR__ . '/lib/portable.php';
    plan_export();
}

if ($act === 'export_log') {
    require_once __DIR__ . '/lib/portable.php';
    log_export(req_int('project_id'), req_int('task_id'));
}

if ($act === 'export_gml_chat') {
    require_once __DIR__ . '/lib/gml.php';
    $rows = array_reverse(gml_chat(500));
    $out  = "Глобальный MLL — выгрузка чата\n" . date('d.m.Y H:i') . "\n\n";
    foreach ($rows as $m) {
        $out .= '[' . dt((string)$m['created_at'], 'd.m.Y H:i') . '] '
             . ($m['role'] === 'user' ? 'ОПЕРАТОР' : 'ГЛОБАЛЬНЫЙ MLL')
             . (!empty($m['model']) ? ' (' . $m['model'] . ')' : '') . "\n"
             . trim((string)$m['text']) . "\n\n";
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="global-mll-chat-' . date('Ymd-His') . '.txt"');
    echo $out;
    exit;
}

if ($act === 'import_plan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/lib/portable.php';
    $raw = trim((string)($_POST['payload'] ?? ''));
    $nodes = [];
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $nodes = is_array($j['nodes'] ?? null) ? $j['nodes'] : $j;
        }
    }
    if (!$nodes) {
        flash('warning', 'Не разобрался файл: нужен JSON с полем nodes');
    } else {
        $res = plan_import($nodes);
        $msg = 'Импорт: создано ' . $res['created'] . ', обновлено ' . $res['updated'];
        if ($res['errors']) {
            $msg .= ', замечаний: ' . count($res['errors']);
        }
        flash(count($res['errors']) ? 'warning' : 'success', $msg);
    }
    header('Location: ?page=tree' . $openQ);
    exit;
}

if ($act === 'import_chat') {
    $id = req_int('id');
    $n = node($id);
    if ($n) {
        $c = import_chat_to_node($id, $n);
        flash('success', "Из чата забрано событий: $c");
        header("Location: ?page=node&id=$id");
        exit;
    }
}

/* --- MLL-советник: действия до рендера (редирект должен уйти до вывода HTML) --- */
if (strpos($act, 'mll_') === 0) {
    require_once __DIR__ . '/lib/mll.php';
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $mllTargets = mll_targets();
    $mllProjects = [];
    $mllSubs = [];
    foreach ($mllTargets as $t) {
        if ($t['kind'] === 'project') {
            $mllProjects[] = $t;
        } else {
            $mllSubs[] = $t;
        }
    }
    $mllProjectId = req_int('project');
    if ($mllProjectId <= 0 && $mllProjects) {
        $mllProjectId = (int)$mllProjects[0]['id'];
    }
    $mllSubId = req_int('sub');
    $mllProject = $mllTargets[$mllProjectId] ?? null;
    $mllSub = (isset($mllTargets[$mllSubId]) && $mllTargets[$mllSubId]['parent_id'] === $mllProjectId)
        ? $mllTargets[$mllSubId] : null;
    $mllTargetId = $mllSub ? (int)$mllSub['id'] : $mllProjectId;
    $mllTargetName = $mllSub ? $mllSub['title'] : (string)($mllProject['title'] ?? '');
    $mllScope = $mllSub ? 'подпроект направления ' . (string)($mllProject['title'] ?? '') : 'направление';
    $mllModel = req_str('model');
    if ($mllModel === '' || mll_model($mllModel) === null) {
        // модель, назначенная направлению, иначе запасная
        $mllAssigned = '';
        try {
            require_once __DIR__ . '/lib/models.php';
            $mllAssigned = (string)(project_model($mllTargetId) ?: project_model($mllProjectId));
        } catch (Throwable $e) {
            $mllAssigned = '';
        }
        $mllModel = ($mllAssigned !== '' && mll_model($mllAssigned) !== null)
            ? $mllAssigned : 'z-ai/glm-5.3-flash';
    }
    $mllBack = '?page=mll&project=' . $mllProjectId . ($mllSub ? '&sub=' . (int)$mllSub['id'] : '')
             . '&model=' . urlencode($mllModel);

    if ($act === 'mll_generate') {
        $mllHint = trim((string)req_str('hint'));
        $_SESSION['mll_hint'] = $mllHint;
        $mllProjId = (int)$mllTargetId;
        $mllTargetRow0 = node($mllTargetId);
        if ($mllTargetRow0 && $mllTargetRow0['parent_id']) {
            $mllProjId = (int)$mllTargetRow0['parent_id'];
        }
        require_once __DIR__ . '/lib/roadmap.php';
        $res = mll_call($mllModel, roadmap_mll_prompt($mllProjId, $mllHint, true));
        if ($res['ok']) {
            $mllSteps = roadmap_parse_steps($res['text']);
            // учёт вызова MLL по направлению
            $mllProjId = $mllTargetId;
            $mllTargetRow = node($mllTargetId);
            if ($mllTargetRow && $mllTargetRow['parent_id']) {
                $mllProjId = (int)$mllTargetRow['parent_id'];
            }
            q("INSERT INTO mll_runs (project_id, model, ts, tok_in, tok_out, cost_usd, steps)
               VALUES (?, ?, ?, ?, ?, ?, ?)",
              'issiidi',
              [$mllProjId, $mllModel, date('Y-m-d H:i:s'), (int)$res['in'], (int)$res['out'],
               (float)$res['cost'], count($mllSteps)]);
            $_SESSION['mll_steps'] = array_map(static fn($s) => rm_one_field($s), $mllSteps);
            $_SESSION['mll_details'] = [];
            $_SESSION['mll_usage'] = ['in' => $res['in'], 'out' => $res['out'],
                                       'cache' => $res['cache'] ?? 0, 'cost' => $res['cost'],
                                       'model' => $mllModel, 'raw' => $res['text'],
                                       'target' => $mllTargetId, 'target_name' => $mllTargetName];
        } else {
            $_SESSION['mll_steps'] = [];
            $_SESSION['mll_usage'] = null;
            flash('danger', 'Модель не ответила: ' . $res['error']);
        }
        header('Location: ' . $mllBack);
        exit;
    }

    if ($act === 'mll_project_generate') {
        $pid = req_int('project_id');
        $hint = trim((string)req_str('hint'));
        $model = trim((string)req_str('model'));
        if ($pid <= 0) {
            flash('warning', 'Не выбран проект');
            header('Location: ?page=node');
            exit;
        }
        require_once __DIR__ . '/lib/roadmap.php';
        require_once __DIR__ . '/lib/models.php';
        if ($model === '') {
            $model = (string)(project_model($pid) ?: '');
        }
        if ($model === '') {
            $mm = mll_models();
            $model = $mm ? (string)($mm[0]['id'] ?? '') : '';
        }
        if ($model === '') {
            flash('danger', 'Нет доступных моделей');
            header('Location: ?page=node&id=' . $pid);
            exit;
        }
        $_SESSION['mll_hint'] = $hint;
        $res = mll_call($model, roadmap_mll_prompt($pid, $hint, true));
        if ($res['ok']) {
            $steps = roadmap_parse_steps($res['text']);
            $projId = $pid;
            $row = node($pid);
            if ($row && $row['parent_id']) {
                $projId = (int)$row['parent_id'];
            }
            q("INSERT INTO mll_runs (project_id, model, ts, tok_in, tok_out, cost_usd, steps)
               VALUES (?, ?, ?, ?, ?, ?, ?)",
              'issiidi',
              [$projId, $model, date('Y-m-d H:i:s'), (int)$res['in'], (int)$res['out'],
               (float)$res['cost'], count($steps)]);
            $_SESSION['mll_steps'] = array_map(static fn($s) => rm_one_field($s), $steps);
            $_SESSION['mll_details'] = [];
            $_SESSION['mll_usage'] = ['in' => $res['in'], 'out' => $res['out'],
                                       'cache' => $res['cache'] ?? 0, 'cost' => $res['cost'],
                                       'model' => $model, 'raw' => $res['text'],
                                       'target' => $pid, 'target_name' => (string)(node($pid)['title'] ?? '')];
            flash('success', 'MLL предложил шагов: ' . count($steps));
        } else {
            $_SESSION['mll_steps'] = [];
            $_SESSION['mll_usage'] = null;
            flash('danger', 'Модель не ответила: ' . $res['error']);
        }
        header('Location: ?page=node&id=' . $pid);
        exit;
    }

    if ($act === 'mll_add') {
        $title = trim((string)req_str('title'));
        $parent = req_int('parent');
        if ($title === '' || $parent <= 0 || !isset($mllTargets[$parent])) {
            flash('warning', 'Шаг не добавлен: нет названия или направления');
        } else {
            $pid = (int)$parent;
            $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$pid]) ?? 0) + 1;
            $id = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
            $parentRow = node($pid);
            $projId = $parentRow && $parentRow['parent_id'] ? (int)$parentRow['parent_id'] : $pid;
            $projRow = node($projId);
            q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                                    agent_code, server_ip)
               VALUES (?, ?, 'task', ?, '', 'planned', 2, ?, NULL, ?, ?)",
              'iisiss',
              [$id, $pid, $title, $pos,
               (string)($projRow['agent_code'] ?? ''),
               (string)($projRow['server_ip'] ?? '')]);
            flash('success', 'Задача [' . $id . '] добавлена: ' . $title);
        }
        header('Location: ' . $mllBack);
        exit;
    }

    if ($act === 'mll_add_many') {
        // в форме из карточки проекта можно указать, куда класть задачи
        $mllParentOverride = req_int('parent');
        if ($mllParentOverride > 0 && isset($mllTargets[$mllParentOverride])) {
            $mllTargetId = $mllParentOverride;
        }
        $picks = $_POST['pick'] ?? [];
        $titles = [];
        $details = $_SESSION['mll_details'] ?? [];
        foreach ((array)$picks as $i) {
            $i = (int)$i;
            $stepTitle = trim((string)req_str('step' . $i));
            if ($stepTitle !== '') {
                $titles[$i] = $stepTitle;
            }
        }
        $added = 0;
        foreach ($titles as $i => $stepTitle) {
            $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$mllTargetId]) ?? 0) + 1;
            $id = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
            $parentRow = node($mllTargetId);
            $projId = $parentRow && $parentRow['parent_id'] ? (int)$parentRow['parent_id'] : $mllTargetId;
            $projRow = node($projId);
            // текст задачи — целиком в заголовок: агент получает ровно то, что видно в input
            $full = $stepTitle;
            $det  = trim((string)($details[$i] ?? ''));
            if ($det !== '' && strpos($full, $det) === false) {
                $full = rtrim($full, ". ") . '. ' . $det;
            }
            // планировщик: первая задача — по окну выдачи, остальные ждут очереди
            $dcfg = dispatch_settings($projId);
            $dgap = dispatch_gap_minutes($projId, $dcfg);
            $slot = dispatch_slot(dispatch_now()->modify('+' . $dgap . ' minutes'), $dcfg, $projId);
            $stepNo = $added + 1;
            $chain  = 'mll-' . $projId . '-' . date('YmdHis');
            $ready  = ($stepNo === 1) ? $slot : null;
            // цель и срок — из плана дня направления
            $planId = 0;
            $goal   = '';
            $due    = '';
            try {
                require_once __DIR__ . '/lib/plan.php';
                $pl = plan_current($projId);
                if ($pl) {
                    $planId = (int)$pl['id'];
                    $goal   = (string)($pl['goal'] ?? '');
                    $due    = date('d.m.Y', strtotime((string)$pl['day']));
                }
            } catch (Throwable $e) {
                // плана дня может не быть
            }
            q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                                    task_desc, agent_code, server_ip, ready_at, auto_chain, auto_step,
                                    plan_id, goal, deadline)
               VALUES (?, ?, 'task', ?, '', 'planned', 2, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)",
              'iisisssssiiss',
              [$id, $mllTargetId, $full, $pos,
               'источник: MLL\nшаг ' . $stepNo,
               (string)($projRow['agent_code'] ?? ''),
               (string)($projRow['server_ip'] ?? ''),
               $ready, $chain, $stepNo,
               $planId > 0 ? $planId : null, $goal !== '' ? $goal : null, $due]);
            $added++;
        }
        // добавленные шаги убираем из списка — остальные остаются на странице
        $left = [];
        foreach (($_SESSION['mll_steps'] ?? []) as $i => $s) {
            if (!isset($titles[$i])) {
                $left[] = $s;
            }
        }
        $_SESSION['mll_steps'] = $left;
        flash($added ? 'success' : 'warning',
              $added ? "Добавлено задач: $added" : 'Ничего не отмечено');
        $mllBackTo = req_str('back');
        header('Location: ' . ($mllBackTo !== '' ? $mllBackTo : $mllBack));
        exit;
    }

    if ($act === 'mll_clear') {
        unset($_SESSION['mll_steps'], $_SESSION['mll_usage']);
        $mllBackTo = req_str('back');
        header('Location: ' . ($mllBackTo !== '' ? $mllBackTo : ('?page=mll&project=' . $mllProjectId)));
        exit;
    }
}

/* ------------------------------------------------------------------ */
/*  routing                                                            */
/* ------------------------------------------------------------------ */
/* --- Графики: JSON-выдача для переключения периода без перезагрузки --- */
if (req_str('fmt') === 'json' && req_str('page') === 'charts') {
    require_once __DIR__ . '/lib/charts.php';
    $jRange = req_str('range');
    if ($jRange !== 'week') {
        $jRange = 'today';
    }
    $jBucket = req_int('bucket');
    if (!in_array($jBucket, [1, 5, 60], true)) {
        $jBucket = 60;
    }
    [$jFrom, $jTo] = charts_period($jRange);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(charts_data($jBucket * 60, $jFrom, $jTo), JSON_UNESCAPED_UNICODE);
    exit;
}

$routes = [
    'dashboard'   => 'dashboard.php',
    'tree'        => 'tree.php',
    'node'        => 'node.php',
    'node_new'    => 'node_form.php',
    'node_edit'   => 'node_form.php',
    'agents'      => 'agents.php',
    'usage' => 'usage.php',
    'charts' => 'charts.php',
    'models' => 'models.php',
    'roadmap' => 'roadmap.php',
    'gml' => 'gml.php',
    'money_servers' => 'money.php',
    'money_days' => 'money.php',
    'import'      => 'import.php',
    'mll'         => 'mll.php',
    'events'      => 'events.php',
    'finance'     => 'finance.php',
    'goal_new'    => 'goal_form.php',
    'dispatch'    => 'dispatch.php',
    'dispatch_new'=> 'dispatch_form.php',
    'settings'    => 'settings.php',
    'health'      => 'health.php',
    'log'         => 'log.php',
];
if (!isset($routes[$page])) { $page = 'dashboard'; }
render($routes[$page], ['page' => $page]);
