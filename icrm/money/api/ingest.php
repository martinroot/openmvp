<?php
declare(strict_types=1);
/**
 * Единая точка приёма отчётов от серверов сетки.
 *
 *   POST /money/api/ingest.php
 *   X-Server: <code>
 *   X-Token:  <token>
 *   Content-Type: application/json
 *
 *   {"rows":[{"d":"2026-09-03","domain":"a.ru","cost":0.07,"revenue":0.12,"clicks":900,"visits":140}]}
 *
 * Идемпотентно: повторная отправка того же дня перезаписывает строку, а не плодит.
 * Поэтому сервер может слать хоть каждые пять минут — сегодняшний день просто уточняется.
 *
 * Токен в базе не хранится, только sha256. Потерял — выпускай новый.
 */

const DB_HOST = 'localhost';
const DB_USER = 'money';
const DB_PASS = 'CHANGE_ME';
const DB_NAME = 'money';
const MAX_ROWS = 5000;

header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload, ?mysqli $db = null, ?int $srv = null, int $rows = 0): never {
    http_response_code($code);
    if ($db) {
        $st = $db->prepare('INSERT INTO ingest_log (server_id, src_ip, http_code, rows_in, message) VALUES (?,?,?,?,?)');
        $ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $msg = substr((string)($payload['error'] ?? $payload['status'] ?? ''), 0, 500);
        $st->bind_param('isiis', $srv, $ip, $code, $rows, $msg);
        @$st->execute();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['error' => 'только POST']);

$code  = trim((string)($_SERVER['HTTP_X_SERVER'] ?? ''));
$token = trim((string)($_SERVER['HTTP_X_TOKEN']  ?? ''));
if ($code === '' || $token === '') out(401, ['error' => 'нужны заголовки X-Server и X-Token']);

$db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) out(500, ['error' => 'база недоступна']);
$db->set_charset('utf8mb4');

$st = $db->prepare('SELECT id, token_hash, is_enabled FROM servers WHERE code = ? LIMIT 1');
$st->bind_param('s', $code);
$st->execute();
$srv = $st->get_result()->fetch_assoc();

// Сравнение постоянного времени: иначе по скорости ответа можно подбирать токен.
if (!$srv || !hash_equals((string)$srv['token_hash'], hash('sha256', $token))) {
    out(401, ['error' => 'сервер или токен не подходят'], $db);
}
if (!(int)$srv['is_enabled']) out(403, ['error' => 'сервер отключён'], $db, (int)$srv['id']);
$sid = (int)$srv['id'];

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in) || !isset($in['rows']) || !is_array($in['rows'])) {
    out(400, ['error' => 'ожидаю JSON вида {"rows":[...]}'], $db, $sid);
}
$rows = $in['rows'];
if (count($rows) > MAX_ROWS) out(413, ['error' => 'слишком много строк, максимум ' . MAX_ROWS], $db, $sid);

$up = $db->prepare(
    'INSERT INTO daily (server_id, d, domain, cost, revenue, clicks, visits, meta_json)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       cost=VALUES(cost), revenue=VALUES(revenue),
       clicks=VALUES(clicks), visits=VALUES(visits), meta_json=VALUES(meta_json)'
);

$ok = 0; $bad = [];
$db->begin_transaction();
foreach ($rows as $i => $r) {
    if (!is_array($r)) { $bad[] = "строка $i: не объект"; continue; }
    $d      = (string)($r['d'] ?? '');
    $domain = strtolower(trim((string)($r['domain'] ?? '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $bad[] = "строка $i: дата '$d' не в формате ГГГГ-ММ-ДД"; continue; }
    if ($domain === '' || strlen($domain) > 190)  { $bad[] = "строка $i: пустой или слишком длинный domain"; continue; }

    $cost    = (float)($r['cost']    ?? 0);
    $revenue = (float)($r['revenue'] ?? 0);
    $clicks  = (int)  ($r['clicks']  ?? 0);
    $visits  = (int)  ($r['visits']  ?? 0);
    $meta    = isset($r['meta']) ? json_encode($r['meta'], JSON_UNESCAPED_UNICODE) : null;

    $up->bind_param('issddiis', $sid, $d, $domain, $cost, $revenue, $clicks, $visits, $meta);
    if ($up->execute()) $ok++; else $bad[] = "строка $i: " . $up->error;
}
$db->commit();

$db->query('UPDATE servers SET last_seen_at = NOW(), last_ip = ' .
           "'" . $db->real_escape_string(substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)) . "' " .
           'WHERE id = ' . $sid);

out(200, [
    'status'   => 'ok',
    'server'   => $code,
    'accepted' => $ok,
    'rejected' => count($bad),
    'errors'   => array_slice($bad, 0, 10),
], $db, $sid, $ok);
