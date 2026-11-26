<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$e = parse_ini_file('/etc/openmvp/dv.env');
if (!$e || empty($e['DV_API_KEY']) || empty($e['DB_DSN'])) {
    out(500, ['ok' => false, 'error' => 'configuration unavailable']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['ok' => false, 'error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$j = json_decode($raw, true);
if (!is_array($j)) { $j = $_POST; }
$amountRaw = $j['amount'] ?? null;
if (!is_numeric($amountRaw)) {
    out(400, ['ok' => false, 'error' => 'enter an amount between 1 and 1000 USD']);
}
$amount = round((float)$amountRaw, 2);
if ($amount < 1.0 || $amount > 1000.0 || $amount != round($amount, 2)) {
    out(400, ['ok' => false, 'error' => 'amount must be between 1 and 1000 USD']);
}
if (($j['currency_code'] ?? 'USD') !== 'USD') {
    out(400, ['ok' => false, 'error' => 'USD only']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$external = 'omvp-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO($e['DB_DSN'], $e['DB_USER'], $e['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $recent = $pdo->prepare('SELECT COUNT(*) FROM donations WHERE client_ip=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $recent->execute([$ip]);
    if ((int)$recent->fetchColumn() >= 12) {
        out(429, ['ok' => false, 'error' => 'too many payment attempts, try again later']);
    }
    $ins = $pdo->prepare('INSERT INTO donations (external_id, amount, currency, client_ip) VALUES (?,?,?,?)');
    $ins->execute([$external, $amount, 'USD', $ip]);
} catch (Throwable $ex) {
    error_log('donate db: ' . $ex->getMessage());
    out(500, ['ok' => false, 'error' => 'could not create order']);
}

$api = rtrim($e['DV_MERCHANT_API'], '/') . '/wallet';
$body = json_encode([
    'amount' => $amount,
    'currency_code' => 'USD',
    'store_external_id' => $external,
], JSON_UNESCAPED_SLASHES);

$ch = curl_init($api);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'X-Api-Key: ' . $e['DV_API_KEY'],
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$d = json_decode((string)$resp, true);
$payUrl = is_array($d) ? ($d['data']['pay_url'] ?? null) : null;
$host = $payUrl ? parse_url($payUrl, PHP_URL_HOST) : null;
if ($code !== 200 || !is_string($payUrl) || $host !== 'openpay.click') {
    try {
        $pdo->prepare("UPDATE donations SET status='failed' WHERE external_id=?")->execute([$external]);
    } catch (Throwable $ignore) {}
    error_log('donate gateway: HTTP ' . $code . ' ' . substr($err . ' ' . (string)$resp, 0, 300));
    out(502, ['ok' => false, 'error' => 'payment gateway did not return a checkout URL']);
}

$wallet = is_array($d) ? ($d['data']['id'] ?? null) : null;
try {
    $pdo->prepare('UPDATE donations SET wallet_id=?, pay_url=? WHERE external_id=?')
        ->execute([is_string($wallet) ? $wallet : null, $payUrl, $external]);
} catch (Throwable $ignore) {}

out(200, [
    'ok' => true,
    'order_id' => $external,
    'amount' => $amount,
    'pay_url' => $payUrl,
]);
