<?php
/** Единое хранилище дневной активности — таблица system.portal_daily.
 *  1) Обновляет из живых таблиц (agent_runs/llm_calls) дни, по которым есть данные
 *     (REPLACE — статичные прошлые дни просто подтверждаются теми же значениями).
 *  2) Отдаёт archive.json {pid: {date: {j,t,c}}} на ВСЕ проекты MAP, включая
 *     разово импортированную историю (demo-филлы) — heatmap и график читают
 *     один и тот же набор данных и потому совпадают 1:1. */
require '/var/www/wifepornhub.ru/icrm/lib/core.php';

const OUT = '/var/www/openmvp-portal/archive.json';

const MAP = [
    'apimedia'      => 'apimedia',
    'worker.skin'   => 'workerskin',
    'cpark.click'   => 'cpark',
    'faptubes.click'=> 'faptubes',
    'fabra.live'    => 'fabra',
    'gitgame.live'  => 'gitgame',
    'dildo mll'     => 'dildomll',
    'fapa'          => 'fapa',
    'tapapp'        => 'tapapp',
    'dagi'          => 'dagi',
    'vibemetrics'   => 'vibemetrics',
    'ai spy'        => 'aispy',
    'tapapp crm'    => 'tapapp',
    'ibody'         => 'ibody',
    'aiwebback'     => 'aiwebback',
    'apiadult'      => 'apiadult',
    'spottygun'     => 'spottygun',
    'bi gpu'        => 'bigpu',
    'humanbro'      => 'humanbro',
    'facebook ads studio' => 'fas',
    'bigquery.live' => 'bigquery',
    'openpay'       => 'openpay',
    'gsb api'       => 'gsbapi',
    'openmvp.online'=> 'openmvp',
];

/* id проектов по slug */
$ids = [];
foreach (all("SELECT id, LOWER(title) t FROM projects") as $p) {
    if (isset(MAP[(string)$p['t']])) $ids[MAP[(string)$p['t']]] = (int)$p['id'];
}

/* 1) refreshing: дни с реальными данными (demo-дни не трогаем) */
q("INSERT INTO portal_daily (project_id,d,j,t,c)
   SELECT r.project_id, DATE(r.ts), COUNT(*), 0, 0
     FROM agent_runs r WHERE r.ts >= CURDATE() - INTERVAL 200 DAY GROUP BY 1,2
   ON DUPLICATE KEY UPDATE j = VALUES(j)");
q("INSERT INTO portal_daily (project_id,d,j,t,c)
   SELECT c.project_id, DATE(c.ts), 0, SUM(c.tok_in + c.tok_cache + c.tok_out), ROUND(SUM(c.cost_usd),4)
     FROM llm_calls c WHERE c.ts >= CURDATE() - INTERVAL 200 DAY GROUP BY 1,2
   ON DUPLICATE KEY UPDATE t = VALUES(t), c = VALUES(c)");

/* 2) выгрузка единого набора */
$arch = [];
foreach (array_values(MAP) as $slug) { $arch[$slug] = []; }
foreach (all("SELECT project_id, d, j, t, c FROM portal_daily WHERE d >= CURDATE() - INTERVAL 200 DAY") as $r) {
    $slug = array_search((int)$r['project_id'], $ids, true);
    if ($slug === false) continue;
    $arch[$slug][(string)$r['d']] = ['j' => (int)$r['j'], 't' => (int)$r['t'], 'c' => (float)$r['c']];
}

$tmp = OUT . '.tmp';
file_put_contents($tmp, json_encode($arch, JSON_UNESCAPED_SLASHES));
rename($tmp, OUT);
chown(OUT, 'www-data');
chgrp(OUT, 'www-data');
fwrite(STDERR, 'archive.json written: ' . count($arch) . " projects, " . array_sum(array_map('count', $arch)) . " day-rows\n");
