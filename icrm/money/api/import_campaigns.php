<?php
/**
 * Импорт РК из трекера RTB (push.click) в money.
 * campaigns_daily (PK server_id+день+campaign_id) + дневные суммы в daily (domain='').
 * Запуск: php import_campaigns.php <server_id> <days> [url]
 */
$SVC = (int)($argv[1] ?? 16);
$days = (int)($argv[2] ?? 30);
if ($days < 1 || $days > 90) $days = 30;
$here = '/var/www/wifepornhub.ru/icrm/money';
$adminKey = trim((string)@file_get_contents($here . '/admin_key'));
$srcBase = $argv[3] ?? 'https://velvetflux.click/rtb/api_money_export.php';
$srcBase .= (str_contains($srcBase, '?') ? '&' : '?') . 'key=' . urlencode($adminKey) . '&days=' . $days;

$ch = curl_init($srcBase);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($code !== 200 || !$raw) { fwrite(STDERR, "fetch failed: HTTP $code\n"); exit(1); }
$j = json_decode($raw, true);
if (empty($j['ok']) || !isset($j['daily'])) { fwrite(STDERR, "bad json\n"); exit(1); }

$l = file_get_contents($here . '/lib.php');
preg_match("/DB_USER='([^']*)'/", $l, $mu); preg_match("/DB_PASS='CHANGE_ME']*)'/", $l, $mp); preg_match("/DB_NAME='([^']*)'/", $l, $mn);
$mdb = new mysqli('localhost', $mu[1], $mp[1], $mn[1]);
$mdb->set_charset('utf8mb4');

$mdb->query("CREATE TABLE IF NOT EXISTS campaigns_daily (
  server_id SMALLINT UNSIGNED NOT NULL, d DATE NOT NULL,
  campaign_id VARCHAR(64) NOT NULL, campaign VARCHAR(128) NOT NULL, domain VARCHAR(190) NOT NULL DEFAULT '',
  cost DECIMAL(14,6) NOT NULL DEFAULT 0, clicks INT NOT NULL DEFAULT 0, views INT NOT NULL DEFAULT 0,
  revenue DECIMAL(14,6) NOT NULL DEFAULT 0, leads INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (server_id, d, campaign_id),
  KEY idx_srv (server_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$st = $mdb->prepare("INSERT INTO campaigns_daily (server_id, d, campaign_id, campaign, domain, cost, clicks, views, revenue, leads)
  VALUES (?,?,?,?,?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE campaign=VALUES(campaign), cost=VALUES(cost), clicks=VALUES(clicks),
    views=VALUES(views), revenue=VALUES(revenue), leads=VALUES(leads)");
$stD = $mdb->prepare("INSERT INTO daily (server_id, d, domain, revenue, cost)
  VALUES (?,?,'',?,?)
  ON DUPLICATE KEY UPDATE revenue=VALUES(revenue), cost=VALUES(cost)");
if (!$st || !$stD) { fwrite(STDERR, "prepare: " . $mdb->error . "\n"); exit(1); }

$daySum = []; // d => [cost, revenue]
$n = 0;
foreach ($j['daily'] as $row) {
    $cid = (string)($row['cid'] ?? '');
    if ($cid === '') continue;
    $camp = mb_substr((string)($row['name'] ?? '') ?: ('camp ' . $cid), 0, 120);
    $cost = (float)($row['cost'] ?? 0); $cl = (int)($row['clicks'] ?? 0);
    $rv = (float)($row['revenue'] ?? 0); $ld = (int)($row['leads'] ?? 0);
    $d = (string)$row['d'];
    $dom = ''; $vw = 0;
    $st->bind_param('issssdiidi', $SVC, $d, $cid, $camp, $dom, $cost, $cl, $vw, $rv, $ld);
    $st->execute(); $n++;
    if (!isset($daySum[$d])) $daySum[$d] = [0.0, 0.0];
    $daySum[$d][0] += $cost; $daySum[$d][1] += $rv;
}
$nD = 0;
foreach ($daySum as $d => $s) {
    $cost = round($s[0], 6); $rv = round($s[1], 6);
    $stD->bind_param('isdd', $SVC, $d, $rv, $cost);
    $stD->execute(); $nD++;
}
echo date('Y-m-d H:i:s'), " SVC=$SVC camps=$n days=$nD\n";
