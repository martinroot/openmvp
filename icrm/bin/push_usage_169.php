<?php
declare(strict_types=1);
/**
 * push_usage_169.php — догоняющий пушер ходов этой машины (main@5.144) в трекер.
 * Читает usage.jsonl (агрегаты ходов ⚙️), POST-ит новые строки как type=turn.
 * Идемпотентно: ingest_turn делает ON DUPLICATE KEY UPDATE по job_id=turn_id.
 * Курсор: var/usage_push.offset. Крон раз в 5 минут.
 */

const JSONL  = '/home/main/.hermes/plugins/token-usage-footer/usage.jsonl';
const OFFSET = '/var/www/wifepornhub.ru/icrm/var/usage_push.offset';
const URL    = 'https://127.0.0.1/icrm/api/ingest.php';

$db = @new mysqli('localhost', 'root', '', 'system');
if ($db->connect_error) { fwrite(STDERR, "system недоступна: {$db->connect_error}\n"); exit(1); }
$k = $db->query("SELECT v FROM settings WHERE k='ingest_key'")->fetch_row();
if (!$k) { fwrite(STDERR, "нет ingest_key\n"); exit(1); }
$key = $k[0];

if (!is_readable(JSONL)) { fwrite(STDERR, "нет " . JSONL . "\n"); exit(1); }
$lines = file(JSONL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$cur = (int)(@file_get_contents(OFFSET) ?: 0);
if ($cur > count($lines)) $cur = 0;

function push(string $key, string $body): array {
    $ch = curl_init(URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Ingest-Key: ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    curl_setopt($ch, CURLOPT_RESOLVE, ['openmvp.online:443:127.0.0.1']);
    curl_setopt($ch, CURLOPT_URL, 'https://openmvp.online/icrm/api/ingest.php');
    $r = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, (string)$r];
}

$ok = 0; $skip = 0; $err = 0; $newCur = $cur; $lastErr = '';
foreach ($lines as $i => $ln) {
    if ($i < $cur) continue;
    $d = json_decode($ln, true);
    if (!is_array($d) || empty($d['turn_id'])) { $newCur = $i + 1; $skip++; continue; }
    $payload = [
        'type'        => 'turn',
        'turn_id'     => (string)$d['turn_id'],
        'session_id'  => (string)($d['session_id'] ?? ''),
        'project_id'  => 169,
        'project'     => 'OpenMVP.online',
        'agent_code'  => 'hermes-main',
        'server'      => '5.144.179.170',
        'model'       => (string)($d['model'] ?? ''),
        'chat_id'     => -1004362113575,
        'tok_in'      => (int)($d['in'] ?? 0),
        'tok_cache'   => (int)($d['cache_read'] ?? 0),
        'tok_out'     => (int)($d['out'] ?? 0) + (int)($d['cache_write'] ?? 0),
        'reasoning'   => (int)($d['reasoning'] ?? 0),
        'call_no'     => max(1, (int)($d['calls'] ?? 1)),
        'cost_usd'    => (float)($d['usd'] ?? 0),
        'duration_ms' => (int)($d['ms'] ?? 0),
        'ts'          => gmdate('Y-m-d H:i:s', (int)($d['ts'] ?? time())),
    ];
    [$code, $resp] = push($key, json_encode($payload, JSON_UNESCAPED_UNICODE));
    if ($code >= 200 && $code < 300) { $ok++; $newCur = $i + 1; }
    else { $err++; $lastErr = $code . ' ' . substr($resp, 0, 200); break; }
}
file_put_contents(OFFSET, (string)$newCur);
echo "usage_push: ok=$ok skip=$skip err=$err cur=$newCur/", count($lines), ($lastErr ? " last=$lastErr" : ''), "\n";
