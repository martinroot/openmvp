<?php
declare(strict_types=1);
/**
 * RoadMap-конструктор: чат с MLL по проекту, версии плана, закрепление версии.
 *
 *   roadmap_chats — переписка (сохраняется, держит контекст);
 *   roadmaps      — версии RoadMap (список шагов), одна закреплена за проектом.
 */
require_once __DIR__ . '/../lib/core.php';
require_once __DIR__ . '/../lib/models.php';
require_once __DIR__ . '/../lib/mll.php';

/* ------------------------------------------------------------------ чат */

function roadmap_chat(int $projectId, int $limit = 100): array
{
    return all("SELECT id, role, text, model, version_id, created_at
                FROM roadmap_chats WHERE project_id=? ORDER BY id DESC LIMIT " . (int)$limit,
               'i', [$projectId]) ?: [];
}

function roadmap_chat_add(int $projectId, string $role, string $text, string $model = '', int $versionId = 0): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    q("INSERT INTO roadmap_chats (project_id, role, text, model, version_id, created_at)
       VALUES (?,?,?,?,?,?)", 'isssis', [$projectId, $role, mb_substr($text, 0, 8000), $model, $versionId,
                                           date('Y-m-d H:i:s')]);
    return (int)db()->insert_id;
}

function roadmap_chat_clear(int $projectId): void
{
    q("DELETE FROM roadmap_chats WHERE project_id=?", 'i', [$projectId]);
}

/* --------------------------------------------------------------- версии */

function roadmap_versions(int $projectId): array
{
    return all("SELECT id, version, title, content, note, status, model, created_at
                FROM roadmaps WHERE project_id=? ORDER BY version DESC", 'i', [$projectId]) ?: [];
}

function roadmap_version(int $id): ?array
{
    $r = one("SELECT * FROM roadmaps WHERE id=?", 'i', [$id]);
    return $r ?: null;
}

function roadmap_steps(array $row): array
{
    $out = [];
    foreach (roadmap_step_details($row) as $s) {
        $out[] = $s['title'];
    }
    return $out;
}

/** Шаги с описанием: [{title, detail}] (старые версии — просто строки). */
function roadmap_step_details(array $row): array
{
    $j = json_decode((string)($row['content'] ?? ''), true);
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $s) {
        if (is_array($s)) {
            $title  = trim((string)($s['title'] ?? ''));
            $detail = trim((string)($s['detail'] ?? ''));
            $goal   = trim((string)($s['goal'] ?? ''));
            $due    = trim((string)($s['due'] ?? ''));
            $hours  = (int)($s['hours'] ?? 0);
        } else {
            $title  = trim((string)$s);
            $detail = '';
            $goal   = '';
            $due    = '';
            $hours  = 0;
        }
        if ($title !== '') {
            $out[] = ['title' => $title, 'detail' => $detail, 'goal' => $goal,
                      'due' => $due, 'hours' => $hours];
        }
    }
    return $out;
}

/** Новая версия RoadMap (черновик). */
function roadmap_new_version(int $projectId, array $steps, string $model, string $title = '', string $note = ''): int
{
    $norm = [];
    foreach ($steps as $s) {
        if (is_array($s)) {
            // шаг может прийти как {title, detail} или как {task, goal, hours}
            $t = trim((string)($s['title'] ?? ($s['task'] ?? '')));
            $d = trim((string)($s['detail'] ?? ''));
            $g = trim((string)($s['goal'] ?? ''));
            $w = trim((string)($s['due'] ?? ''));
            $h = max(0, min(720, (int)($s['hours'] ?? 0)));
        } else {
            $t = trim((string)$s);
            $d = '';
            $g = '';
            $w = '';
            $h = 0;
        }
        if ($t !== '') {
            $norm[] = ['title' => $t, 'detail' => $d, 'goal' => $g, 'due' => $w, 'hours' => $h];
        }
    }
    $steps = $norm;
    if (!$steps) {
        return 0;
    }
    $next = (int)(scalar("SELECT COALESCE(MAX(version),0)+1 FROM roadmaps WHERE project_id=?",
                          'i', [$projectId]) ?? 0);
    if ($next < 1) {
        $next = 1;
    }
    q("INSERT INTO roadmaps (project_id, version, title, content, note, status, model, created_at)
       VALUES (?,?,?,?,?,?,?,?)", 'iissssss',
      [$projectId, $next, mb_substr($title, 0, 250), json_encode($steps, JSON_UNESCAPED_UNICODE),
       mb_substr($note, 0, 250), 'draft', $model, date('Y-m-d H:i:s')]);
    return (int)db()->insert_id;
}



/**
 * Шаги цепочки RoadMap: по строке auto_chain вида "roadmap-30-v6" находим
 * версию RoadMap и её шаги.
 */
function roadmap_chain_steps(int $projectId, string $chain): array
{
    if (preg_match('/^roadmap-(\d+)-v(\d+)$/', $chain, $m)) {
        $ver = (int)$m[2];
        $row = one("SELECT * FROM roadmaps WHERE project_id=? AND version=?", 'ii', [$projectId, $ver]);
        if ($row) {
            return roadmap_step_details($row);
        }
    }
    return [];
}

/**
 * Автопилот RoadMap: следующий шаг плана ставится в задачи после завершения
 * текущего шага — не сразу пачкой, а по одному, с учётом паузы (окно выдачи +
 * производительность агента). Цепочка хранится в колонках auto_chain/auto_step.
 */
function roadmap_autopilot_step(int $projectId, string $chain, int $step): ?int
{
    // уже есть задача с этим шагом цепочки — не плодим
    $dup = one("SELECT id FROM projects WHERE parent_id=? AND kind='task' AND auto_chain=? AND auto_step=? LIMIT 1",
               'isi', [$projectId, $chain, $step]);
    if ($dup) {
        return (int)$dup['id'];
    }
    return null;
}

/**
 * Поставить следующий шаг цепочки (RoadMap) в план.
 * @return array{ok:bool,task_id?:int,ready_at?:string,error?:string}
 */
function roadmap_autopilot_next(int $projectId, string $chain, array $steps, int $afterStep,
                               array $cfg = []): array
{
    $cfg = $cfg ?: dispatch_settings($projectId);
    $next = $afterStep + 1;
    if ($next < 1 || $next > count($steps)) {
        return ['ok' => true, 'error' => 'цепочка завершена'];
    }
    $step = $steps[$next - 1];
    $title = is_array($step) ? trim((string)($step['title'] ?? '')) : trim((string)$step);
    $detail = is_array($step) ? trim((string)($step['detail'] ?? '')) : '';
    if ($title === '') {
        return ['ok' => false, 'error' => 'пустой шаг'];
    }
    $gapMin = dispatch_gap_minutes($projectId, $cfg);
    $base   = dispatch_now()->modify('+' . $gapMin . ' minutes');
    $ready  = dispatch_slot($base, $cfg, $projectId);

    $existing = roadmap_autopilot_step($projectId, $chain, $next);
    if ($existing) {
        // шаг уже стоит в плане — обновляем время готовности
        q("UPDATE projects SET ready_at=?, status=IF(status='done','done','planned') WHERE id=?",
          'si', [$ready, $existing]);
        return ['ok' => true, 'task_id' => $existing, 'ready_at' => $ready, 'step' => $next];
    }

    $proj = node($projectId);

    // цель и срок задачи: план дня направления + цель этапа
    $planId = 0;
    $goal   = is_array($step) ? trim((string)($step['goal'] ?? '')) : '';
    $stageGoal = $goal;
    $due    = is_array($step) ? trim((string)($step['due'] ?? '')) : '';
    $hours  = is_array($step) ? (int)($step['hours'] ?? 0) : 0;
    if ($hours > 0 && $due === '') {
        $due = '+' . $hours . ' ч';
    }
    try {
        require_once __DIR__ . '/plan.php';
        $pl = plan_current($projectId);
        if ($pl) {
            $planId = (int)$pl['id'];
            if ($goal === '') {
                $goal = (string)($pl['goal'] ?? '');
            }
        }
    } catch (Throwable $e) {
        // плана дня может не быть
    }

    $pos    = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$projectId]) ?? 0) + 1;
    $nextId = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
    q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                             task_desc, agent_code, server_ip, ready_at, auto_chain, auto_step,
                             plan_id, goal, deadline, stage_goal)
       VALUES (?, ?, 'task', ?, '', 'planned', 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
      'iisissssssiisss',
      [$nextId, $projectId, $title, $pos,
       'источник: ' . $chain . '\nшаг ' . $next,
       $detail !== '' ? $detail : null,
       (string)($proj['agent_code'] ?? ''), (string)($proj['server_ip'] ?? ''),
       $ready, $chain, $next,
       $planId > 0 ? $planId : null, $goal !== '' ? $goal : null, $due,
       $stageGoal !== '' ? $stageGoal : null]);
    q("INSERT INTO events (project_id, task_id, kind, level, title, text)
       VALUES (?, ?, 'system', 'info', ?, ?)",
      'iiss', [$projectId, $nextId, 'следующий шаг по плану', $title
                . "\nвыдача: " . dispatch_human($ready)]);

    return ['ok' => true, 'task_id' => $nextId, 'ready_at' => $ready, 'step' => $next];
}

/**
 * Автоматическая постановка задач из RoadMap.
 *
 * Когда версия утверждена (и закреплена), её шаги сами становятся задачами
 * в плане направления — оператору не нужно ничего нажимать. Дубли не
 * плодим: шаг, для которого уже есть задача с таким же текстом, пропускаем.
 */
function roadmap_auto_queue(int $projectId, array $steps, int $version, string $source = ''): array
{
    $proj = node($projectId);
    if (!$proj) {
        return ['ok' => false, 'error' => 'проект не найден'];
    }
    if ($source === '') {
        $source = 'roadmap-v' . (int)$version;
    }
    $scope = direction_scope_ids($projectId);
    $ph    = implode(',', array_fill(0, count($scope), '?'));
    $existing = [];
    foreach (all("SELECT title FROM projects WHERE parent_id IN ($ph) AND kind='task'",
                 str_repeat('i', count($scope)), $scope) as $r) {
        $existing[trim(preg_replace('/\s+/u', ' ', (string)$r['title']))] = true;
    }
    $made  = [];
    $pos   = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$projectId]) ?? 0);
    $nextId = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);
    $chain = 'roadmap-' . (int)$projectId . '-v' . (int)$version;
    $cfg   = dispatch_settings($projectId);
    $byWindow = (int)($cfg['batch_window'] ?? 1) === 1;
    $gapMin   = dispatch_gap_minutes($projectId, $cfg);
    $slot     = dispatch_slot(dispatch_now()->modify('+' . $gapMin . ' minutes'), $cfg, $projectId);
    foreach ($steps as $i => $s) {
        $title = is_array($s) ? trim((string)($s['title'] ?? '')) : trim((string)$s);
        $detail = is_array($s) ? trim((string)($s['detail'] ?? '')) : '';
        if ($title === '') {
            continue;
        }
        $key = trim(preg_replace('/\s+/u', ' ', $title));
        if (isset($existing[$key])) {
            continue;
        }
        $existing[$key] = true;
        $pos++;
        $id = $nextId++;
        // готовность: первый шаг — по слоту, остальные ждут очереди (окно + пауза)
        $stepNo  = $i + 1;
        $readyAt = $byWindow ? ($stepNo === 1 ? $slot : null) : $slot;
        // срок этапа: hours — через сколько часов от начала этапа можно браться
        $hours = is_array($s) ? (int)($s['hours'] ?? 0) : 0;
        $stageGoal = is_array($s) ? trim((string)($s['goal'] ?? '')) : '';
        $due = is_array($s) ? trim((string)($s['due'] ?? '')) : '';
        if ($hours > 0) {
            $due = $due !== '' ? $due : ('+' . $hours . ' ч');
        }
        // цель задачи = план дня направления (если есть), иначе цель этапа
        $planId = 0;
        $goal   = $stageGoal;
        try {
            require_once __DIR__ . '/plan.php';
            $pl = plan_current($projectId);
            if ($pl) {
                $planId = (int)$pl['id'];
                if ($goal === '') {
                    $goal = (string)($pl['goal'] ?? '');
                }
            }
        } catch (Throwable $e) {
            // плана дня может не быть
        }
        q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                                 task_desc, agent_code, server_ip, ready_at, auto_chain, auto_step,
                                 plan_id, goal, deadline, stage_goal)
           VALUES (?, ?, 'task', ?, '', 'planned', 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          'iisissssssiisss',
          [$id, $projectId, $title, $pos, 'источник: ' . $source . '\nшаг ' . $stepNo,
           $detail !== '' ? $detail : null,
           (string)($proj['agent_code'] ?? ''), (string)($proj['server_ip'] ?? ''),
           $readyAt, $chain, $stepNo,
           $planId > 0 ? $planId : null, $goal !== '' ? $goal : null, $due, $stageGoal !== '' ? $stageGoal : null]);
        q("INSERT INTO events (project_id, task_id, kind, level, title, text)
           VALUES (?, ?, 'system', 'info', ?, ?)",
          'iiss', [$projectId, $id, 'задача из RoadMap v' . (int)$version,
                    $title . ($readyAt ? "\nвыдача: " . dispatch_human($readyAt) : '')]);
        $made[] = $id;
    }
    return ['ok' => true, 'created' => $made, 'count' => count($made)];
}

/** Скорректировать версию — правится текст шагов, версия та же. */
function roadmap_update(int $id, array $steps, ?string $title = null): bool
{
    $row = roadmap_version($id);
    if (!$row) {
        return false;
    }
    $steps = array_values(array_filter(array_map(static fn($s) => trim((string)$s), $steps),
                                       static fn($s) => $s !== ''));
    if (!$steps) {
        return false;
    }
    if ($title === null || trim($title) === '') {
        $title = (string)$row['title'];
    }
    q("UPDATE roadmaps SET content=?, title=? WHERE id=?", 'ssi',
      [json_encode($steps, JSON_UNESCAPED_UNICODE), mb_substr((string)$title, 0, 250), $id]);
    return true;
}

function roadmap_approve(int $id): bool
{
    $row = roadmap_version($id);
    if (!$row) {
        return false;
    }
    q("UPDATE roadmaps SET status='approved' WHERE id=?", 'i', [$id]);
    // апрувнутая версия сразу становится текущей для проекта
    roadmap_pin($id);
    // задачи из RoadMap ставятся автоматически — без участия оператора
    $pid = (int)$row['project_id'];
    $proj = node($pid);
    if ($proj && (int)($proj['auto_roadmap'] ?? 0) === 1) {
        $steps = roadmap_step_details($row);
        if ($steps) {
            $res = roadmap_auto_queue($pid, $steps, (int)$row['version']);
            if (!empty($res['created'])) {
                q("INSERT INTO events (project_id, kind, level, title, text)
                   VALUES (?, 'system', 'ok', ?, ?)",
                  'iss', [$pid, 'RoadMap утверждён — задачи поставлены',
                           'Создано задач: ' . (int)$res['count'] . ' (версия v' . (int)$row['version'] . ')']);
            }
        }
    }
    return true;
}

/** Текущий план проекта: закреплённый, иначе последний апрувнутый. */
function roadmap_current(int $projectId): ?array
{
    $r = one("SELECT * FROM roadmaps WHERE project_id=? AND status='pinned' LIMIT 1", 'i', [$projectId]);
    if ($r) {
        return $r;
    }
    $r = one("SELECT * FROM roadmaps WHERE project_id=? AND status='approved'
              ORDER BY version DESC LIMIT 1", 'i', [$projectId]);
    return $r ?: null;
}

/** Закрепить версию за проектом (все остальные версии остаются черновиками). */
function roadmap_pin(int $id): bool
{
    $row = roadmap_version($id);
    if (!$row) {
        return false;
    }
    $pid = (int)$row['project_id'];
    q("UPDATE roadmaps SET status='draft' WHERE project_id=? AND status='pinned'", 'i', [$pid]);
    q("UPDATE roadmaps SET status='pinned' WHERE id=?", 'i', [$id]);
    return true;
}

function roadmap_pinned(int $projectId): ?array
{
    $r = one("SELECT * FROM roadmaps WHERE project_id=? AND status='pinned' LIMIT 1", 'i', [$projectId]);
    return $r ?: null;
}


/**
 * Промт для генерации задач по направлению:
 *   ЛОГ (что делали агенты) + ЗАДАЧИ (текущий план) + АПРУВНУТЫЙ RoadMap.
 * Система рассуждает по последней активности и может предложить исправление
 * неверно развёрнутой задачи или задачу «затупившей» модели.
 */
/**
 * Промт для генерации задач по направлению:
 *   ЛОГ (что делали агенты) + ЗАДАЧИ (текущий план) + АПРУВНУТЫЙ RoadMap.
 * Система рассуждает по последней активности и может предложить исправление
 * неверно развёрнутой задачи или задачу «затупившей» модели.
 */
function roadmap_mll_prompt(int $projectId, string $hint = '', bool $withLog = true): string
{
    $ctx = roadmap_context_view($projectId, $hint, $withLog);
    return <<<PROMPT
Ты техлид и архитектор плана работ. Работаем по одному направлению.

{$ctx}

Дай РОВНО 5 шагов по направлению. Правила:
1. task - ОДНО поле, полный текст задачи, который агент получит и выполнит. Пиши как ТЗ: что сделать, где именно, вход (что уже есть или что сломано) и как проверить результат. До 400 знаков, по-русски, с глагола.
2. Шаги строго по порядку: каждый следующий опирается на результат предыдущего. Не перечисляй параллельные несвязанные дела.
3. Учитывай журнал: если видно, что задача развёрнута неверно (ошибка, обрыв, неверный шаг) - предложи шаг на исправление именно её; если модель/агент затупил на одном месте - предложи шаг, который это распутает.
4. Не дублируй шаги между собой и не повторяй то, что уже сделано.
5. Порядок по важности: сначала деньги, потом обслуживание.

Ответь ТОЛЬКО JSON без пояснений:
{"steps":[{"task":"ПОЛНЫЙ ТЕКСТ ЗАДАЧИ"}]}

PROMPT;
}

/**
 * Декомпозиция утверждённого RoadMap в технические задачи по порядку.
 * Связующее звено: план (RoadMap) -> конкретные задачи в плане трекера.
 */
function roadmap_decompose_prompt(int $projectId): string
{
    $pinned = roadmap_current($projectId);
    if (!$pinned) {
        return '';
    }
    $steps = roadmap_steps($pinned);
    $ctx   = roadmap_context_view($projectId, '', true);
    $road  = "RoadMap v" . (int)$pinned['version'] . " (утверждён оператором):\n"
           . implode("\n", array_map(static fn($s, $i) => ($i + 1) . '. ' . $s, $steps, array_keys($steps)));
    return <<<PROMPT
Ты техлид. У тебя утверждённый RoadMap по направлению и фактическое состояние дел.
Нужно разложить план на ТЕХНИЧЕСКИЕ ЗАДАЧИ по порядку - так, чтобы агент мог выполнить каждую следующую задачу.

{$ctx}
=== УТВЕРЖДЁННЫЙ ROADMAP (цель) ===
{$road}

Дай 3-7 задач - строго по порядку RoadMap, начиная с ближайшей следующей. Правила:
1. task - ОДНО поле, полный текст задачи для агента: что сделать, где именно, вход (файлы/пути/номера заказов, что уже есть) и как проверить результат. До 400 знаков, по-русски, с глагола.
2. Задачи должны быть самодостаточными: агент прочитает их отдельно, без контекста RoadMap.
3. Не предлагай то, что уже сделано; опирайся на журнал.

Ответь ТОЛЬКО JSON:
{"steps":[{"task":"ПОЛНЫЙ ТЕКСТ ЗАДАЧИ"}]}

PROMPT;
}

/**
 * Собранный контекст - то, что видит модель. Показывается оператору,
 * чтобы он видел, почему модель отвечает так или иначе.
 */
function roadmap_context_view(int $projectId, string $hint = '', bool $withLog = true): string
{
    $dir  = roadmap_direction_context($projectId);
    $log  = $withLog ? roadmap_log_context($projectId, 50) : '(журнал не подключали)';
    $plan = mll_plan_context();
    $road = 'RoadMap пока не утверждён.';
    $pinned = roadmap_current($projectId);
    if ($pinned) {
        $st = roadmap_steps($pinned);
        $road = "RoadMap v" . (int)$pinned['version'] . " (утверждён):\n"
              . implode("\n", array_map(static fn($s, $i) => ($i + 1) . '. ' . $s, $st, array_keys($st)));
    }
    $hint = trim(preg_replace('/\s+/', ' ', $hint));
    $hintBlock = $hint !== ''
        ? "\n=== ПОЖЕЛАНИЕ ОПЕРАТОРА ===\n{$hint}\nЭто главный вектор: шаги должны идти в эту сторону.\n"
        : '';
    // глобальное планирование: план дня и разборы выполнений (цель vs результат)
    $gplan = '';
    $reviews = '';
    try {
        require_once __DIR__ . '/plan.php';
        $pl = plan_current($projectId);
        if ($pl) {
            $gplan = sprintf("цель дня: %s%s%s\n      первое действие: %s\n      метрика: %s",
                (string)$pl['goal'],
                (string)$pl['why'] !== '' ? ' (почему: ' . (string)$pl['why'] . ')' : '',
                (string)$pl['first_move'] !== '' ? '' : '',
                (string)$pl['first_move'],
                (string)$pl['metric']);
        }
        $rv = all("SELECT id, title, closed_at, result_note FROM projects
                   WHERE parent_id=? AND kind='task' AND status='done' AND result_note IS NOT NULL
                   ORDER BY closed_at DESC LIMIT 5", 'i', [$projectId]);
        foreach ($rv as $r2) {
            $reviews .= '    [' . (int)$r2['id'] . '] ' . mb_substr((string)$r2['title'], 0, 70) . ' → '
                      . str_replace("\n", ' / ', mb_substr((string)$r2['result_note'], 0, 400)) . "\n";
        }
    } catch (Throwable $e) {
        // плана дня или разборов может не быть
    }
    $gplanBlock  = $gplan !== ''    ? "\n=== ЦЕЛЬ ДНЯ ОТ ГЛОБАЛЬНОГО MLL ===\n{$gplan}\n" : '';
    $reviewsBlock = $reviews !== '' ? "\n=== РАЗБОР ПОСЛЕДНИХ ВЫПОЛНЕНИЙ (факт) ===\n{$reviews}" : '';
    return "=== НАПРАВЛЕНИЕ ===\n{$dir}\n{$hintBlock}"
         . $gplanBlock
         . "\n=== ТЕКУЩИЕ ЗАДАЧИ (все направления) ===\n{$plan}\n"
         . "=== АПРУВНУТЫЙ ROADMAP (цель) ===\n{$road}\n"
         . $reviewsBlock
         . "=== ЖУРНАЛ (последняя активность агентов) ===\n{$log}\n";
}


/** Одно поле: заголовок и описание склеиваются в один текст задачи. */
function rm_one_field(array $s): string
{
    $title  = trim((string)($s['title'] ?? ''));
    $detail = trim((string)($s['detail'] ?? ''));
    if ($detail === '' || strpos($title, $detail) !== false) {
        return $title;
    }
    return rtrim($title, ". ") . '. ' . $detail;
}

/** Разбор ответа модели в шаги с описанием: [{title, detail}]. */
function roadmap_parse_steps(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $m)) {
        $text = $m[1];
    } elseif (!preg_match('/^\s*\{/s', $text)) {
        if (preg_match('/(\{.*\})/s', $text, $m)) {
            $text = $m[1];
        }
    }
    $j = json_decode($text, true);
    $list = [];
    if (is_array($j) && isset($j['steps']) && is_array($j['steps'])) {
        $list = $j['steps'];
    } elseif (is_array($j) && array_is_list($j)) {
        $list = $j;
    }
    $out = [];
    foreach ($list as $s) {
        if (is_array($s)) {
            // одно поле: task (или title) — длинный текст задачи; detail — необязателен
            $title  = trim((string)($s['task'] ?? $s['title'] ?? ''));
            $detail = trim((string)($s['detail'] ?? ''));
            $goal   = trim((string)($s['goal'] ?? ''));
            $due    = trim((string)($s['due'] ?? ''));
            $hours  = max(0, min(720, (int)($s['hours'] ?? 0)));
        } else {
            $title  = trim((string)$s);
            $detail = '';
            $goal   = '';
            $due    = '';
            $hours  = 0;
        }
        if ($title !== '') {
            $out[] = ['title' => $title, 'detail' => $detail, 'goal' => $goal,
                      'due' => $due, 'hours' => $hours];
        }
    }
    return $out;
}

/* -------------------------------------------------------------- промт */

/** Контекст направления: описание проекта, цели, задачи, расходы. */
function roadmap_direction_context(int $projectId): string
{
    $p = node($projectId);
    if (!$p) {
        return '(проект не найден)';
    }
    $out = [];
    $out[] = 'Проект: ' . (string)$p['title'];
    if (!empty($p['server_ip'])) {
        $out[] = 'Сервер: ' . (string)$p['server_ip'];
    }
    if (!empty($p['summary'])) {
        $out[] = 'Описание: ' . mb_substr((string)$p['summary'], 0, 600);
    }
    if (!empty($p['criteria'])) {
        $out[] = 'Критерии: ' . mb_substr((string)$p['criteria'], 0, 600);
    }
    $tasks = all("SELECT id, title, status FROM projects
                  WHERE parent_id=? AND kind='task' ORDER BY id DESC LIMIT 40", 'i', [$projectId]);
    if ($tasks) {
        $out[] = '--- текущие задачи проекта ---';
        foreach ($tasks as $t) {
            $out[] = sprintf('[%d] %s — %s', (int)$t['id'], mb_substr((string)$t['title'], 0, 120),
                              (string)$t['status']);
        }
    } else {
        $out[] = 'Задач в проекте пока нет.';
    }
    return implode("\n", $out);
}

/** Журнал проекта (по событиям) — опционально. */
function roadmap_log_context(int $projectId, int $limit = 40): string
{
    $rows = all("SELECT ts, kind, text FROM events WHERE project_id=? ORDER BY id DESC LIMIT " . (int)$limit,
                'i', [$projectId]);
    if (!$rows) {
        return '(журнал пуст)';
    }
    $out = [];
    foreach (array_reverse($rows) as $r) {
        $text = trim(preg_replace('/\s+/', ' ', (string)($r['text'] ?? '')));
        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300) . '…';
        }
        $out[] = sprintf('%s [%s] %s', dt((string)$r['ts'], 'd.m H:i'), (string)$r['kind'], $text);
    }
    return implode("\n", $out);
}

/** Промт RoadMap: направление + задачи + переписка + (опционально) журнал. */
function roadmap_prompt(int $projectId, string $userText, bool $withLog, array $history): string
{
    $dir = roadmap_direction_context($projectId);
    $hist = '';
    foreach (array_reverse($history) as $m) {
        $role = (string)$m['role'] === 'user' ? 'Оператор' : 'MLL';
        $hist .= $role . ': ' . mb_substr(trim((string)$m['text']), 0, 1200) . "\n";
    }
    $logBlock = '';
    if ($withLog) {
        $logBlock = "\n=== ЖУРНАЛ ПРОЕКТА (что делали агенты) ===\n" . roadmap_log_context($projectId) . "\n";
    }
    $user = trim($userText);
    $userBlock = $user !== '' ? "\n=== ПОЖЕЛАНИЕ ОПЕРАТОРА ===\n{$user}\n" : '';
    return <<<PROMPT
Ты архитектор плана работ (RoadMap) по одному направлению. Построй или перестрой план задач.

=== НАПРАВЛЕНИЕ ===
{$dir}
{$userBlock}
{$logBlock}=== ПЕРЕПИСКА (контекст, держи его) ===
{$hist}

Дай ТОЛЬКО JSON без пояснений:
{"steps":[{"title":"КОРОТКИЙ ЗАГОЛОВОК"}]}

Правила:
1. Шаг — конкретное действие, которое агент выполнит, до 60 знаков, по-русски, с глагола, без воды.
2. 3–12 шагов, порядок по важности: сначала деньги, потом обслуживание.
3. Не повторяй текущие задачи без необходимости; если задача уже сделана — предложи следующий шаг.
4. Учитывай пожелание оператора и журнал (если он приложен).
5. Каждый ответ — новая версия RoadMap; оператор сам выберет, какую закрепить.

PROMPT;
}

/** Один шаг диалога: сообщение оператора → ответ MLL → новая версия. */
function roadmap_ask(int $projectId, string $userText, bool $withLog, string $model = ''): array
{
    $history = roadmap_chat($projectId, 20);
    if (trim($userText) !== '') {
        roadmap_chat_add($projectId, 'user', $userText);
        $history = roadmap_chat($projectId, 20);
    }
    if ($model === '') {
        $model = (string)(project_model($projectId) ?: '');
    }
    if ($model === '') {
        $models = mll_models();
        $model = $models ? (string)($models[0]['id'] ?? '') : '';
    }
    if ($model === '') {
        return ['ok' => false, 'error' => 'не выбрана модель'];
    }
    $prompt = roadmap_prompt($projectId, $userText, $withLog, $history);
    $res = or_chat($model, $prompt, 2000, 240);
    if (!$res['ok']) {
        roadmap_chat_add($projectId, 'mll', 'Ошибка: ' . (string)$res['error'], $model);
        return ['ok' => false, 'error' => (string)$res['error'], 'model' => $model];
    }
    $steps = roadmap_parse_steps((string)$res['text']);
    if (!$steps) {
        roadmap_chat_add($projectId, 'mll', (string)$res['text'], $model);
        return ['ok' => false, 'error' => 'не удалось разобрать план из ответа', 'model' => $model,
                'text' => (string)$res['text']];
    }
    $vid = roadmap_new_version($projectId, $steps, $model);
    $text = "RoadMap v" . (string)(roadmap_version($vid)['version'] ?? '?') . ":\n"
          . implode("\n", array_map(static fn($s, $i) => ($i + 1) . '. ' . $s['title']
              . (!empty($s['detail']) ? "\n   " . $s['detail'] : ''), $steps, array_keys($steps)));
    roadmap_chat_add($projectId, 'mll', $text, $model, $vid);
    return ['ok' => true, 'version_id' => $vid, 'steps' => $steps, 'model' => $model,
            'cost' => (float)$res['cost'], 'latency' => (int)$res['latency']];
}

/**
 * Ручная постановка следующей задачи: смотрит последнюю выполненную задачу,
 * её лог, RoadMap и ставит ровно 1 следующую задачу.
 * Вызывается оператором кнопкой "Следующая задача (ручная)".
 */
function roadmap_next_manual(int $projectId): array
{
    $pinned = roadmap_current($projectId);
    if (!$pinned) {
        return ['ok' => false, 'error' => 'Нет утверждённого RoadMap — сначала закрепи план'];
    }
    $steps = roadmap_steps($pinned);
    if (!$steps) {
        return ['ok' => false, 'error' => 'RoadMap пуст'];
    }

    // Найти последнюю завершённую задачу в этом направлении
    $lastDone = one("SELECT id, title, closed_at, result_note FROM projects
                     WHERE parent_id=? AND kind='task' AND status='done'
                     ORDER BY closed_at DESC LIMIT 1", 'i', [$projectId]);

    // Лог по последней задаче
    $taskLog = '';
    if ($lastDone) {
        $ev = all("SELECT ts, kind, level, title, text FROM events
                   WHERE task_id=? ORDER BY id DESC LIMIT 30", 'i', [(int)$lastDone['id']]);
        foreach ($ev as $e) {
            $taskLog .= '[' . dt($e['ts'], 'H:i:s') . '] ' . $e['kind'] . ' ' . $e['level'] . ': ' . ($e['title'] ?: $e['text']) . "\n";
        }
    }

    // Контекст: RoadMap + последняя задача + её лог + план дня
    $ctx = roadmap_context_view($projectId, '', true);
    $roadText = "RoadMap v" . (int)$pinned['version'] . ":\n" .
        implode("\n", array_map(static fn($s, $i) => ($i + 1) . '. ' . $s, $steps, array_keys($steps)));

    // Определить следующий шаг: найти первый невыполненный
    $nextStepIdx = 0;
    $doneTitles = [];
    if ($lastDone) {
        $allDone = all("SELECT title FROM projects WHERE parent_id=? AND kind='task' AND status='done'", 'i', [$projectId]);
        $doneTitles = array_column($allDone, 'title');
        // нормализуем
        $doneNorm = array_map(static fn($t) => trim(preg_replace('/\s+/u', ' ', $t)), $doneTitles);
        foreach ($steps as $i => $s) {
            $title = is_array($s) ? trim((string)($s['title'] ?? '')) : trim((string)$s);
            $norm = trim(preg_replace('/\s+/u', ' ', $title));
            $found = false;
            foreach ($doneNorm as $d) {
                if ($d === $norm || strpos($d, $norm) !== false || strpos($norm, $d) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $nextStepIdx = $i;
                break;
            }
        }
    }
    if ($nextStepIdx >= count($steps)) {
        return ['ok' => false, 'error' => 'Все шаги RoadMap выполнены'];
    }

    $nextStep = $steps[$nextStepIdx];
    $title = is_array($nextStep) ? trim((string)($nextStep['title'] ?? '')) : trim((string)$nextStep);
    $detail = is_array($nextStep) ? trim((string)($nextStep['detail'] ?? '')) : '';

    // Собрать промпт для MLL: он сгенерирует ТЗ для следующей задачи
    $lastDoneText = $lastDone
        ? '[' . (int)$lastDone['id'] . '] ' . $lastDone['title'] . "\nзакрыта: " . dt($lastDone['closed_at'], 'd.m H:i') . "\nразбор: " . mb_substr((string)$lastDone['result_note'], 0, 1000)
        : 'нет выполненных задач';
    $taskLogText = $taskLog ?: 'пусто';
    $detailText = $detail ? "Деталь: {$detail}" : '';
    $stepsCount = count($steps);
    $nextStepNum = $nextStepIdx + 1;

    $prompt = <<<PROMPT
Ты техлид. Оператор просит поставить СЛЕДУЮЩУЮ задачу.

=== КОНТЕКСТ НАПРАВЛЕНИЯ ===
{$ctx}

=== ROADMAP (утверждён) ===
{$roadText}

=== ПОСЛЕДНЯЯ ВЫПОЛНЕННАЯ ЗАДАЧА ===
{$lastDoneText}

=== ЛОГ ПО ПОСЛЕДНЕЙ ЗАДАЧЕ ===
{$taskLogText}

=== ЧТО НУЖНО ===
Следующий шаг по RoadMap: шаг {$nextStepNum} из {$stepsCount}
Текст шага: {$title}
{$detailText}

Сгенерируй ПОЛНОЕ ТЗ для агента (ОДНО ПОЛЕ — заголовок + описание):
- что сделать
- где именно (файлы/пути/ID кампаний/серверы)
- вход: что уже есть (результат прошлой задачи, данные лога)
- как проверить результат (конкретный критерий)
- с учётом RoadMap и плана дня

ВАЖНО: Ответ ТОЛЬКО JSON, задача 200-500 символов, без воды, по-русски, с глагола.

Ответь ТОЛЬКО JSON:
{"task":"ПОЛНЫЙ ТЕКСТ ЗАДАЧИ ДЛЯ АГЕНТА"}

PROMPT;

    // Вызов MLL: принудительно используем openai/gpt-6.1-terra-pro с лимитом токенов
    $model = 'openai/gpt-6.1-terra-pro';

    $res = mll_call($model, $prompt, 500);  // max_tokens = 500 для краткого ответа
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'MLL error: ' . (string)$res['error']];
    }

    // Разобрать ответ
    $text = trim((string)$res['text']);
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $m)) {
        $text = $m[1];
    } elseif (!preg_match('/^\s*\{/s', $text)) {
        if (preg_match('/(\{.*\})/s', $text, $m)) {
            $text = $m[1];
        }
    }
    $data = json_decode($text, true);
    if (!$data || !isset($data['task'])) {
        roadmap_chat_add($projectId, 'mll', 'Ошибка парсинга ручной задачи: ' . $text, $model);
        return ['ok' => false, 'error' => 'не удалось разобрать ответ модели: ' . mb_substr($text, 0, 200)];
    }

    $taskText = trim((string)$data['task']);
    if ($taskText === '') {
        return ['ok' => false, 'error' => 'пустая задача от модели'];
    }
    // Ограничение длины: 200-500 символов (в символах, не байтах)
    $len = mb_strlen($taskText);
    if ($len > 500) {
        $taskText = mb_substr($taskText, 0, 500) . '…';
    } elseif ($len < 200) {
        error_log("roadmap_next_manual: task too short ($len chars)");
    }

    // Создать задачу
    $proj = node($projectId);
    $cfg = dispatch_settings($projectId);
    $gapMin = dispatch_gap_minutes($projectId, $cfg);
    $slot = dispatch_slot(dispatch_now()->modify('+' . $gapMin . ' minutes'), $cfg, $projectId);

    $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0) FROM projects WHERE parent_id=?", 'i', [$projectId]) ?? 0) + 1;
    $nextId = (int)(scalar("SELECT COALESCE(MAX(id),0)+1 FROM projects") ?? 0);

    // Цель и срок из плана дня / этапа
    $planId = 0;
    $goal = '';
    $due = '';
    try {
        require_once __DIR__ . '/plan.php';
        $pl = plan_current($projectId);
        if ($pl) {
            $planId = (int)$pl['id'];
            $goal = (string)($pl['goal'] ?? '');
            $due = date('d.m.Y', strtotime((string)$pl['day']));
        }
    } catch (Throwable $e) {
    }
    if ($goal === '') {
        $goal = is_array($nextStep) ? trim((string)($nextStep['goal'] ?? '')) : '';
    }
    if ($due === '') {
        $hours = is_array($nextStep) ? (int)($nextStep['hours'] ?? 0) : 0;
        if ($hours > 0) {
            $due = '+' . $hours . ' ч';
        }
    }

    q("INSERT INTO projects (id, parent_id, kind, title, icon, status, prio, pos, criteria,
                             task_desc, agent_code, server_ip, ready_at, auto_chain, auto_step,
                             plan_id, goal, deadline, stage_goal)
       VALUES (?, ?, 'task', ?, '', 'planned', 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
      'iisissssssiisss',
      [$nextId, $projectId, $taskText, $pos,
       'источник: roadmap-next-manual\nшаг ' . ($nextStepIdx + 1) . '/' . count($steps),
       $detail !== '' ? $detail : null,
       (string)($proj['agent_code'] ?? ''), (string)($proj['server_ip'] ?? ''),
       $slot, 'roadmap-' . $projectId . '-v' . (int)$pinned['version'], $nextStepIdx + 1,
       $planId > 0 ? $planId : null, $goal !== '' ? $goal : null, $due,
       is_array($nextStep) ? trim((string)($nextStep['goal'] ?? '')) : '']);

    q("INSERT INTO events (project_id, task_id, kind, level, title, text)
       VALUES (?, ?, 'system', 'info', ?, ?)",
      'iiss', [$projectId, $nextId, 'ручная задача из RoadMap',
                $taskText . "\nвыдача: " . dispatch_human($slot)]);

    // Лог в чат RoadMap
    roadmap_chat_add($projectId, 'mll', "Оператор запросил следующую задачу вручную. Поставлена задача #{$nextId}: " . mb_substr($taskText, 0, 100), $model, (int)$pinned['id']);

    return ['ok' => true, 'task_id' => $nextId, 'ready_at' => $slot, 'step' => $nextStepIdx + 1, 'model' => $model, 'cost' => (float)$res['cost']];
}