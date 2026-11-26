<?php
declare(strict_types=1);
/**
 * Клиент OpenRouter: каталог моделей, лимиты ключа, тестовый вызов.
 *
 * Всё ходит напрямую в api/v1 OpenRouter, ключ берётся из настроек трекера.
 */
require_once __DIR__ . '/core.php';

/** Ключ OpenRouter: из настроек трекера, иначе из env. */
function or_api_key(): string
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

/**
 * Запрос к api/v1 OpenRouter.
 * @return array{ok:bool,code:int,json:?array,error:string}
 */
function or_request(string $path, ?array $body = null, int $timeout = 180): array
{
    $key = or_api_key();
    if ($key === '') {
        return ['ok' => false, 'code' => 0, 'json' => null, 'error' => 'нет ключа OpenRouter'];
    }
    $ch = curl_init('https://openrouter.ai/api/v1' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'HTTP-Referer: http://5.144.179.170:8080/',
            'X-Title: task-tracker',
        ],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'code' => $code, 'json' => null, 'error' => 'сеть: ' . $err];
    }
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'code' => $code, 'json' => null,
                'error' => 'не JSON (HTTP ' . $code . '): ' . substr((string)$raw, 0, 200)];
    }
    if (isset($j['error'])) {
        $errObj = $j['error'];
        $msg    = is_array($errObj) ? (string)($errObj['message'] ?? 'ошибка OpenRouter') : (string)$errObj;
        $meta   = '';
        if (is_array($errObj) && !empty($errObj['metadata']['raw'])) {
            $meta = ' — ' . (string)$errObj['metadata']['raw'];
        }
        return ['ok' => false, 'code' => $code, 'json' => $j, 'error' => $msg . $meta];
    }
    return ['ok' => true, 'code' => $code, 'json' => $j, 'error' => ''];
}

/** Каталог моделей (сырой ответ api/v1/models). */
function or_models_raw(): array
{
    return or_request('/models', null, 90);
}

/** Лимиты и баланс ключа (api/v1/key). */
function or_key_info(): array
{
    $r = or_request('/key', null, 45);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => '', 'data' => $r['json']['data'] ?? []];
}

/**
 * Тестовый вызов модели: вернуть ответ, токены, стоимость и время.
 * @return array{ok:bool,text:string,in:int,out:int,cost:float,latency:int,error:string,model:string}
 */

/**
 * Как выбираем провайдера OpenRouter.
 * Настройка or_provider_mode:
 *   '' или 'default' — как есть (по умолчанию OpenRouter сам выбирает);
 *   'price'          — маршрутизация по цене (самый дешёвый провайдер);
 *   '<slug>'         — конкретный провайдер (например 'relace').
 */
function or_provider_mode(): string
{
    try {
        $row = one("SELECT v FROM settings WHERE k='or_provider_mode'");
        return trim((string)($row['v'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function or_provider_routing(): array
{
    $mode = strtolower(or_provider_mode());
    if ($mode === '' || $mode === 'default' || $mode === 'auto') {
        return [];
    }
    if ($mode === 'price') {
        return ['sort' => 'price'];
    }
    return ['order' => [$mode], 'allow_fallbacks' => true];
}

function or_chat(string $model, string $prompt, int $maxTokens = 700, int $timeout = 180, array $extra = []): array
{
    return or_chat_msgs($model, [['role' => 'user', 'content' => $prompt]], $maxTokens, $timeout, $extra);
}

/**
 * Мульти-турновый вызов: messages = [{role: user|assistant, content: ...}].
 * Настоящие роли нужны там, где важна память диалога (глобальный MLL).
 */
function or_chat_msgs(string $model, array $messages, int $maxTokens = 700, int $timeout = 180, array $extra = []): array
{
    $t0   = microtime(true);
    if (!$messages) {
        $messages = [['role' => 'user', 'content' => '—']];
    }
    $body = [
        'model'      => $model,
        'messages'   => $messages,
        'max_tokens' => $maxTokens,
    ];
    $prov = or_provider_routing();
    if ($prov !== []) {
        $body['provider'] = $prov;
    }
    foreach ($extra as $k => $v) {
        $body[$k] = $v;
    }
    $r = or_request('/chat/completions', $body, $timeout);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if (!$r['ok']) {
        return ['ok' => false, 'text' => '', 'in' => 0, 'out' => 0, 'cost' => 0.0,
                'latency' => $ms, 'error' => $r['error'], 'model' => $model];
    }
    $j     = $r['json'];
    $msg   = $j['choices'][0]['message'] ?? [];
    $text  = (string)($msg['content'] ?? '');
    $usage = $j['usage'] ?? [];
    $in    = (int)($usage['prompt_tokens'] ?? 0);
    $out   = (int)($usage['completion_tokens'] ?? 0);
    // рассуждающие модели держат текст в reasoning, а токены тратят и на него
    $think = (int)($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
    $cache = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
    $cost  = isset($usage['cost']) ? (float)$usage['cost'] : 0.0;
    if ($cost <= 0.0) {
        // OpenRouter не всегда отдаёт cost — считаем по каталогу
        $m = model_get($model);
        if ($m) {
            $cost = $in * (float)$m['p_in'] + $out * (float)$m['p_out'];
        }
    }
    return ['ok' => true, 'text' => $text, 'in' => $in, 'out' => $out, 'cost' => $cost,
            'think' => $think, 'cache' => $cache, 'reasoning' => (string)($msg['reasoning'] ?? ''),
            'latency' => $ms, 'error' => '', 'model' => (string)($j['model'] ?? $model),
            'finish' => (string)($j['choices'][0]['finish_reason'] ?? '')];
}
