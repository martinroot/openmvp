<?php
/** Админ-операции раздела «Деньги»: сервисы (147) + привязки к дереву (локально). */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$adminApi = 'https://wifepornhub.ru/icrm/money/';
$keyFile = dirname(__DIR__) . '/var/money_admin_key';
$ADMIN_KEY = is_file($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
if ($ADMIN_KEY === '') { echo json_encode(['error' => 'no admin key']); exit; }

/** вызов админ-API 147 */
function svc_api(string $action, array $payload): array {
    global $adminApi, $ADMIN_KEY;
    $ch = curl_init($adminApi);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Admin-Key: ' . $ADMIN_KEY],
        CURLOPT_POSTFIELDS => json_encode(array_merge(['action' => $action], $payload), JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($res === false) return ['error' => 'api unreachable'];
    $j = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($j)) return ['error' => 'api error ' . $code];
    return $j;
}

$act = (string)($_POST['action'] ?? '');
try {
    switch ($act) {
        case 'svc_rename': {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            if ($id <= 0 || $code === '') throw new Exception('заполните название');
            $r = svc_api('rename', ['id' => $id, 'code' => $code]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка 147'));
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_add': {
            $code = trim((string)($_POST['code'] ?? ''));
            $ip = trim((string)($_POST['ip'] ?? ''));
            $kind = (string)($_POST['kind'] ?? 'tube');
            if ($code === '' || $ip === '') throw new Exception('заполните название и IP');
            $r = svc_api('add', ['code' => $code, 'ip' => $ip, 'kind' => $kind]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка 147'));
            echo json_encode(['ok' => true, 'id' => $r['id'] ?? null, 'token' => $r['token'] ?? null]); exit;
        }
        case 'svc_toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $r = svc_api('toggle', ['id' => $id, 'enabled' => !empty($_POST['enabled'])]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка 147'));
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('нет ID');
            $r = svc_api('delete', ['id' => $id]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка 147'));
            q("DELETE FROM money_svc_map WHERE server_id=?", 'i', [$id]);
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_link': {
            $id = (int)($_POST['id'] ?? 0);
            $node = ($_POST['tree_node_id'] ?? '') !== '' ? (int)$_POST['tree_node_id'] : null;
            $name = trim((string)($_POST['name_override'] ?? ''));
            if ($id <= 0) throw new Exception('нет ID');
            $types = 'iis';
            $nn = $node; $nn2 = $name;
            q("INSERT INTO money_svc_map (server_id, tree_node_id, name_override) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE tree_node_id=VALUES(tree_node_id), name_override=VALUES(name_override)",
               $types, [$id, $nn, $nn2]);
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_ip': {
            $id = (int)($_POST['id'] ?? 0);
            $ip = trim((string)($_POST['ip'] ?? ''));
            if ($id <= 0 || filter_var($ip, FILTER_VALIDATE_IP) === false) throw new Exception('некорректный IP');
            $r = svc_api('ip', ['id' => $id, 'ip' => $ip]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка money-центра'));
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_kind': {
            $id = (int)($_POST['id'] ?? 0);
            $kind = (string)($_POST['kind'] ?? '');
            if (!in_array($kind, ['tube', 'tracker', 'game', 'win', 'edge'], true)) throw new Exception('неизвестный тип');
            $r = svc_api('kind', ['id' => $id, 'kind' => $kind]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка money-центра'));
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_admin': {
            $id = (int)($_POST['id'] ?? 0);
            $u = trim((string)($_POST['admin_url'] ?? ''));
            if ($id <= 0) throw new Exception('нет ID');
            if ($u !== '' && !preg_match('#^https?://#i', $u)) throw new Exception('URL должен начинаться с http(s)://');
            if ($u === '') { /* пустое поле = не трогать сохранённую админку (защита от затирания) */
                echo json_encode(['ok' => true, 'kept' => true]); exit;
            }
            q("INSERT INTO money_svc_map (server_id, admin_url) VALUES (?,?) ON DUPLICATE KEY UPDATE admin_url=VALUES(admin_url)",
               'is', [$id, $u]);
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_color': {
            $id = (int)($_POST['id'] ?? 0);
            $color = trim((string)($_POST['color'] ?? ''));
            if ($id <= 0) throw new Exception('нет ID');
            if ($color !== '' && !in_array($color, ['primary', 'success', 'danger', 'warning', 'info', 'dark', 'secondary'], true) && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw new Exception('неизвестный цвет');
            $types = 'is';
            $cc = ($color === '') ? null : $color;
            q("INSERT INTO money_svc_map (server_id, color) VALUES (?,?) ON DUPLICATE KEY UPDATE color=VALUES(color)",
               $types, [$id, $cc]);
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_check': {
            $id = (int)($_POST['id'] ?? 0);
            $url = trim((string)($_POST['check_url'] ?? ''));
            if ($id <= 0) throw new Exception('нет ID');
            if ($url !== '' && !preg_match('#^https?://#i', $url)) throw new Exception('URL должен начинаться с http(s)://');
            $r = svc_api('check', ['id' => $id, 'check_url' => $url]);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка money-центра'));
            echo json_encode(['ok' => true]); exit;
        }
        case 'svc_ping': {
            $r = svc_api('ping', []);
            if (empty($r['ok'])) throw new Exception((string)($r['error'] ?? 'ошибка money-центра'));
            echo json_encode(['ok' => true, 'ping' => $r['ping'] ?? []]); exit;
        }
        default:
            throw new Exception('неизвестное действие');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
