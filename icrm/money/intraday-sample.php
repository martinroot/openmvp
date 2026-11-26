<?php
declare(strict_types=1);
/**
 * intraday-sample.php — снимок сегодняшних сумм раз в минуту (крон на центре).
 *  intraday       — итог по сети (для Обзора)
 *  intraday_snap  — по (сервер, домен) для фильтруемого мультиграфика (График)
 */
require __DIR__ . '/lib.php';
$db = db();
$ts = gmdate('Y-m-d H:i:00');

/* сеть */
$r = q($db, "SELECT COALESCE(SUM(d.cost),0) c, COALESCE(SUM(d.revenue),0) rv
             FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1 WHERE d.d = UTC_DATE()");
$c=(float)$r[0]['c']; $rv=(float)$r[0]['rv'];
$st = $db->prepare("INSERT INTO intraday (ts,cost,revenue) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cost=VALUES(cost),revenue=VALUES(revenue)");
$st->bind_param('sdd',$ts,$c,$rv); $st->execute();

/* по серверу+домену */
$rows = q($db, "SELECT d.server_id sid, d.domain dom, d.cost c, d.revenue rv, d.visits vis, d.clicks clk
                FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1 WHERE d.d=UTC_DATE()");
$s2 = $db->prepare("INSERT INTO intraday_snap (ts,server_id,domain,cost,revenue,visits,clicks) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE cost=VALUES(cost),revenue=VALUES(revenue),visits=VALUES(visits),clicks=VALUES(clicks)");
foreach($rows as $row){ $sid=(int)$row['sid']; $dom=(string)$row['dom']; $cc=(float)$row['c']; $rr=(float)$row['rv']; $vv=(int)$row['vis']; $kk=(int)$row['clk'];
    $s2->bind_param('sisddii',$ts,$sid,$dom,$cc,$rr,$vv,$kk); $s2->execute(); }

$db->query("DELETE FROM intraday WHERE ts < (UTC_TIMESTAMP() - INTERVAL 2 DAY)");
$db->query("DELETE FROM intraday_snap WHERE ts < (UTC_TIMESTAMP() - INTERVAL 2 DAY)");
echo "sampled $ts net(cost=$c rev=$rv) snap=".count($rows)."\n";
