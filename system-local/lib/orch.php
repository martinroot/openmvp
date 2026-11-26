<?php
declare(strict_types=1);
/**
 * Простой MLL-оркестратор.
 *
 * Есть агент-исполнитель (Hermes, работает по задачам из дерева) и есть
 * оркестратор — лёгкий цикл, который раз в N минут «долбит цель»:
 *   1) берёт цель + свою компактную память (дорожную карту) с прошлого шага,
 *   2) добирает ПОСЛЕДНИЕ 3-4 ответа агента и новые события с прошлого тика,
 *   3) пересобирает дорожную карту и решает, что агенту делать дальше,
 *   4) пишет решение + учёт вызова в orch_log (и llm_calls для сводки).
 * Историю целиком оркестратор не тащит — только своё состояние и хвост.
 */
require_once __DIR__ . '/core.php';

const ORCH_DEFAULT_MODEL = 'google/gemini-2.5-flash';

/** Настройки оркестратора проекта (или null). */
function orch_get(int $projectId): ?array
{
    try {
        return one("SELECT * FROM orch WHERE project_id=?", 'i', [$projectId]);
    } catch (Throwable $e) {
        return null;
    }
}

/** Сохранить настройки (создаёт таблицу при первом обращении). */
function orch_save(int $projectId, string $goal, string $model, int $intervalMin, int $maxIter, int $enabled): void
{
    db()->query("CREATE TABLE IF NOT EXISTS orch (
        project_id INT NOT NULL PRIMARY KEY,
        goal TEXT NULL, model VARCHAR(96) NOT NULL DEFAULT 'google/gemini-2.5-flash',
        interval_min INT NOT NULL DEFAULT 30, max_iter INT NOT NULL DEFAULT 0,
        enabled TINYINT NOT NULL DEFAULT 0, iter_count INT NOT NULL DEFAULT 0,
        last_run_at DATETIME NULL, state MEDIUMTEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = db()->prepare("INSERT INTO orch (project_id, goal, model, interval_min, max_iter, enabled)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE goal=VALUES(goal), model=VALUES(model),
        interval_min=VALUES(interval_min), max_iter=VALUES(max_iter), enabled=VALUES(enabled)");
    $st->bind_param('issiii', $projectId, $goal, $model, $intervalMin, $maxIter, $enabled);
    $st->execute();
}

/** Последние ответы агента (результат взаимодействия). */
function orch_agent_answers(int $projectId, int $limit = 4): array
{
    try {
        return all("SELECT id, ts, LEFT(text, 900) text FROM events
                     WHERE project_id=? AND kind='agent' AND text IS NOT NULL AND text <> ''
                     ORDER BY id DESC LIMIT " . (int)$limit, 'i', [$projectId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Новые события (триггеры) с прошлого тика. */
function orch_new_events(int $projectId, string $since): array
{
    try {
        return all("SELECT id, kind, ts, LEFT(title,120) title, LEFT(IFNULL(text,''),300) text
                     FROM events WHERE project_id=? AND ts > ? ORDER BY id DESC LIMIT 25",
                    'is', [$projectId, $since]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Один вызов OpenRouter. Возвращает [text, tok_in, tok_out, cost, ms, err]. */
function orch_call(string $model, string $prompt): array
{
    $key = '';
    foreach (['/var/www/wifepornhub.ru/icrm/var/openrouter_key', '/home/main/.hermes/.env'] as $kf) {
        if (is_file($kf) && is_readable($kf)) {
            foreach (file($kf, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^OPENROUTER_API_KEY=(.+)$/', $line, $m)) { $key = trim($m[1]); break; }
            }
            if ($key !== '') break;
        }
    }
    if ($key === '') return ['', 0, 0, 0.0, 0, 'нет OPENROUTER_API_KEY'];

    $t0 = microtime(true);
    $body = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 8000,
    ]);
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: https://wifepornhub.ru',
            'X-Title: icrm-orch',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($code !== 200 || !is_string($raw)) return ['', 0, 0, 0.0, $ms, "HTTP $code"];

    $j = json_decode($raw, true);
    if (!isset($j['choices'][0]['message']['content'])) return ['', 0, 0, 0.0, $ms, 'bad response'];
    $tokIn = (int)($j['usage']['prompt_tokens'] ?? 0);
    $tokOut = (int)($j['usage']['completion_tokens'] ?? 0);
    $cost = (float)($j['usage']['cost'] ?? 0);
    return [(string)$j['choices'][0]['message']['content'], $tokIn, $tokOut, $cost, $ms, ''];
}

/** Вытянуть JSON из ответа модели. */
function orch_parse_json(string $text): array
{
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $j = json_decode($m[0], true);
        if (is_array($j)) return $j;
    }
    return [];
}

/** Один тик оркестратора по проекту. Возвращает id лога или 0. */
function orch_tick(int $projectId): int
{
    $o = orch_get($projectId);
    if (!$o || !(int)$o['enabled']) return 0;
    $interval = max(5, (int)($o['interval_min'] ?: 30));
    if ($o['last_run_at']) {
        $next = strtotime($o['last_run_at'] . ' UTC') + $interval * 60;
        if (time() < $next) return 0;
    }
    $maxIter = (int)($o['max_iter'] ?: 0);
    if ($maxIter > 0 && (int)$o['iter_count'] >= $maxIter) return 0;

    $model = ($o['model'] ?: ORCH_DEFAULT_MODEL);
    $goal = trim((string)$o['goal']);
    $state = trim((string)$o['state']);

    $answers = orch_agent_answers($projectId, 4);
    $since = $o['last_run_at'] ?: '1970-01-01 00:00:00';
    $events = orch_new_events($projectId, $since);

    $tail = '';
    foreach ($answers as $a) {
        $tail .= "--- ответ агента ({$a['ts']}):\n{$a['text']}\n";
    }
    $evTxt = '';
    foreach ($events as $e) {
        $evTxt .= "({$e['ts']}) [{$e['kind']}] {$e['title']}";
        if (trim((string)$e['text']) !== '') $evTxt .= ' :: ' . trim((string)$e['text']);
        $evTxt .= "\n";
    }

    $prompt = "ТЫ — ОРКЕСТРАТОР (менеджер-аналитик). Твоё место: ты НЕ исполняешь и НЕ делаешь ничего руками.\n"
        . "Всё исполнение делает АГЕНТ-ИСПОЛНИТЕЛЬ — он работает на сервере по задачам, которые ТЫ выдаёшь ему брифом.\n"
        . "Ты не пишешь абстракции вроде «перезапустить цикл», «подумать», «следить» — это мусор.\n"
        . "Ты формулируешь для агента КОНКРЕТНУЮ техническую задачу: что проверить/изменить/запустить, на каком домене/кампании,\n"
        . "с какими числами, с каким ожидаемым результатом. Ты и только ты следишь за целью и памятью проекта.\n\n"
        . "ЦЕЛЬ ПРОЕКТА:\n{$goal}\n\n"
        . "ТЕКУЩАЯ ДОРОЖНАЯ КАРТА / ПАМЯТЬ (может быть пустой на первом шаге):\n"
        . ($state !== '' ? $state : '(пусто)') . "\n\n"
        . "ПОСЛЕДНИЕ ОТВЕТЫ АГЕНТА-ИСПОЛНИТЕЛЯ (там его отчёты о работе и данные):\n" . ($tail !== '' ? $tail : '(пока нет)') . "\n"
        . "НОВЫЕ СОБЫТИЯ/ТРИГГЕРЫ с прошлого запуска:\n" . ($evTxt !== '' ? $evTxt : '(нет)') . "\n\n"
        . "Что делать:\n"
        . "1) Найди новые триггеры/информацию в ответах и событиях, сверь с целью.\n"
        . "2) Пересобери дорожную карту с нуля (компактно, БЕЗ истории).\n"
        . "3) Выдай агенту СЛЕДУЮЩУЮ техническую задачу брифом: конкретное действие, объект (домен/кампания/ID),\n"
        . "   числа и пороги из данных выше, ожидаемый результат, критерий готовности.\n"
        . "   Если агент уже работает над правильной задачей и новых данных нет — поле task_title оставь пустым.\n\n"
        . "Ответ строго JSON:\n"
        . '{"memory":"новая компактная дорожная карта, 10-20 строк",'
        . '"triggers":"что нового найдено (или пусто)",'
        . '"task_title":"краткое название новой задачи агенту (или пусто)",'
        . '"task_detail":"технический бриф задачи: действие, объект, числа, критерий готовности (или пусто)"}';

    [$text, $tin, $tout, $cost, $ms, $err] = orch_call($model, $prompt);
    $j = $err === '' ? orch_parse_json($text) : [];
    $memory = trim((string)($j['memory'] ?? ''));
    $triggers = trim((string)($j['triggers'] ?? ''));
    $decision = trim((string)($j['task_title'] ?? ''));           // в лог показываем задачу агенту
    $detail = trim((string)($j['task_detail'] ?? ''));

    $ok = ($err === '' && ($decision !== '' || $memory !== '')) ? 1 : 0;
    // задача агенту: создаём в дереве и ставим в очередь агента (если ещё нет открытой от оркестратора)
    $taskCreated = 0;
    if ($ok && $decision !== '') {
        try {
            $open = one("SELECT id FROM projects WHERE parent_id=? AND kind='task' AND status<>'done'
                          AND criteria='source: orchestrator' LIMIT 1", 'i', [$projectId]);
            if (!$open) {
                $pos = (int)(scalar("SELECT COALESCE(MAX(pos),0)+1 FROM projects WHERE parent_id=?", 'i', [$projectId]) ?? 1);
                $title = mb_substr($decision, 0, 190);
                $crit = 'source: orchestrator';
                $stT = db()->prepare("INSERT INTO projects (parent_id, kind, title, criteria, status, prio, pos, ready_at)
                                        VALUES (?, 'task', ?, ?, 'planned', 2, ?, UTC_TIMESTAMP())");
                $stT->bind_param('issi', $projectId, $title, $crit, $pos);
                $stT->execute();
                $taskId = (int)db()->insert_id;
                $taskCreated = $taskId;
                // бриф с техникой
                $stD = db()->prepare("UPDATE projects SET criteria=? WHERE id=?");
                $stD->bind_param('si', $detail, $taskId);
                $stD->execute();
                // в очередь агенту — payload как queue_next
                $proj = one("SELECT agent_code, agent_chat_id FROM projects WHERE id=?", 'i', [$projectId]);
                $agentCode = (string)($proj['agent_code'] ?? '');
                $payload = "Новая задача.\n\nЗадача {$taskId} — {$title}\n\nБриф от оркестратора:\n{$detail}\n\n"
                    . "Формат отчёта: отдельной строкой в конце TASK|{$taskId}|ACTIVE … TASK|{$taskId}|DONE. "
                    . "В отчёте указывай id трекера: {$taskId}. Правило прежнее: работаем по одной задаче, следующую ждёшь.";
                $stQ = db()->prepare("INSERT INTO dispatch_queue (project_id, agent_code, payload, status) VALUES (?,?,?,'new')");
                $stQ->bind_param('iss', $projectId, $agentCode, $payload);
                $stQ->execute();
            }
        } catch (Throwable $e) { error_log('orch task issue: ' . $e->getMessage()); }
    }


    db()->query("CREATE TABLE IF NOT EXISTS orch_log (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL, iter INT NOT NULL DEFAULT 0,
        ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        tok_in BIGINT NOT NULL DEFAULT 0, tok_out BIGINT NOT NULL DEFAULT 0,
        cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0, duration_ms INT NOT NULL DEFAULT 0,
        triggers TEXT NULL, decision TEXT NULL, state TEXT NULL,
        ok TINYINT NOT NULL DEFAULT 0, err TEXT NULL,
        KEY idx_p (project_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $iter = (int)$o['iter_count'] + 1;
    $st = db()->prepare("INSERT INTO orch_log (project_id, iter, tok_in, tok_out, cost_usd, duration_ms, triggers, decision, state, ok, err, raw_response)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $tinL = (int)$tin; $toutL = (int)$tout;
    $brief = $decision !== '' ? ($decision . ($detail !== '' ? "\n\n" . $detail : '')) : '';
    if ($ok === 0 && $err === '') {
        // модель ответила, но JSON не дал нужных полей — честная причина
        $err = 'модель ответила без JSON с полями memory/task_title (нечего ставить в задачу)';
    }
    $st->bind_param('iiiddissiiss', $projectId, $iter, $tinL, $toutL, $cost, $ms, $triggers, $brief, $memory, $ok, $err, $raw);
    $st->execute();
    $logId = (int)db()->insert_id;

    $newState = ($memory !== '') ? $memory : (string)$o['state'];
    $stU = db()->prepare("UPDATE orch SET iter_count=iter_count+1, last_run_at=UTC_TIMESTAMP(), state=? WHERE project_id=?");
    $stU->bind_param('si', $newState, $projectId);
    $stU->execute();

    // учёт вызова в общий журнал llm_calls
    try {
        $st2 = db()->prepare("INSERT INTO llm_calls (project_id, task_id, agent_code, server, model, turn_id, call_no, ts,
                                tok_in, tok_cache, tok_cache_write, tok_out, tok_think, cost_usd, duration_ms, error)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $server = 'icrm-orch'; $turn = 'orch-' . $iter; $callNo = 1; $zero = 0; $errS = $err !== '' ? $err : null;
        $ts = date('Y-m-d H:i:s');
        $st2->bind_param('iisssssiiiiiiiis', $projectId, $projectId, 'orchestrator', $server, $model, $turn, $callNo, $ts,
            $tinL, $zero, $zero, $toutL, $zero, $cost, $ms, $errS);
        $st2->execute();
    } catch (Throwable $e) { /* учёт не критичен */ }

    return $logId;
}
