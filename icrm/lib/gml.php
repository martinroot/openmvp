<?php
declare(strict_types=1);
/**
 * Глобальный MLL: чат главного управляющего + пояснения по направлениям.
 *
 *   global_chats — переписка (сохраняется, держит контекст);
 *   global_notes — пояснения по каждому направлению (по одному на проект).
 */
require_once __DIR__ . '/../lib/core.php';
require_once __DIR__ . '/../lib/models.php';
require_once __DIR__ . '/../lib/mll.php';
require_once __DIR__ . '/../lib/roadmap.php';

/* ------------------------------------------------------------------ чат */

function gml_chat(int $limit = 200): array
{
    return all("SELECT id, role, text, model, payload, created_at FROM global_chats
                ORDER BY id DESC LIMIT " . (int)$limit) ?: [];
}

function gml_chat_add(string $role, string $text, string $model = '', string $payload = ''): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    q("INSERT INTO global_chats (role, text, model, payload, created_at) VALUES (?,?,?,?,?)",
      'sssss', [$role, mb_substr($text, 0, 20000), $model, $payload, date('Y-m-d H:i:s')]);
    return (int)db()->insert_id;
}

function gml_chat_clear(): void
{
    q("DELETE FROM global_chats");
}

/* --------------------------------------------------------------- пояснения */

/** Пояснения по направлению (одна запись на проект). */
function gml_note(int $projectId): string
{
    $r = one("SELECT text FROM global_notes WHERE project_id=?", 'i', [$projectId]);
    return $r ? (string)$r['text'] : '';
}

function gml_note_set(int $projectId, string $text): void
{
    $text = trim($text);
    if ($text === '') {
        q("DELETE FROM global_notes WHERE project_id=?", 'i', [$projectId]);
        return;
    }
    q("INSERT INTO global_notes (project_id, text, updated_at) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE text=VALUES(text), updated_at=VALUES(updated_at)",
      'iss', [$projectId, mb_substr($text, 0, 4000), date('Y-m-d H:i:s')]);
}

/** Все пояснения: [project => текст] */
function gml_notes(): array
{
    $out = [];
    foreach (all("SELECT project_id, text FROM global_notes ORDER BY project_id") as $r) {
        $out[(int)$r['project_id']] = (string)$r['text'];
    }
    return $out;
}

/* ------------------------------------------------------- глубина истории */

/** Сколько последних сообщений лога видит управляющий по каждому направлению. */
function gml_log_depth(int $set = 0): int
{
    if ($set > 0) {
        $set = max(0, min(500, $set));
        models_setting_set('gml_log_depth', (string)$set);
        return $set;
    }
    $v = (int)models_setting('gml_log_depth', '30');
    return $v > 0 ? $v : 30;
}

/** Последние сообщения лога направления (включая подпроекты). */
function gml_log_lines(int $projectId, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }
    $scope = direction_scope_ids($projectId);
    if (!$scope) {
        $scope = [$projectId];
    }
    $ph = implode(',', array_fill(0, count($scope), '?'));
    $rows = all("SELECT e.id, e.ts, e.kind, e.level, e.title, e.text, e.task_id
                 FROM events e
                 WHERE e.project_id IN ($ph) AND e.kind IN ('agent','chat','system','note','file')
                 ORDER BY e.id DESC LIMIT " . (int)$limit,
                 str_repeat('i', count($scope)), $scope);
    return array_reverse($rows);
}

/** Что умеет система — советник должен знать свои инструменты. */
function gml_system_capabilities(): string
{
    return <<<TXT
ТРЕКЕР (этот трекер):
— проекты и подпроекты, задачи с планом, статусы, приоритеты, версии RoadMap (утверждённый план на проект);
— журнал активности по каждому чату и по каждой задаче (что делал агент, с ошибками и без), лента событий;
— учёт LLM-вызовов: токены (вход/выход/кэш/размышления), стоимость, длительность, модель, агент, сервер;
— раздел моделей: каталог OpenRouter, недельный объём, цены, качество, бенчмарк кодинга, тест, ротация, закреплённые модели;
— доход/расход по проектам, цена одной задачи, CPM/eCPM/ROI по рекламным кампаниям.

ГЕНЕРАЦИЯ ЗАДАЧ:
— MLL в карточке проекта: видит лог, текущие задачи и утверждённый RoadMap, выдаёт технические задачи (одно поле на задачу: что, где, вход, проверка);
— «Разложить RoadMap на технические задачи»: декомпозиция утверждённого плана по шагам, начиная с ближайшей следующей;
— конструктор RoadMap: переписка с MLL, 3–10 версий, утверждение и закрепление версии за проектом.

ГЛОБАЛЬНЫЙ MLL (этот чат): видит все направления, все утверждённые RoadMap, открытые и недавно закрытые задачи, пояснения оператора по направлениям, переписку.

API И АВТОМАТИЗАЦИЯ:
— агент может сам поставить задачу на фикс через POST /api/task_queue.php (задача попадает в дерево, её забирает сторож);
— периодические задачи (recurring): сторож сам отдаёт их агенту по расписанию;
— команды можно отправить агенту в чат (например сменить модель или провайдера) — сторож доставит в ближайшем цикле;
— чат-сторож на каждом направлении: один Telethon-процесс, одна сессия, направление определяется по chat_id.

ШЛЮЗЫ (Hermes-инстансы): на сервере каждого агента — свой глобальный MLL, локальные навыки, MCP-инструменты, доступ к файлам и командам на сервере агента, ключи OpenRouter и доступы к отчётности лежат там.

CRM (fapacrm): свои форки на доменах-хабах — заявки, клиенты, оплаты; доход считается там и сводится в трекер.

ОРГАНИЗАЦИЯ И ДЕЛЕГИРОВАНИЕ:
— каждое направление (проект) — отдельный бизнес-контур: свой чат, агент, сервер, домены, монетизация, CRM-форк;
— три уровня: L0 — ты, главный управляющий (приоритеты, капитализация, фокус); L1 — менеджер направления (MLL проекта, раскладывает утверждённый RoadMap на технические задачи); L2 — исполнитель (агент в чате, берёт задачи из плана сам);
— задачи из утверждённого RoadMap ставятся автоматически: план утверждён — шаги сами стали задачами, оператор не вмешивается;
— информационные RoadMap (сбор данных для следующего прохода) сохраняются черновиком и ждут утверждения оператора;
— в трекере видны сводные цифры (доход, расход, маржа, цель, цена задачи) и фактическая работа агентов; пояснение оператора по направлению — главный источник правды о CRM.

ТВОЙ ИНСТРУМЕНТ: ты не можешь сам выполнить работу — ты формируешь решения и задачи, а исполняют агенты через задачи в трекере.
TXT;
}

/* --------------------------------------------------------------- контекст */

/** Направления: список с расходом, задачами и закреплённым RoadMap. */
function gml_directions(): array
{
    $notes = gml_notes();
    $rows  = all("SELECT p.id, p.title, p.icon, p.server_ip, p.model, p.summary, p.income_usd,
                         p.agent_code, p.agent_chat_id, p.goal_amount, p.goal_date, p.auto_roadmap,
                         (SELECT COUNT(*) FROM projects t WHERE t.parent_id=p.id AND t.kind='task') tasks_all,
                         (SELECT COUNT(*) FROM projects t WHERE t.parent_id=p.id AND t.kind='task' AND t.status<>'done') tasks_open,
                         (SELECT COUNT(*) FROM projects t WHERE t.parent_id=p.id AND t.kind='task' AND t.status='done') tasks_done,
                         (SELECT COALESCE(SUM(c.cost_usd),0) FROM llm_calls c WHERE c.project_id=p.id) cost,
                         (SELECT COUNT(*) FROM llm_calls c WHERE c.project_id=p.id) calls,
                         (SELECT MAX(c.ts) FROM llm_calls c WHERE c.project_id=p.id) last_call,
                         (SELECT COUNT(*) FROM events e WHERE e.project_id=p.id AND e.level='error') errors
                  FROM projects p WHERE p.kind='project' ORDER BY p.id");
    foreach ($rows as &$r) {
        $r['note'] = $notes[(int)$r['id']] ?? '';
        $pin = roadmap_current((int)$r['id']);
        $r['roadmap'] = $pin ? roadmap_steps($pin) : [];
        $r['roadmap_version'] = $pin ? (int)$pin['version'] : 0;
        $r['recurring'] = all("SELECT id, every_min, title FROM recurring WHERE project_id=? ORDER BY id",
                               'i', [(int)$r['id']]);
    }
    return $rows;
}

/** Уровни делегирования: L1 — менеджеры направлений, L2 — исполнители (агенты). */
function gml_delegation_levels(): array
{
    $l1 = [];
    foreach (gml_directions() as $d) {
        $pid = (int)$d['id'];
        $l1[] = [
            'id'       => $pid,
            'title'    => (string)$d['icon'] . ' ' . (string)$d['title'],
            'roadmap'  => (int)$d['roadmap_version'],
            'drafts'   => (int)(scalar("SELECT COUNT(*) FROM roadmaps WHERE project_id=? AND status='draft'",
                                        'i', [$pid]) ?? 0),
            'planned'  => (int)(scalar("SELECT COUNT(*) FROM projects WHERE parent_id=? AND kind='task' AND status='planned'",
                                        'i', [$pid]) ?? 0),
            'active'   => (int)(scalar("SELECT COUNT(*) FROM projects WHERE parent_id=? AND kind='task' AND status='active'",
                                        'i', [$pid]) ?? 0),
            'done'     => (int)$d['tasks_done'],
            'cost'     => (float)$d['cost'],
            'auto'     => (int)($d['auto_roadmap'] ?? 0) === 1,
            'recurring' => count((array)$d['recurring']),
        ];
    }
    return $l1;
}

/** Полный контекст для глобального управляющего. */
function gml_context(array $history = [], string $userText = '', bool $withChat = true): string
{
    $dirs = gml_directions();
    $out  = [];

    $out[] = '=== ВОЗМОЖНОСТИ СИСТЕМЫ (твои инструменты) ===';
    $out[] = gml_system_capabilities();

    $out[] = '=== НАПРАВЛЕНИЯ, ОРГАНИЗАЦИЯ И ДЕНЬГИ ===';
    foreach ($dirs as $d) {
        $line = sprintf('- %s%s [%s]: задач всего %d, открытых %d, закрытых %d, вызовов %d, расход $%.4f',
            (string)$d['icon'] ? (string)$d['icon'] . ' ' : '', (string)$d['title'], (string)$d['id'],
            (int)$d['tasks_all'], (int)$d['tasks_open'], (int)$d['tasks_done'],
            (int)$d['calls'], (float)$d['cost']);
        if (isset($d['income']) && (float)$d['income'] > 0) {
            $line .= sprintf(', доход $%.4f, маржа $%.4f',
                (float)$d['income'], (float)$d['income'] - (float)$d['cost']);
        }
        if (!empty($d['last_call'])) {
            $line .= ', последний вызов ' . dt((string)$d['last_call'], 'd.m H:i');
        }
        if (!empty($d['errors'])) {
            $line .= ', ошибок в логе: ' . (int)$d['errors'];
        }
        if (!empty($d['model'])) {
            $line .= ', модель ' . (string)$d['model'];
        }
        if (!empty($d['goal_amount'])) {
            $line .= sprintf(', цель $%.2f%s', (float)$d['goal_amount'],
                !empty($d['goal_date']) ? ' к ' . dt((string)$d['goal_date'], 'd.m.Y') : '');
        }
        if (!empty($d['server_ip'])) {
            $line .= ', сервер ' . (string)$d['server_ip'];
        }
        $line .= ', авто-постановка из RoadMap: ' . ((int)($d['auto_roadmap'] ?? 0) === 1 ? 'вкл' : 'выкл');
        $out[] = $line;
        if (!empty($d['recurring'])) {
            foreach ($d['recurring'] as $rc) {
                $out[] = '    повторяющаяся задача: каждые ' . (int)$rc['every_min'] . ' мин — ' . (string)$rc['title'];
            }
        }
        if (!empty($d['roadmap'])) {
            $out[] = '  утверждённый RoadMap v' . (int)$d['roadmap_version'] . ':';
            foreach ($d['roadmap'] as $i => $s) {
                $out[] = '    ' . ($i + 1) . '. ' . $s;
            }
        }
        if (!empty($d['note'])) {
            $out[] = '  пояснения оператора: ' . str_replace("\n", ' / ', (string)$d['note']);
        }
    }

    $depth = gml_log_depth();
    if ($depth > 0) {
        $out[] = '=== ЛОГ НАПРАВЛЕНИЙ (последние ' . (int)$depth . ' сообщений по каждому) ===';
        foreach ($dirs as $d) {
            $lines = gml_log_lines((int)$d['id'], $depth);
            if (!$lines) {
                continue;
            }
            $out[] = '  --- ' . (string)$d['icon'] . ' ' . (string)$d['title'] . ' ---';
            foreach ($lines as $l) {
                $txt = trim(preg_replace('/\s+/u', ' ', (string)($l['text'] ?? '')));
                if ($txt === '') {
                    $txt = trim((string)($l['title'] ?? ''));
                }
                if (mb_strlen($txt) > 260) {
                    $txt = mb_substr($txt, 0, 260) . '…';
                }
                $out[] = sprintf('    %s [%s%s] %s', dt((string)$l['ts'], 'd.m H:i'), (string)$l['kind'],
                    !empty($l['task_id']) ? ' #' . (int)$l['task_id'] : '', $txt);
            }
        }
    }

    // ПЛАН НА СЕГОДНЯ + РАЗБОРЫ РЕЗУЛЬТАТА
    $plans = [];
    try {
        require_once __DIR__ . '/plan.php';
        $plans = plan_for_day();
    } catch (Throwable $e) {
        $plans = [];
    }
    if ($plans) {
        $out[] = '=== ПЛАН НА СЕГОДНЯ (зафиксирован) ===';
        foreach ($plans as $pl) {
            $out[] = sprintf('  %s%s: цель — %s%s%s',
                (string)$pl['proj_icon'] ? (string)$pl['proj_icon'] . ' ' : '',
                (string)$pl['proj_title'], (string)$pl['goal'],
                (string)$pl['metric'] !== '' ? ' | метрика: ' . (string)$pl['metric'] : '',
                (string)$pl['first_move'] !== '' ? ' | первое действие: ' . (string)$pl['first_move'] : '');
            $out[] = sprintf('      задач открыто %d, закрыто %d',
                (int)$pl['tasks_open'], (int)$pl['tasks_done']);
        }
    }
    $reviews = all("SELECT t.id, t.title, t.closed_at, t.result_note, p.title proj
                    FROM projects t JOIN projects p ON p.id=t.parent_id
                    WHERE t.kind='task' AND t.status='done' AND t.result_note IS NOT NULL
                    ORDER BY t.closed_at DESC LIMIT 12");
    if ($reviews) {
        $out[] = '=== РАЗБОР ПОСЛЕДНИХ ВЫПОЛНЕНИЙ (цель vs результат) ===';
        foreach ($reviews as $rv) {
            $out[] = '  [' . (int)$rv['id'] . '] ' . (string)$rv['proj'] . ': '
                     . str_replace("\n", ' / ', mb_substr((string)$rv['result_note'], 0, 600));
        }
    }

    $out[] = '=== УРОВНИ ДЕЛЕГИРОВАНИЯ ===';
    $out[] = '  L0 — ты (глобальный управляющий): приоритеты, капитализация, фокус, отвержение слабых планов.';
    $out[] = '  L1 — менеджер направления (MLL проекта): по утверждённому RoadMap раскладывает работу на технические задачи;';
    $out[] = '  L2 — исполнитель (агент в чате): берёт задачи из плана сам и отчитывается токенами.';
    foreach (gml_delegation_levels() as $l) {
        $out[] = sprintf('    L1/L2 %s: RoadMap v%d, черновиков %d, задач в плане %d, в работе %d, закрыто %d, recurring %d, расход $%.4f',
            (string)$l['title'], (int)$l['roadmap'], (int)$l['drafts'], (int)$l['planned'],
            (int)$l['active'], (int)$l['done'], (int)$l['recurring'], (float)$l['cost']);
    }

    $open = all("SELECT p.title proj, t.id, t.title FROM projects t JOIN projects p ON p.id=t.parent_id
                 WHERE t.kind='task' AND t.status<>'done' ORDER BY p.title, t.id LIMIT 60");
    $out[] = '=== ОТКРЫТЫЕ ЗАДАЧИ (все направления) ===';
    if ($open) {
        foreach ($open as $t) {
            $out[] = sprintf('  [%d] %s — %s', (int)$t['id'], mb_substr((string)$t['title'], 0, 120),
                             (string)$t['proj']);
        }
    } else {
        $out[] = '  (открытых задач нет)';
    }

    $closed = all("SELECT p.title proj, t.id, t.title, t.updated_at FROM projects t JOIN projects p ON p.id=t.parent_id
                   WHERE t.kind='task' AND t.status='done' ORDER BY t.updated_at DESC LIMIT 30");
    $out[] = '=== НЕДАВНО ЗАКРЫТЫЕ ЗАДАЧИ ===';
    if ($closed) {
        foreach ($closed as $t) {
            $out[] = sprintf('  [%d] %s — %s (%s)', (int)$t['id'], mb_substr((string)$t['title'], 0, 110),
                             (string)$t['proj'], dt((string)$t['updated_at'], 'd.m H:i'));
        }
    } else {
        $out[] = '  (нет)';
    }

    if ($history && $withChat) {
        $out[] = '=== ПЕРЕПИСКА ===';
        foreach (array_reverse($history) as $m) {
            $role = (string)$m['role'] === 'user' ? 'Оператор' : 'Глобальный MLL';
            $out[] = $role . ': ' . mb_substr(trim((string)$m['text']), 0, 900);
        }
    }
    if (trim($userText) !== '') {
        $out[] = '=== ВОПРОС ОПЕРАТОРА ===' . "\n" . trim($userText);
    }
    return implode("\n", $out);
}

/** Промпт глобального советника: организационная оценка, а не пересказ задач. */
function gml_prompt(string $userText, array $history): string
{
    return <<<PROMPT
Ты — главный управляющий и организационный советник сети направлений. Не исполнитель, не пересказчик задач.
Ты не соглашаешься из вежливости. Если план слабый, бессмысленный или упирается в узкое место — говори прямо и отвергай его.

__CTX__

ТВОЯ РОЛЬ:
1. ASSESSMENT — 2–5 предложений: что сейчас реально происходит с сетью, где главный риск или упущенная деньга.
2. LEVERAGE — 3–6 организационно эффективных моментов: что даст непропорционально большой эффект (деньги, автоматизация, снятие ручного труда, снятие зависимости от одного канала/сервера/модели, переиспользование уже сделанного).
3. REJECTS — 1–4 пункта: что в текущих планах/задачах ты отвергаешь и почему (не пересказывать задачу, а объяснить, почему это не та работа).
4. QUESTIONS — 2–4 вопроса оператору: только те, ответ на которые меняет приоритет или деньги в ближайшие 1–4 недели. Короткие, по делу.
5. STEPS — 0–5 конкретных действий, только если без действия совет невыполним. Каждый action — полный текст задачи для агента (одно поле: что сделать, где именно, вход, как проверить), до 400 знаков, по-русски.
6. ROADMAPS — 0–3 дополнительных RoadMap, которые ты считаешь уместными СЕГОДНЯ: по одному на направление, где нужен новый план.
   Формат: [{"project": <числовой id направления из контекста>, "title": "заголовок плана", "why": "зачем этот план сейчас и почему он лучше текущего (1–2 предложения)", "steps": ["шаг 1","шаг 2","шаг 3"]}]
   Требования: 3–8 шагов, каждый шаг — одно поле (что сделать, где, вход, проверка), до 400 знаков; шаги идут в порядке выполнения и опираются на текущие наработки; не дублируй текущий утверждённый RoadMap, а предлагай сдвиг.
   У каждого шага укажи goal (чего добиться этим шагом) и hours (через сколько часов от начала этапа можно браться за шаг; 0 = сразу) — этапы идут во времени.
7. TODAY — ПЛАН НА СЕГОДНЯ по 1–3 направлениям, где ты ставишь цель дня.
   Формат: [{"project": <числовой id направления>, "goal": "чего добиться сегодня (1 предложение, измеримо)", "metric": "как поймём, что добились (цифра/факт)", "why": "почему это сегодня и что выигрываем", "first_move": "первое действие (что сделать в первую очередь)"}]
   Требования: план дня конкретный, измеримый, не «поработать над X» — а «добиться, чтобы X», плюс первое действие, с которого начинаем.
   СВЯЗКА: план дня без утверждённого RoadMap не работает — задачи ставятся из RoadMap.
   Если у направления из твоего TODAY нет утверждённого RoadMap (в контексте это видно), обязательно добавь
   для него RoadMap в секцию 6 — иначе цепочка «план дня → задача → выполнение → разбор» не пойдёт.
   Первое действие (first_move) должно быть первым шагом этого RoadMap.
8. INFO_ROADMAPS — 0–2 информационных RoadMap: план СБОРА ДАННЫХ для следующего прохода (аудит, свод, замер, аналитика).
   Формат: [{"project": <числовой id направления>, "title": "…", "why": "зачем эти данные и что с ними будет", "steps": ["…"]}]
   Этот план НЕ выполняется сразу: он сохраняется черновиком и ждёт утверждения оператора. Шаги — про данные и отчёт, без денег и без кода.

ПРАВИЛА:
— Не пересказывай задачи и логи. Если нечего сказать по сути — не выдумывай.
— Никаких выдуманных цифр: в ответе только числа из контекста. Не хватает данных — задай вопрос.
— Не предлагай то, что уже сделано или прямо сейчас в работе.
— Каждый пункт leverage — про организацию и деньги, а не про код.
— Думай категориями организации и CRM: воронка и конверсия, повторные продажи, стоимость привлечения, маржа по направлениям, кто что делает в сети (L0/L1/L2), где ручной труд съедает прибыль.
— Развилки, где нужен выбор оператора, — в questions, а не в steps.
— Приоритет по деньгам: сначала то, что приносит или сохраняет деньги, потом рост, потом обслуживание.
— Учитывай пояснения оператора по направлениям — они высший приоритет.

ФОРМАТ ОТВЕТА — только JSON, без пояснений:
{"assessment":"…","leverage":["…","…"],"rejects":["…"],"questions":["…"],"steps":[{"task":"…"}],"today":[{"project":1,"goal":"…","metric":"…","why":"…","first_move":"…"}],"roadmaps":[{"project":1,"title":"…","why":"…","steps":["…"]}],"info_roadmaps":[{"project":1,"title":"…","why":"…","steps":["…"]}]}

PROMPT;
}


/**
 * Собирает messages для настоящего диалога: первый ход — контекст и правила,
 * дальше переписка ролями (Оператор → user, Глобальный MLL → assistant),
 * последним ходом — текущий вопрос оператора.
 */
function gml_messages(array $history, string $userText = ''): array
{
    $ctx  = gml_context($history, '', false); // переписка пойдёт настоящими сообщениями
    $rules = gml_prompt($userText, []);        // шаблон промта с маркером __CTX__
    $rules = str_replace('__CTX__', '', $rules);
    $rules = trim(preg_replace('/\n{3,}/', "\n\n", $rules));

    $msgs = [['role' => 'user', 'content' => $ctx . "\n\n" . $rules]];

    // последние реплики: оператор и советник по очереди (gml_chat отдаёт от новых к старым)
    $rows = array_reverse(array_slice($history, 0, 16));
    // текущий вопрос оператора — последняя user-реплика, её вынесем в отдельный ход
    $lastUserIdx = -1;
    foreach ($rows as $i => $m) {
        if ((string)$m['role'] === 'user') {
            $lastUserIdx = $i;
        }
    }
    $queued = [];
    foreach ($rows as $i => $m) {
        $role = ((string)$m['role'] === 'user') ? 'user' : 'assistant';
        $txt  = trim((string)$m['text']);
        if ($txt === '') {
            continue;
        }
        // текущий вопрос оператора пойдёт последним отдельным ходом
        if ($i === $lastUserIdx && trim($userText) !== '' && $txt === trim($userText)) {
            continue;
        }
        if (mb_strlen($txt) > 2600) {
            $txt = mb_substr($txt, 0, 2600) . '…';
        }
        $queued[] = ['role' => $role, 'content' => $txt];
    }
    // не оставляем два assistant подряд и не начинаем с assistant
    $clean = [];
    foreach ($queued as $q) {
        if ($q['role'] === 'assistant' && (!$clean || end($clean)['role'] === 'assistant')) {
            continue;
        }
        $clean[] = $q;
    }
    foreach ($clean as $q) {
        $msgs[] = $q;
    }
    if (trim($userText) !== '') {
        $msgs[] = ['role' => 'user', 'content' => trim($userText)];
    }
    if (!$msgs || end($msgs)['role'] !== 'user') {
        $msgs[] = ['role' => 'user', 'content' => 'Продолжай.'];
    }
    return $msgs;
}

/** Один шаг диалога: совет + вопросы + (по желанию) конкретные шаги. */
function gml_ask(string $userText, string $model = ''): array
{
    $history = gml_chat(24);
    if (trim($userText) !== '') {
        gml_chat_add('user', $userText);
        $history = gml_chat(24);
    }
    if ($model === '') {
        $models = mll_models();
        $model  = $models ? (string)($models[0]['id'] ?? '') : '';
    }
    if ($model === '') {
        return ['ok' => false, 'error' => 'нет доступных моделей'];
    }
    // настоящий диалог: контекст + правила первым ходом, переписка — ролями
    $msgs   = gml_messages($history, $userText);
    $prompt = str_replace('__CTX__', 'см. первый ход диалога', gml_prompt($userText, []));
    // контекст большой — модели-«размышляющие» съедают лимит, поэтому даём запас и просим отвечать сразу
    $res = or_chat_msgs($model, $msgs, 6000, 180, ['reasoning' => ['effort' => 'low']]);
    if (!$res['ok']) {
        gml_chat_add('mll', 'Ошибка: ' . (string)$res['error'], $model);
        return ['ok' => false, 'error' => (string)$res['error'], 'model' => $model];
    }
    $raw = trim((string)$res['text']);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // модель могла обернуть JSON в текст — вытащим первый объект
        if (preg_match('/\{.*\}/s', $raw, $mm)) {
            $data = json_decode($mm[0], true);
        }
    }
    if (!is_array($data)) {
        // ответ оборвался (лимит ушёл на размышления) — повторяем с увеличенным лимитом
        $msgs2 = $msgs;
        $msgs2[] = ['role' => 'user', 'content' => 'Внимание: предыдущий ответ оборвался. Дай ПОЛНЫЙ JSON целиком, начни сразу с символа { и закончи }.'];
        $res2 = or_chat_msgs($model, $msgs2, 14000, 180, ['reasoning' => ['effort' => 'low']]);
        if ($res2['ok']) {
            $raw2 = trim((string)$res2['text']);
            $d2 = json_decode($raw2, true);
            if (!is_array($d2) && preg_match('/\{.*\}/s', $raw2, $mm2)) {
                $d2 = json_decode($mm2[0], true);
            }
            if (is_array($d2)) {
                $data = $d2;
                $raw  = $raw2;
                $res  = $res2;
            }
        }
    }
    if (!is_array($data)) {
        $msg = $raw !== '' ? $raw : ('Модель вернула пустой ответ (finish: ' . (string)($res['finish'] ?? '?') . '). Размышления: '
              . mb_substr(trim((string)($res['reasoning'] ?? '')), 0, 400));
        gml_chat_add('mll', $msg, $model);
        return ['ok' => false, 'error' => 'не удалось разобрать ответ', 'model' => $model, 'text' => $msg];
    }

    $assessment = trim((string)($data['assessment'] ?? ''));
    $leverage   = [];
    foreach ((array)($data['leverage'] ?? []) as $l) {
        $l = trim((string)$l);
        if ($l !== '') {
            $leverage[] = $l;
        }
    }
    $rejects = [];
    foreach ((array)($data['rejects'] ?? []) as $l) {
        $l = trim((string)$l);
        if ($l !== '') {
            $rejects[] = $l;
        }
    }
    $questions = [];
    foreach ((array)($data['questions'] ?? []) as $l) {
        $l = trim((string)$l);
        if ($l !== '') {
            $questions[] = $l;
        }
    }
    $steps = [];
    foreach ((array)($data['steps'] ?? []) as $s) {
        if (is_array($s)) {
            $t = trim((string)($s['task'] ?? ($s['title'] ?? '')));
        } else {
            $t = trim((string)$s);
        }
        if ($t !== '') {
            $steps[] = ['title' => $t, 'detail' => ''];
        }
    }

    $roadmaps = [];
    foreach ((array)($data['roadmaps'] ?? []) as $rm) {
        if (!is_array($rm)) {
            continue;
        }
        $pid = (int)($rm['project'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $rsteps = [];
        foreach ((array)($rm['steps'] ?? []) as $s) {
            if (is_array($s)) {
                $t = trim((string)($s['task'] ?? ($s['title'] ?? '')));
                $stg = ['task' => $t];
                // цель этапа и срок — чтобы этапы шли во времени
                $stg['goal']  = trim((string)($s['goal'] ?? ''));
                $stg['hours'] = max(0, (int)($s['hours'] ?? 0));
                $stg['due']   = trim((string)($s['due'] ?? ''));
            } else {
                $t = trim((string)$s);
                $stg = ['task' => $t, 'goal' => '', 'hours' => 0, 'due' => ''];
            }
            if ($t !== '') {
                $rsteps[] = $stg;
            }
        }
        if (!$rsteps) {
            continue;
        }
        $roadmaps[] = [
            'project' => $pid,
            'title'   => trim((string)($rm['title'] ?? '')),
            'why'     => trim((string)($rm['why'] ?? '')),
            'steps'   => $rsteps,
        ];
    }

    $todayPlan = [];
    foreach ((array)($data['today'] ?? []) as $tp) {
        if (!is_array($tp)) {
            continue;
        }
        $pid  = (int)($tp['project'] ?? 0);
        $goal = trim((string)($tp['goal'] ?? ''));
        if ($pid <= 0 || $goal === '') {
            continue;
        }
        $todayPlan[] = [
            'project'   => $pid,
            'goal'      => $goal,
            'metric'    => trim((string)($tp['metric'] ?? '')),
            'why'       => trim((string)($tp['why'] ?? '')),
            'first_move' => trim((string)($tp['first_move'] ?? '')),
        ];
    }

    $infoRoadmaps = [];
    foreach ((array)($data['info_roadmaps'] ?? []) as $rm) {
        if (!is_array($rm)) {
            continue;
        }
        $pid = (int)($rm['project'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $isteps = [];
        foreach ((array)($rm['steps'] ?? []) as $s) {
            $t = is_array($s) ? trim((string)($s['task'] ?? ($s['title'] ?? ''))) : trim((string)$s);
            if ($t !== '') {
                $isteps[] = $t;
            }
        }
        if (!$isteps) {
            continue;
        }
        $infoRoadmaps[] = [
            'project' => $pid,
            'title'   => trim((string)($rm['title'] ?? '')),
            'why'     => trim((string)($rm['why'] ?? '')),
            'steps'   => $isteps,
        ];
    }

    $text = '';
    if ($assessment !== '') {
        $text .= "ОЦЕНКА\n" . $assessment . "\n\n";
    }
    if ($leverage) {
        $text .= "ЧТО ДАСТ ЭФФЕКТ\n" . implode("\n", array_map(static fn($x) => '• ' . $x, $leverage)) . "\n\n";
    }
    if ($rejects) {
        $text .= "ОТВЕРГАЮ\n" . implode("\n", array_map(static fn($x) => '✕ ' . $x, $rejects)) . "\n\n";
    }
    if ($questions) {
        $text .= "ВОПРОСЫ К ТЕБЕ\n" . implode("\n", array_map(static fn($x) => '? ' . $x, $questions)) . "\n\n";
    }
    if ($steps) {
        $text .= "ДЕЙСТВИЯ\n" . implode("\n", array_map(static fn($s, $i) => ($i + 1) . '. ' . $s['title'], $steps, array_keys($steps))) . "\n\n";
    }
    if ($todayPlan) {
        $text .= "ПЛАН НА СЕГОДНЯ\n";
        foreach ($todayPlan as $i => $tp) {
            $text .= ($i + 1) . ') направление #' . (int)$tp['project'] . ' — цель: ' . $tp['goal'] . "\n";
            if ($tp['metric'] !== '') {
                $text .= '   метрика: ' . $tp['metric'] . "\n";
            }
            if ($tp['first_move'] !== '') {
                $text .= '   первое действие: ' . $tp['first_move'] . "\n";
            }
        }
    }
    if ($infoRoadmaps) {
        $text .= "ИНФОРМАЦИОННЫЕ ROADMAP (сбор данных, ждут утверждения)\n";
        foreach ($infoRoadmaps as $i => $rm) {
            $text .= ($i + 1) . ') направление #' . (int)$rm['project'] . ' — ' . (string)$rm['title'] . "\n";
            if ($rm['why'] !== '') {
                $text .= '   зачем: ' . $rm['why'] . "\n";
            }
            foreach ($rm['steps'] as $j => $st) {
                $text .= '   ' . ($j + 1) . '. ' . $st . "\n";
            }
        }
    }
    if ($roadmaps) {
        $text .= "ПРЕДЛАГАЕМЫЕ ROADMAP\n";
        foreach ($roadmaps as $i => $rm) {
            $text .= ($i + 1) . ') направление #' . (int)$rm['project'] . ' — ' . (string)$rm['title'] . "\n";
            if ($rm['why'] !== '') {
                $text .= '   зачем: ' . $rm['why'] . "\n";
            }
            foreach ($rm['steps'] as $j => $st) {
                // шаг может быть строкой или {task, goal, hours}
                $line = is_array($st) ? (string)($st['task'] ?? ($st['title'] ?? '')) : (string)$st;
                $text .= '   ' . ($j + 1) . '. ' . $line . "\n";
                if (is_array($st)) {
                    if (trim((string)($st['goal'] ?? '')) !== '') {
                        $text .= '      цель этапа: ' . trim((string)$st['goal']) . "\n";
                    }
                    if ((int)($st['hours'] ?? 0) > 0) {
                        $text .= '      срок: через ' . (int)$st['hours'] . " ч от начала этапа\n";
                    }
                }
            }
        }
    }
    if ($text === '') {
        $text = 'Ответ без структуры: ' . mb_substr($raw, 0, 800);
    }
    $payload = json_encode([
        'assessment' => $assessment,
        'leverage'   => $leverage,
        'rejects'    => $rejects,
        'questions'  => $questions,
        'steps'      => array_column($steps, 'title'),
        'roadmaps'      => $roadmaps,
        'info_roadmaps' => $infoRoadmaps,
        'today'         => $todayPlan,
    ], JSON_UNESCAPED_UNICODE);
    gml_chat_add('mll', $text, $model, (string)$payload);
    return ['ok' => true, 'steps' => $steps, 'model' => $model,
            'assessment' => $assessment, 'leverage' => $leverage, 'rejects' => $rejects,
            'questions' => $questions, 'roadmaps' => $roadmaps, 'info_roadmaps' => $infoRoadmaps,
            'today' => $todayPlan,
            'cost' => (float)$res['cost'], 'latency' => (int)$res['latency']];
}
