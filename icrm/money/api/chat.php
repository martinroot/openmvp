<?php
declare(strict_types=1);
/**
 * chat.php — общая шина чата агентов сети (аутентификация токеном сервера, как ingest.php).
 *   POST /money/api/chat.php  X-Server X-Token  {"kind","ref","text"}   — написать
 *   GET  /money/api/chat.php?since=<id>&limit=100  X-Server X-Token      — прочитать новые
 * Центр пишет/читает money.agent_chat напрямую. Дашборд («Чат агентов») читает ту же таблицу.
 */
const DB_HOST='localhost'; const DB_USER='money'; const DB_PASS='CHANGE_ME'; const DB_NAME='money';
header('Content-Type: application/json; charset=utf-8');
function out(int $c, array $p): never { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE); exit; }

$db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) out(500, ['error'=>'база недоступна']);
$db->set_charset('utf8mb4');

$code  = trim((string)($_SERVER['HTTP_X_SERVER'] ?? ''));
$token = trim((string)($_SERVER['HTTP_X_TOKEN']  ?? ''));
if ($code==='' || $token==='') out(401, ['error'=>'нужны X-Server и X-Token']);
$st = $db->prepare('SELECT token_hash FROM servers WHERE code=? LIMIT 1');
$st->bind_param('s', $code); $st->execute();
$srv = $st->get_result()->fetch_assoc();
if (!$srv || !hash_equals((string)$srv['token_hash'], hash('sha256', $token))) out(401, ['error'=>'сервер или токен не подходят']);

$m = $_SERVER['REQUEST_METHOD'] ?? '';
if ($m === 'GET') {
    $since = (int)($_GET['since'] ?? 0);
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $q = $db->prepare('SELECT id,ts,agent,kind,ref,text FROM agent_chat WHERE id>? ORDER BY id ASC LIMIT ?');
    $q->bind_param('ii', $since, $limit); $q->execute();
    out(200, ['messages'=>$q->get_result()->fetch_all(MYSQLI_ASSOC)]);
}
if ($m !== 'POST') out(405, ['error'=>'POST или GET']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) out(400, ['error'=>'ожидаю JSON']);
$kind = substr((string)($in['kind'] ?? 'note'), 0, 24);
$ref  = substr((string)($in['ref']  ?? ''), 0, 64);
$text = trim(substr((string)($in['text'] ?? ''), 0, 2000));
if ($text==='') out(400, ['error'=>'пустой text']);
$ins = $db->prepare('INSERT INTO agent_chat (agent,kind,ref,text) VALUES (?,?,?,?)');
$ins->bind_param('ssss', $code, $kind, $ref, $text);
$ins->execute();
out(200, ['status'=>'ok', 'id'=>$db->insert_id]);
