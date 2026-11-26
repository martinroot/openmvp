<?php
declare(strict_types=1);

/**
 * Работа с агентским чатом velvetflux (chat.php).
 * Запись: POST {kind,ref,text} с X-Server/X-Token.
 * Чтение:  GET ?since=<last_id>&limit=100 -> {messages:[{id,ts,agent,kind,ref,text}]}
 */

function chat_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'url'    => CHAT_URL,
            'server' => CHAT_SERVER,
            'token'  => CHAT_TOKEN,
        ];
        try {
            foreach (all("SELECT * FROM settings") as $s) {
                if ($s['k'] === 'chat_url' && $s['v'])    $cfg['url'] = $s['v'];
                if ($s['k'] === 'chat_server' && $s['v']) $cfg['server'] = $s['v'];
            }
        } catch (Throwable $e) {
            // settings недоступны — используем дефолт
        }
    }
    return $cfg;
}

/** POST в чат. Возвращает ['ok'=>bool,'error'=>string] */
function chat_post(string $kind, string $ref, string $text): array
{
    $c = chat_cfg();
    $ch = curl_init($c['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'X-Server: ' . $c['server'],
            'X-Token: ' . $c['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'kind' => $kind, 'ref' => $ref, 'text' => $text,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => $err ?: 'network error'];
    }
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['error'])) {
        return ['ok' => false, 'error' => (string)$j['error']];
    }
    return ['ok' => ($code >= 200 && $code < 300), 'error' => $raw ? '' : 'empty response'];
}

/** GET из чата. Возвращает массив сообщений. */
function chat_fetch(int $since = 0, int $limit = 100): array
{
    $c = chat_cfg();
    $url = $c['url'] . '?since=' . $since . '&limit=' . $limit;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'X-Server: ' . $c['server'],
            'X-Token: ' . $c['token'],
        ],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [];
    }
    $j = json_decode($raw, true);
    return is_array($j) && isset($j['messages']) ? $j['messages'] : [];
}

/** Последний прочитанный id для узла (по событиям с chat_msg_id) */
function node_last_chat_id(int $nodeId): int
{
    $v = scalar("SELECT COALESCE(MAX(chat_msg_id),0) FROM events WHERE project_id=?", 'i', [$nodeId]);
    return (int)$v;
}

/**
 * Забрать из чата сообщения, относящиеся к узлу, и приклеить их в лог.
 * Отношение: ref узла (ref задачи) в тексте/рефе сообщения, либо agent_code узла.
 */
function import_chat_to_node(int $nodeId, array $node): int
{
    $since = node_last_chat_id($nodeId);
    $msgs = chat_fetch($since, 100);
    if (!$msgs) {
        return 0;
    }
    $ref = strtolower((string)($node['title']));
    $agent = strtolower((string)($node['agent_code'] ?? ''));
    $imported = 0;

    foreach ($msgs as $m) {
        $mid = (int)($m['id'] ?? 0);
        if ($mid <= $since) {
            continue;
        }
        $mtext = (string)($m['text'] ?? '');
        $mref = strtolower((string)($m['ref'] ?? ''));
        $mag = strtolower((string)($m['agent'] ?? ''));

        $hit = false;
        if ($ref !== '' && (str_contains($mref, $ref) || str_contains(strtolower($mtext), $ref))) {
            $hit = true;
        }
        if (!$hit && $agent !== '' && $mag === $agent) {
            $hit = true;
        }
        if (!$hit) {
            continue;
        }
        q("INSERT INTO events (project_id, kind, level, title, text, server, chat_msg_id)
           VALUES (?,?,?,?,?,?,?)",
          'isssssi', [
              $nodeId,
              $m['kind'] === 'report' ? 'agent' : 'chat',
              'ok',
              null,
              $mtext,
              $m['agent'] ?? null,
              $mid,
          ]);
        $imported++;
    }
    return $imported;
}

/** Найти узел по свободному ref (для ingest API) */
function find_node_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    $n = one("SELECT * FROM projects WHERE title = ? LIMIT 1", 's', [$ref]);
    if ($n) {
        return $n;
    }
    $n = one("SELECT * FROM projects WHERE agent_code = ? AND kind='project' LIMIT 1", 's', [$ref]);
    return $n;
}
