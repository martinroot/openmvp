<?php
/**
 * Импорт финансов fapacrm (с 147) в локальную money-базу для сервиса Adult_1 (id 17).
 * Забирает JSON с velvetflux/money/api/fapacrm_finance.php и разносит в daily (server_id=17),
 * плюс обновляет справочник доменов (регистраторы/цены/сроки) в domain_meta.
 * Запуск: php import_fapacrm.php [days] (каждые 15 минут)
 */
$SVC = (int)($argv[1] ?? 17);
$days = (int)($argv[2] ?? 30);
if ($days < 1 || $days > 180) $days = 30;

$here = '/var/www/wifepornhub.ru/icrm/money';
$adminKey = trim((string)@file_get_contents($here . '/admin_key'));
$srcBase = $argv[3] ?? 'https://velvetflux.click/money/api/fapacrm_finance.php';
if (str_contains($srcBase, '?')) { $srcBase .= '&'; } else { $srcBase .= '?'; }
$url = $srcBase . 'key=' . urlencode($adminKey) . '&days=' . $days;

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($code !== 200 || !$raw) { fwrite(STDERR, "fetch failed: HTTP $code\n"); exit(1); }
$j = json_decode($raw, true);
if (empty($j['ok'])) { fwrite(STDERR, "bad json\n"); exit(1); }

$l = file_get_contents($here . '/lib.php');
preg_match("/DB_USER='([^']*)'/", $l, $mu); preg_match("/DB_PASS='CHANGE_ME']*)'/", $l, $mp); preg_match("/DB_NAME='([^']*)'/", $l, $mn);
$mdb = new mysqli('localhost', $mu[1], $mp[1], $mn[1]);
$mdb->set_charset('utf8mb4');

/* справочник доменов -> domain_meta (доп. поля registrar/price/expires) */
$mdb->query("ALTER TABLE domain_meta ADD COLUMN IF NOT EXISTS registrar VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS price DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS expires_at DATE NULL,
  ADD COLUMN IF NOT EXISTS autorenew TINYINT(1) NULL") or fwrite(STDERR, "alter warn: " . $mdb->error . "\n");

$stD = $mdb->prepare("INSERT INTO domain_meta (domain, registrar, updated_at)
  VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE registrar=VALUES(registrar), updated_at=NOW()");
$nDom = 0;
foreach (($j['domains'] ?? []) as $d) {
    $dn = (string)$d['domain'];
    $reg = (string)($d['registrar'] ?? '');
    $stD->bind_param('ss', $dn, $reg);
    $stD->execute();
    $nDom++;
}

/* дневные суммы -> daily (upsert по PK server_id+d+domain) */
$stU = $mdb->prepare("INSERT INTO daily (server_id, d, domain, cost, revenue, clicks, visits, updated_at)
  VALUES (?,?,?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE cost=VALUES(cost), revenue=VALUES(revenue), clicks=VALUES(clicks),
  visits=VALUES(visits), updated_at=NOW()");
$nRows = 0;
foreach (($j['daily'] ?? []) as $r) {
    $d = (string)$r['d']; $dom = (string)$r['domain'];
    $rev = (float)$r['revenue']; $cost = (float)$r['cost'];
    $cl = (int)round((float)$r['clicks']); $vs = (int)round((float)$r['visits']);
    $stU->bind_param('issddii', $SVC, $d, $dom, $cost, $rev, $cl, $vs);
    $stU->execute();
    $nRows++;
}
echo date('Y-m-d H:i:s') . " SVC=$SVC domains=$nDom rows=$nRows\n";
