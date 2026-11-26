<?php
declare(strict_types=1);
/**
 * MLL-советчик: статичный, запускается только по кнопке.
 *
 * Скармливает модели текущие задачи и журнал, получает список новых задач
 * (по 2 на направление) с короткими заголовками. Результат показывается
 * в редактируемых полях — оператор правит и вносит в план.
 */
require_once __DIR__ . '/core.php';

/** Топовые модели с ценой за 1M токенов (вход/выход), по каталогу OpenRouter. */
function mll_models_fallback(): array
{
    return [
        ['id' => 'z-ai/glm-5.3-flash',           'label' => 'GLM 5.3 Flash',       'in' => 0.0052, 'out' => 0.1985],
        ['id' => 'z-ai/glm-5.3',                 'label' => 'GLM 5.3',             'in' => 0.079,  'out' => 1.40],
        ['id' => 'moonshotai/kimi-k3',            'label' => 'Kimi K3',             'in' => 0.239,  'out' => 11.936],
        ['id' => 'deepseek/deepseek-v4.1-flash',  'label' => 'DeepSeek V4.1 Flash', 'in' => 0.30,   'out' => 1.20],
        ['id' => 'anthropic/claude-sonnet-5.5',   'label' => 'Claude Sonnet 5.5',   'in' => 2.00,   'out' => 10.00],
        ['id' => 'anthropic/claude-opus-5.5',     'label' => 'Claude Opus 5.5',     'in' => 4.00,   'out' => 20.00],
        ['id' => 'openai/gpt-5.6-sol',            'label' => 'GPT-5.6 sol',         'in' => 4.00,   'out' => 20.00],
        ['id' => 'anthropic/claude-opus-5',       'label' => 'Claude Opus 5',       'in' => 5.00,   'out' => 25.00],
        ['id' => 'openai/gpt-6-astra',            'label' => 'GPT-6 astra',         'in' => 10.00,  'out' => 50.00],
        ['id' => 'anthropic/claude-fable-5.1',    'label' => 'Claude Fable 5.1',    'in' => 10.00,  'out' => 50.00],
    ];
}

/**
 * Модели для MLL: из раздела «Модели» (сначала с оценками, потом самые дешёвые).
 * Если каталог не синкан — запасной список.
 */
function mll_models(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = [];
    try {
        require_once __DIR__ . '/models.php';
        $rows = models_list('all', 'score', 0.0);
    } catch (Throwable $e) {
        $rows = [];
    }
    if ($rows) {
        $scored  = [];
        $cheap   = [];
        $free    = [];
        $routers = [];
        foreach ($rows as $r) {
            $avg = $r['avg_score'] !== null ? (float)$r['avg_score'] : null;
            $item = [
                'id'    => (string)$r['slug'],
                'label' => (string)$r['name'],
                'in'    => round(max(0.0, (float)$r['p_in']) * 1e6, 4),
                'out'   => round(max(0.0, (float)$r['p_out']) * 1e6, 4),
                'score' => $avg !== null && $avg > 0 ? round($avg, 2) : null,
                'free'  => (int)$r['is_free'] === 1,
            ];
            if ((float)$r['p_in'] < 0) {
                $routers[] = $item;          // роутеры OpenRouter — не для MLL
            } elseif ($item['score'] !== null) {
                $scored[] = $item;
            } elseif ((float)$r['p_out'] <= 0) {
                $free[] = $item;             // бесплатные варианты — в конец
            } else {
                $cheap[] = $item;
            }
        }
        usort($cheap, static fn($a, $b) => $a['out'] <=> $b['out']);
        $out = array_merge($scored, array_slice($cheap, 0, 30), array_slice($free, 0, 5));
        // модели, назначенные направлениям, должны быть в списке всегда
        try {
            foreach (all("SELECT slug FROM project_models") as $pm) {
                $slug = (string)$pm['slug'];
                $has  = false;
                foreach ($out as $o) {
                    if ($o['id'] === $slug) { $has = true; break; }
                }
                if (!$has) {
                    $m = model_get($slug);
                    if ($m) {
                        $out[] = [
                            'id'    => $slug,
                            'label' => (string)$m['name'],
                            'in'    => round(max(0.0, (float)$m['p_in']) * 1e6, 4),
                            'out'   => round(max(0.0, (float)$m['p_out']) * 1e6, 4),
                            'score' => null,
                            'free'  => (int)$m['is_free'] === 1,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // назначений пока нет
        }
        // модели, закреплённые оператором, — всегда в списке
        try {
            require_once __DIR__ . '/models.php';
            foreach (array_filter(array_map('trim', explode(',', models_setting('pinned_models')))) as $slug) {
                $has = false;
                foreach ($out as $o) {
                    if ($o['id'] === $slug) { $has = true; break; }
                }
                if (!$has) {
                    $m = model_get($slug);
                    if ($m) {
                        $out[] = [
                            'id'    => $slug,
                            'label' => (string)$m['name'],
                            'in'    => round(max(0.0, (float)$m['p_in']) * 1e6, 4),
                            'out'   => round(max(0.0, (float)$m['p_out']) * 1e6, 4),
                            'score' => null,
                            'free'  => (int)$m['is_free'] === 1,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // закреплённых нет
        }
    }
    if (!$out) {
        $out = mll_models_fallback();
    }
    $cache = $out;
    return $out;
}

function mll_model(string $id): ?array
{
    foreach (mll_models() as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
}

/** Ключ OpenRouter: из настроек трекера, иначе из env Hermes. */
function mll_api_key(): string
{
    try {
        $row = one("SELECT v FROM settings WHERE k='openrouter_key'");
        $k = trim((string)($row['v'] ?? ''));
        if ($k !== '') {
            return $k;
        }
    } catch (Throwable $e) {
        // settings недоступны — пробуем env
    }
    $env = '/home/main/.hermes/.env';
    if (is_readable($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, 'OPENROUTER_API_KEY=') === 0) {
                return trim(substr($line, strlen('OPENROUTER_API_KEY=')));
            }
        }
    }
    return '';
}

/** Направления и подпроекты — для выбора. */
function mll_targets(): array
{
    $out = [];
    foreach (all("SELECT id, title, parent_id FROM projects WHERE kind IN ('project','subproject')
                  ORDER BY (kind='subproject'), title") as $r) {
        $out[(int)$r['id']] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'kind' => $r['parent_id'] === null ? 'project' : 'subproject',
        ];
    }
    return $out;
}

/** Текущий план: задачи по направлениям. */
function mll_plan_context(): string
{
    $rows = all("SELECT p.title AS project, t.id, t.title, t.status, t.criteria
                 FROM projects t JOIN projects p ON p.id = t.parent_id
                 WHERE t.kind='task' ORDER BY p.title, t.id");
    if (!$rows) {
        return "(задач пока нет)";
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = sprintf('[%d] %s — %s (%s)', (int)$r['id'], (string)$r['title'],
                          (string)$r['project'], (string)$r['status']);
    }
    return implode("\n", $out);
}

/** Журнал: последние события по направлениям. */
function mll_log_context(int $limit = 60): string
{
    $rows = all("SELECT e.ts, p.title AS project, e.kind, e.text
                 FROM events e LEFT JOIN projects p ON p.id = e.project_id
                 ORDER BY e.id DESC LIMIT " . (int)$limit);
    if (!$rows) {
        return "(журнал пуст)";
    }
    $out = [];
    foreach (array_reverse($rows) as $r) {
        $text = trim(preg_replace('/\s+/', ' ', (string)($r['text'] ?? '')));
        if (mb_strlen($text) > 400) {
            $text = mb_substr($text, 0, 400) . '…';
        }
        $out[] = sprintf('%s %s [%s] %s', dt((string)$r['ts'], 'd.m H:i'),
                          (string)($r['project'] ?? '—'), (string)$r['kind'], $text);
    }
    return implode("\n", $out);
}

/** Промт: 5 шагов по выбранному направлению. */
function mll_prompt(string $targetName, string $scope, string $hint = ''): string
{
    $plan = mll_plan_context();
    $log = mll_log_context();
    $hint = trim(preg_replace('/\s+/', ' ', $hint));
    $hintBlock = $hint !== ''
        ? "\n=== ПОЖЕЛАНИЕ ОПЕРАТОРА (куда развивать) ===\n{$hint}\n"
          . "Учитывай пожелание оператора: это главный вектор развития, шаги должны идти в эту сторону.\n"
        : '';
    return <<<PROMPT
Ты советник по плану задач. Работаем по одному направлению: {$targetName} ({$scope}).

=== ТЕКУЩИЕ ЗАДАЧИ (все направления) ===
{$plan}
{$hintBlock}
=== ЖУРНАЛ (что агенты делали) ===
{$log}

Дай РОВНО 5 шагов по направлению {$targetName}. Правила:
1. Заголовок шага — МАКСИМАЛЬНО КОРОТКИЙ: до 60 знаков, по-русски, с глагола, без воды и без объяснений. Это то, что агент прочитает и выполнит.
2. Шаг — конкретное действие, которое агент может выполнить и подтвердить фактами (цифры, пути, логи).
3. Не повторяй то, что уже есть в плане по этому направлению, и не дублируй шаги между собой.
4. Учитывай контекст журнала: если там видно проблему (ложные отправки, отсутствие аккаунтов, обвалы, зависшие задачи) — предложи шаг ровно на неё.
5. Порядок шагов — по важности: сначала деньги (лента → заявки → ответы), потом обслуживание.

Ответь ТОЛЬКО JSON без пояснений:
{"steps":[{"title":"КОРОТКИЙ ЗАГОЛОВОК"}]}

PROMPT;
}

/** Разбор ответа модели: список шагов. */
function mll_parse_steps(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    // вырезаем JSON, если модель обернула его в блок кода
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
        $title = is_array($s) ? trim((string)($s['title'] ?? '')) : trim((string)$s);
        if ($title !== '') {
            $out[] = $title;
        }
    }
    return $out;
}

/**
 * Вызов модели через OpenRouter.
 * Возвращает ['ok'=>bool, 'text'=>string, 'in'=>int, 'out'=>int, 'cost'=>float, 'error'=>string].
 */
function mll_call(string $model, string $prompt, int $maxTokens = 0): array
{
    $key = mll_api_key();
    if ($key === '') {
        return ['ok' => false, 'text' => '', 'in' => 0, 'out' => 0, 'cost' => 0.0,
                'error' => 'нет ключа OpenRouter'];
    }
    $body = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Ты отвечаешь строго JSON без пояснений.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.4,
        'max_tokens' => $maxTokens > 0 ? $maxTokens : null,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'text' => '', 'in' => 0, 'out' => 0, 'cost' => 0.0,
                'error' => 'сеть: ' . $err];
    }
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'text' => '', 'in' => 0, 'out' => 0, 'cost' => 0.0,
                'error' => 'не JSON от OpenRouter (HTTP ' . $code . '): ' . substr((string)$raw, 0, 200)];
    }
    if (isset($j['error'])) {
        $msg = is_array($j['error']) ? (string)($j['error']['message'] ?? 'ошибка') : (string)$j['error'];
        return ['ok' => false, 'text' => '', 'in' => 0, 'out' => 0, 'cost' => 0.0, 'error' => $msg];
    }
    $text = (string)($j['choices'][0]['message']['content'] ?? '');
    $usage = $j['usage'] ?? [];
    $in = (int)($usage['prompt_tokens'] ?? 0);
    $out = (int)($usage['completion_tokens'] ?? 0);
    $cache = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
    $cost = isset($usage['cost']) ? (float)$usage['cost'] : 0.0;
    if ($cost <= 0) {
        $m = mll_model($model);
        if ($m) {
            $cost = ($in - $cache) * $m['in'] / 1e6 + $cache * $m['in'] * 0.1 / 1e6
                  + $out * $m['out'] / 1e6;
        }
    }
    return ['ok' => true, 'text' => $text, 'in' => $in, 'out' => $out,
            'cache' => $cache, 'cost' => $cost, 'error' => ''];
}
