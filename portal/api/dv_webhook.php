<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$log = static function (string $msg): void {
    @file_put_contents('/var/log/openmvp_donate.log', date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
};

$e = parse_ini_file('/etc/openmvp/dv.env');
if (!$e || empty($e['DV_CLIENT_KEY']) || empty($e['DB_DSN'])) {
    http_response_code(500);
    echo 'CONFIG';
    exit;
}

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', (string)$raw, $e['DV_CLIENT_KEY']);
if (!hash_equals($expected, (string)$sig)) {
    $log('rejected signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(403);
    echo 'BAD_SIGNATURE';
    exit;
}

$d = json_decode((string)$raw, true);
if (!is_array($d)) {
    http_response_code(400);
    echo 'BAD_JSON';
    exit;
}

$type = (string)($d['type'] ?? '');
$status = (string)($d['status'] ?? '');
$external = (string)($d['store_external_id'] ?? ($d['invoice_id'] ?? ''));

// Sandbox deliveries are signed exactly like real ones but must never mark a real order paid.
if (strpos($external, 'sandbox-') === 0 || !empty($d['sandbox'])) {
    $log('sandbox webhook verified: ' . $external);
    echo 'OK';
    exit;
}

if ($type !== 'PaymentReceived' || $status !== 'paid' || $external === '') {
    $log('ignored event: ' . substr($type . '/' . $status . '/' . $external, 0, 120));
    echo 'OK-IGNORED';
    exit;
}

try {
    $pdo = new PDO($e['DB_DSN'], $e['DB_USER'], $e['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $sel = $pdo->prepare('SELECT id, status, amount FROM donations WHERE external_id=?');
    $sel->execute([$external]);
    $row = $sel->fetch();
    if (!$row) {
        $log('unknown order: ' . $external);
        echo 'OK-UNKNOWN';
        exit;
    }
    if ($row['status'] === 'paid') {
        echo 'OK-ALREADY-PAID';
        exit;
    }
    $wallet = isset($d['wallet_id']) ? (string)$d['wallet_id'] : null;
    $upd = $pdo->prepare("UPDATE donations SET status='paid', paid_at=NOW(), wallet_id=COALESCE(?,wallet_id) WHERE id=? AND status<>'paid'");
    $upd->execute([$wallet, (int)$row['id']]);
    $log('PAID ' . $external . ' $' . $row['amount']);
    echo 'OK';
} catch (Throwable $ex) {
    $log('db error: ' . $ex->getMessage());
    http_response_code(500);
    echo 'DB_ERROR';
}
