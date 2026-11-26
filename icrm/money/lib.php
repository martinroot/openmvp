<?php
declare(strict_types=1);
/**
 * lib.php — общее ядро аналитического центра сетки.
 * Подключается всеми страницами (index.php — роутер представлений).
 *
 * Ключевой инвариант: данные сводятся КАНОНИЧЕСКИ. На пару (день, домен)
 * берётся ровно один источник — выделенный сервер домена, если есть, иначе
 * донор-агрегатор (servers.is_aggregate=1). Это гасит двойной учёт, когда
 * донор «вся оставшаяся сетка» шлёт домен, у которого уже есть свой сервер.
 */
const DB_HOST='localhost'; const DB_USER='money'; const DB_PASS='CHANGE_ME'; const DB_NAME='money';

function db(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) { http_response_code(500); exit('база недоступна'); }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

function q(mysqli $db, string $sql, array $p = [], string $t = ''): array {
    $st = $db->prepare($sql);
    if ($p) $st->bind_param($t ?: str_repeat('s', count($p)), ...$p);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* ---------- форматтеры ---------- */
function money(float $v): string { return number_format($v, 4, '.', ' '); }
function m2(float $v): string    { return number_format($v, 2, '.', ' '); }
function num(int $v): string      { return number_format($v, 0, '.', ' '); }
function cls(float $v): string    { return $v > 0 ? 'pos' : ($v < 0 ? 'neg' : 'zero'); }
function dot(float $v): string    { return $v > 0 ? '🟢' : ($v < 0 ? '🔴' : '⚪'); }
function h(?string $s): string    { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function roi_s(float $cost, float $rev): string { return $cost > 0 ? number_format($rev/$cost,2).'x' : '—'; }

/* ---------- канонические строки за период ---------- */
function canon(int $days): array {
    $db = db();
    $from = (new DateTime("-" . ($days-1) . " days"))->format('Y-m-d');
    $raw = q($db, "
      SELECT dd.d, dd.domain, dd.server_id, s.code, s.network, s.is_aggregate,
             dd.cost, dd.revenue, dd.clicks, dd.visits, dd.updated_at
        FROM daily dd JOIN servers s ON s.id = dd.server_id
       WHERE s.kind IN ('tube','tracker') AND dd.d >= ?", [$from]);
    $pick = [];
    foreach ($raw as $r) {
        $k = $r['d'].'|'.$r['domain'];
        $cur = $pick[$k] ?? null;
        if ($cur === null) { $pick[$k] = $r; continue; }
        $c = (int)$r['is_aggregate'] <=> (int)$cur['is_aggregate'];
        if ($c < 0 || ($c === 0 && $r['updated_at'] > $cur['updated_at'])) $pick[$k] = $r;
    }
    return ['from'=>$from, 'rows'=>array_values($pick), 'raw_count'=>count($raw)];
}

function canon_range(string $from, string $to): array {
    $db = db();
    $raw = q($db, "
      SELECT dd.d, dd.domain, dd.server_id, s.code, s.network, s.is_aggregate,
             dd.cost, dd.revenue, dd.clicks, dd.visits, dd.updated_at
        FROM daily dd JOIN servers s ON s.id = dd.server_id
       WHERE s.kind IN ('tube','tracker') AND dd.d BETWEEN ? AND ?", [$from,$to]);
    $pick = [];
    foreach ($raw as $r) {
        $k = $r['d'].'|'.$r['domain'];
        $cur = $pick[$k] ?? null;
        if ($cur === null) { $pick[$k] = $r; continue; }
        $c = (int)$r['is_aggregate'] <=> (int)$cur['is_aggregate'];
        if ($c < 0 || ($c === 0 && $r['updated_at'] > $cur['updated_at'])) $pick[$k] = $r;
    }
    return ['from'=>$from, 'rows'=>array_values($pick), 'raw_count'=>count($raw)];
}


function donor_catalog(): array {
    $cache = __DIR__.'/donors.json';
    if (!is_file($cache) || time()-@filemtime($cache) > 3600) {
        $ctx = stream_context_create(['http'=>['timeout'=>5],'https'=>['timeout'=>5]]);
        $j = @file_get_contents('https://hardpulse.ru/temp/templates.json', false, $ctx);
        if ($j && json_decode($j,true)) @file_put_contents($cache, $j);
    }
    $d = json_decode((string)@file_get_contents($cache), true);
    $out=[]; foreach (($d['templates']??[]) as $t){ if(!empty($t['id'])) $out[$t['id']]=$t['name']??$t['id']; }
    return $out;
}

/* ---------- агрегации из канона ---------- */
function aggregate(array $rows): array {
    $day=[]; $srv=[]; $dom=[]; $net=[]; $dayNet=[]; $srvDay=[];
    foreach ($rows as $r) {
        $c=(float)$r['cost']; $rv=(float)$r['revenue']; $cl=(int)$r['clicks']; $vs=(int)$r['visits'];
        $d=$r['d']; $sid=(int)$r['server_id']; $dm=$r['domain']; $nw=$r['network'];

        $day[$d]['cost']=($day[$d]['cost']??0)+$c; $day[$d]['rev']=($day[$d]['rev']??0)+$rv;

        acc($srv,$sid,$c,$rv,$cl,$vs); $srv[$sid]['code']=$r['code']; $srv[$sid]['network']=$nw; $srv[$sid]['doms'][$dm]=1;
        acc($dom,$dm,$c,$rv,$cl,$vs); $dom[$dm]['server']=$r['code']; $dom[$dm]['network']=$nw;
        acc($net,$nw,$c,$rv,$cl,$vs);

        $dayNet[$d][$nw]=($dayNet[$d][$nw]??0)+($rv-$c);
        $srvDay[$sid][$d]=($srvDay[$sid][$d]??0)+($rv-$c);
    }
    ksort($day);
    return compact('day','srv','dom','net','dayNet','srvDay');
}
function acc(array &$a, $k, float $c, float $rv, int $cl, int $vs): void {
    $a[$k]['cost']=($a[$k]['cost']??0)+$c; $a[$k]['rev']=($a[$k]['rev']??0)+$rv;
    $a[$k]['clicks']=($a[$k]['clicks']??0)+$cl; $a[$k]['visits']=($a[$k]['visits']??0)+$vs;
}

/* ---------- список серверов (все включённые) ---------- */
function servers_all(): array {
    return q(db(), "SELECT id,code,ip,note,network,is_enabled,is_aggregate,last_seen_at,last_ping_ok,check_url,kind FROM servers ORDER BY network, is_aggregate DESC, code");
}
function last_ingest(): ?string {
    $r = q(db(), "SELECT MAX(ts) t FROM ingest_log WHERE http_code=200");
    return $r[0]['t'] ?? null;
}
function is_stale($seen): bool { return !$seen || (time()-strtotime((string)$seen))>3600; }

/* ---------- layout ---------- */
/* Курс приведения рублёвых источников (Яндекс.Игры) к доллару сетки.
   Тот же курс зашит в money-push-games.php на сервере игр — менять в обоих. */
const RUB_USD = 86.0;

const EXTRA_TABS = ['/pilot/'=>'🌲 Дерево тем', '/pilot/tracker.php'=>'⏱ Трекер'];

const VIEWS = ['overview'=>'Обзор','tree'=>'🌳 Дерево','farms'=>'🚜 Фермы','idle'=>'🌾 Агрохолдинг','days'=>'📅 По дням','live'=>'🔴 Live','agents'=>'💬 Чат','mainloop'=>'🧠 Main-loop','progress'=>'🎮 Прогресс','chart'=>'График','wall'=>'📊 Стена','trackers'=>'🎯 Трекеры','issues'=>'Задачи','servers'=>'Серверы','domains'=>'Домены','registrar'=>'🛂 Регистратор','games'=>'🕹 Игры','templates'=>'🎨 Шаблоны','trends'=>'Тренды','analytics'=>'Аналитика'];

/* ---------- детектор проблем/задач (общий для Обзора и раздела Задачи) ---------- */
function detect_issues(array $A, array $servers): array {
    $out = []; // [sev(0=bad,1=warn,2=info), ico, title, detail, action]
    foreach ($servers as $s) {
        if ((int)$s['is_enabled']===0)
            $out[] = [1,'⏸','Сервер '.$s['code'].' не в учёте ('.$s['ip'].')', (string)$s['note'], 'Решить: включить / починить сбор / снять с сетки'];
    }
    foreach ($servers as $s) {
        if ((int)$s['is_enabled']===1 && !empty($s['check_url']) && $s['last_ping_ok']===0)
            $out[] = [0,'🔴','Сервер '.$s['code'].' ('.$s['ip'].') недоступен — endpoint не отвечает', 'последний пинг: '.($s['last_ping_at'] ?: '—'), 'Проверить сервер/endpoint'];
        elseif ((int)$s['is_enabled']===1 && is_stale($s['last_seen_at']))
            $out[] = [0,'🔕','Сервер '.$s['code'].' ('.$s['ip'].') молчит >1ч', 'последний приём: '.($s['last_seen_at'] ?: 'никогда'), 'Проверить money-push и крон на сервере'];
    }
    foreach ($servers as $s) {
        if ((int)$s['is_enabled']!==1) continue;
        $a = $A['srv'][(int)$s['id']] ?? null; if (!$a) continue;
        $cost=(float)$a['cost']; $rev=(float)$a['rev'];
        if ($cost>=2 && ($cost>0 && $rev/$cost<0.4))
            $out[] = [0,'📉','Сервер '.$s['code'].' ('.$s['ip'].') в глубоком минусе (ROI '.roi_s($cost,$rev).')',
                      'расход '.money($cost).' при доходе '.money($rev).' — возможен недобор дохода (напр. MyBid share не в учёте)',
                      'Свериться с дайджестом сервера и коннекторами дохода'];
    }
    foreach ($servers as $s) {
        if ((int)$s['is_enabled']!==1) continue;
        $a=$A['srv'][(int)$s['id']]??null; if(!$a) continue;
        if ((float)$a['cost']>=0.3 && (int)$a['visits']===0)
            $out[] = [1,'👁','Сервер '.$s['code'].' ('.$s['ip'].'): визиты не считаются',
                      'расход '.money((float)$a['cost']).' при 0 визитов за период — вероятно Метрика/трекинг на этом IP не работает',
                      'Починить сбор визитов (счётчик/токен Yandex Metrika)'];
    }
    $burn=[]; foreach ($A['dom'] as $dm=>$v){ $c=(float)$v['cost']; $rv=(float)$v['rev']; if($c>=0.3 && $rv<0.1*$c) $burn[$dm]=$c-$rv; }
    arsort($burn);
    if ($burn) $out[] = [0,'🔥',count($burn).' доменов жгут бюджет без дохода',
                         'минус ≈ '.money(array_sum($burn)).'. Топ: '.implode(', ',array_slice(array_keys($burn),0,6)),
                         'Резать убыточные или чинить монетизацию'];
    /* задвоение в источнике: домен шлётся с >1 включённого сервера */
    $dups = q(db(), "SELECT d.domain, GROUP_CONCAT(DISTINCT s.code ORDER BY s.code SEPARATOR '+') who
      FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1
      WHERE d.d >= (CURDATE() - INTERVAL 6 DAY)
      GROUP BY d.domain HAVING COUNT(DISTINCT s.id) > 1");
    if ($dups) $out[] = [1,'👯','Задвоение в источнике: '.count($dups).' домен(ов) шлются с 2+ серверов',
                         implode('; ', array_map(fn($r)=>$r['domain'].' ('.$r['who'].')', $dups)),
                         'В дашборде дедуплю на чтении — цифры верны. Источник: донор не должен слать домены с выделенным сервером (фикс через root или ТЗ клоду)'];
    /* потеря домена на уровне реестра. Изнутри сетки это неотличимо от «домен
       перестал приносить»: трафик просто исчезает. Причина видна только у реестра. */
    try {
        $reg = q(db(), "SELECT domain, registrar, verdict, reason, expires_at FROM domain_registrar WHERE verdict IN ('dead','warn')");
        $dead = array_values(array_filter($reg, fn($r)=>$r['verdict']==='dead'));
        $byReg = [];
        foreach ($dead as $r) $byReg[$r['registrar'] ?: '—'][] = $r['domain'];
        foreach ($byReg as $rg=>$ds) {
            /* разом упало несколько доменов одного регистратора — это аккаунт, а не домены */
            if (count($ds) >= 3)
                $out[] = [0,'🛂','Похоже на блокировку аккаунта у '.$rg.': разом легло '.count($ds).' доменов',
                          implode(', ', array_slice($ds, 0, 12)),
                          'Разбираться с регистратором и переносить домены. Кампании Kadam по ним — остановить, трафик уходит в никуда'];
            else foreach ($ds as $d) {
                $why = '';
                foreach ($dead as $r) if ($r['domain']===$d) { $why = (string)$r['reason']; break; }
                $out[] = [0,'🛂','Домен '.$d.' потерян на уровне реестра ('.$rg.')', $why,
                          'Проверить домен у регистратора; кампании Kadam по нему остановить'];
            }
        }
        $soon = [];
        foreach ($reg as $r) {
            if ($r['verdict']==='dead' || !$r['expires_at']) continue;
            $left = (int)floor((strtotime($r['expires_at'])-time())/86400);
            if ($left <= 30) $soon[$r['domain']] = $left;
        }
        asort($soon);
        if ($soon) $out[] = [1,'📆',count($soon).' домен(ов) с истекающим сроком (<30 дней)',
                             implode(', ', array_map(fn($d,$n)=>"$d — $n д.", array_keys($soon), $soon)),
                             'Продлить у регистратора, пока домен не ушёл в hold'];
    } catch (Throwable $e) { /* обхода реестра ещё не было — не повод ронять страницу */ }
    usort($out, fn($a,$b)=>$a[0]<=>$b[0]);
    return $out;
}

function head(string $active, int $days, bool $yest=false): void {
    $qs = fn(string $v)=> $yest ? '?view='.$v.'&day=y' : '?view='.$v.'&days='.$days;
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    if ($active === 'overview') echo '<meta http-equiv="refresh" content="60">';
    if ($active === 'agents') echo '<meta http-equiv="refresh" content="15">';
    echo '<title>Аналитический центр · сетка</title>';
    echo '<script src="vendor/chart.umd.min.js"></script>';
    echo '<style>'.css().'</style></head><body>';
    echo '<header class="top"><div class="brand">📊 Центр аналитики <span class="dim">· сетка</span></div><nav class="menu">';
    foreach (VIEWS as $v=>$label)
        echo '<a class="'.($v===$active?'on':'').'" href="'.h($qs($v)).'">'.h($label).'</a>';
    /* Соседние части системы: живут отдельным приложением в /pilot, но для человека
       это те же вкладки. Ведут наружу, поэтому не проходят через роутер VIEWS. */
    foreach (EXTRA_TABS as $url=>$label)
        echo '<a class="ext" href="'.h($url).'">'.h($label).'</a>';
    echo '</nav><div class="period">';
    foreach ([1=>'сутки',7=>'7д',30=>'30д',90=>'90д'] as $n=>$t)
        echo '<a class="'.((!$yest && $days===$n)?'on':'').'" href="?view='.h($active).'&days='.$n.'">'.$t.'</a>';
    echo '<a class="'.($yest?'on':'').'" href="?view='.h($active).'&day=y">вчера</a>';
    echo '</div></header><main>';
}
function foot(): void { echo '</main></body></html>'; }

function css(): string { return <<<CSS
:root{--bg:#eef2f7;--card:#ffffff;--card2:#f8fafc;--line:#e5e9f0;--tx:#0f172a;--dim:#64748b;
  --pos:#16a34a;--neg:#dc2626;--warn:#d97706;--acc:#2563eb;--acc-soft:#eef2ff;
  --sh:0 1px 2px rgba(15,23,42,.04),0 1px 3px rgba(15,23,42,.08);--sh-lg:0 4px 12px rgba(15,23,42,.08)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit}
.top{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:16px;flex-wrap:wrap;
  padding:11px 20px;background:#fff;border-bottom:1px solid var(--line);box-shadow:var(--sh)}
.brand{font-weight:700;letter-spacing:-.01em} .dim{color:var(--dim);font-weight:400}
.menu{display:flex;gap:3px;flex-wrap:wrap} .menu a{padding:6px 12px;border-radius:8px;color:var(--dim);text-decoration:none;font-size:13px;font-weight:500}
.menu a:hover{background:var(--acc-soft);color:var(--acc)} .menu a.on{background:var(--acc);color:#fff;font-weight:600;box-shadow:0 1px 2px rgba(37,99,235,.3)}
.period{margin-left:auto;display:flex;gap:3px} .period a{padding:5px 10px;border-radius:7px;color:var(--dim);text-decoration:none;font-size:12px;font-weight:500}
.period a:hover{background:var(--card2)} .period a.on{color:var(--acc);background:var(--acc-soft)}
main{padding:20px;max-width:1240px;margin:0 auto}
h1{font-size:19px;margin:0 0 14px;font-weight:700;letter-spacing:-.01em} h2{font-size:14px;margin:0 0 10px;font-weight:650} h2 small{color:var(--dim);font-weight:400;font-size:12px} .sub{color:var(--dim);font-size:12.5px;margin:-8px 0 16px}
.pos{color:var(--pos)} .neg{color:var(--neg)} .zero{color:var(--dim)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.kpi{position:relative;overflow:hidden;border:1px solid rgba(255,255,255,.65);border-radius:14px;padding:14px;box-shadow:var(--sh);
  background:linear-gradient(135deg,rgba(255,255,255,.72),rgba(255,255,255,.42));backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.kpi::after{content:'';position:absolute;inset:0;border-radius:14px;background:linear-gradient(140deg,rgba(255,255,255,.55),transparent 55%);pointer-events:none}
.kpi>*{position:relative;z-index:1}
.kpis .kpi:nth-child(8n+1){background:linear-gradient(135deg,#e6efff,#f5f9ff)}
.kpis .kpi:nth-child(8n+2){background:linear-gradient(135deg,#e5fbef,#f3fffa)}
.kpis .kpi:nth-child(8n+3){background:linear-gradient(135deg,#fdecef,#fff6f8)}
.kpis .kpi:nth-child(8n+4){background:linear-gradient(135deg,#fff3e2,#fffaf1)}
.kpis .kpi:nth-child(8n+5){background:linear-gradient(135deg,#efe8ff,#f8f4ff)}
.kpis .kpi:nth-child(8n+6){background:linear-gradient(135deg,#e3f6fb,#f1fbff)}
.kpis .kpi:nth-child(8n+7){background:linear-gradient(135deg,#fbe9fc,#fef4ff)}
.kpis .kpi:nth-child(8n){background:linear-gradient(135deg,#edeff4,#f8fafc)}
.kpi .l{color:var(--dim);font-size:12px;font-weight:500} .kpi .v{font-size:22px;font-weight:700;margin-top:3px;letter-spacing:-.02em}
a.tilewrap{text-decoration:none;color:inherit;display:block}
a.tilewrap .tile{transition:transform .12s ease,box-shadow .12s ease,border-color .12s ease;cursor:pointer}
a.tilewrap:hover .tile{transform:translateY(-2px);box-shadow:var(--sh-lg);border-color:var(--acc)}
.grid{display:grid;gap:14px} .g2{grid-template-columns:repeat(auto-fit,minmax(340px,1fr))} .g3{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px;overflow-x:auto;box-shadow:var(--sh)}
.hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:14px}
.hero .h{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--acc);border-radius:14px;padding:16px 18px;box-shadow:var(--sh)}
.hero .h .l{color:var(--dim);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.hero .h .v{font-size:30px;font-weight:750;margin-top:4px;line-height:1.1;letter-spacing:-.02em}
.hero .h .s{color:var(--dim);font-size:12.5px;margin-top:7px}
.hero .h .s b{color:var(--tx);font-weight:650}
.chartbox{position:relative;width:100%;height:200px}
.chartbox canvas{position:absolute;inset:0;width:100%!important;height:100%!important}
.sigs{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
.sig{display:flex;gap:10px;align-items:flex-start;background:var(--card2);border:1px solid var(--line);border-left:3px solid var(--dim);border-radius:0 10px 10px 0;padding:11px 13px}
.sig.bad{border-left-color:var(--neg)} .sig.warn{border-left-color:var(--warn)} .sig.good{border-left-color:var(--pos)}
.sig .ico{font-size:16px;line-height:1.2} .sig .tx .t{font-weight:600;font-size:13px} .sig .tx .d{color:var(--dim);font-size:12px}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:6px;box-shadow:var(--sh)}
.tile .hd{display:flex;justify-content:space-between;align-items:baseline;gap:6px}
.tile .code{font-weight:650} .tile .net{font-size:11px;color:var(--dim);border:1px solid var(--line);border-radius:5px;padding:1px 6px;white-space:nowrap}
.tile .big{font-size:21px;font-weight:750;letter-spacing:-.02em} .tile .row{display:flex;justify-content:space-between;font-size:12px;color:var(--dim)}
.spark{height:34px;width:100%}
table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums;min-width:560px}
th,td{text-align:right;padding:9px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
th:first-child,td:first-child{text-align:left} th{color:var(--dim);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.02em;border-bottom:1.5px solid var(--line)}
tbody tr:hover td,table tr:hover td{background:var(--card2)} tr:last-child td{border-bottom:none}
.badge{font-size:11px;color:var(--dim);border:1px solid var(--line);border-radius:6px;padding:2px 7px;margin:0 4px 4px 0;text-decoration:none;display:inline-block} .badge:hover{background:var(--acc-soft);color:var(--acc);border-color:var(--acc)}
.note{color:var(--dim);font-size:12px;margin-top:8px}
.stale{color:var(--warn);font-weight:600} .agg{color:var(--dim);font-size:11px;border:1px solid var(--line);border-radius:5px;padding:1px 5px;margin-left:4px}
.pill{display:inline-block;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:4px 11px;font-size:12px;color:var(--dim);margin:0 8px 8px 0;box-shadow:var(--sh)}
.flag{border:1px solid var(--line);border-left:3px solid var(--warn);padding:10px 14px;background:var(--card);border-radius:0 10px 10px 0;margin-bottom:9px;box-shadow:var(--sh)}
.flag.bad{border-left-color:var(--neg)} .flag.ok{border-left-color:var(--pos)}
.flag .t{font-weight:650} .flag .d{color:var(--dim);font-size:12.5px;margin-top:2px}
canvas{max-width:100%}
.sel{padding:7px 11px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--tx);font-size:13px;font-weight:500;cursor:pointer;box-shadow:var(--sh)}
.sel:focus{outline:none;border-color:var(--acc)}
.ctrls{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ctrls label{color:var(--dim);font-size:12px;font-weight:600}
.srvbar,.dombar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.dombar{max-height:112px;overflow-y:auto}
.stab,.dtab{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:500;cursor:pointer;color:var(--tx);box-shadow:var(--sh);display:inline-flex;gap:6px;align-items:center;white-space:nowrap}
.stab:hover,.dtab:hover{border-color:var(--acc)}
.stab.on,.dtab.on{background:var(--acc);color:#fff;border-color:var(--acc)}
.stab.on .pos,.stab.on .neg,.stab.on .zero{color:#fff}
.dtab{font-size:12px;padding:6px 10px;color:var(--dim)}
.lvl{display:flex;gap:16px;align-items:center;background:linear-gradient(135deg,#eaf1ff,#f6f0ff);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:var(--sh-lg);margin-bottom:16px}
.lvl .bdg{font-size:42px;line-height:1;min-width:76px;height:76px;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:18px;box-shadow:var(--sh);border:1px solid var(--line)}
.lvl .info{flex:1;min-width:0}
.lvl .lnum{font-size:12px;font-weight:700;letter-spacing:.08em;color:var(--acc);text-transform:uppercase}
.lvl .lname{font-size:22px;font-weight:800;letter-spacing:-.02em;margin:1px 0 2px}
.lvl .lsub{color:var(--dim);font-size:13px}
.barwrap{margin-bottom:14px}
.barwrap .lab{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;color:var(--dim)}
.barwrap .lab b{font-weight:750;color:var(--tx)}
.bar{height:16px;border-radius:9px;background:#e5e9f0;overflow:hidden;position:relative}
.bar>i{display:block;height:100%;border-radius:9px;background:linear-gradient(90deg,#2563eb,#3ddc84);transition:width .6s ease;box-shadow:0 0 10px rgba(37,99,235,.35)}
.bar.gold>i{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.quest{background:linear-gradient(135deg,#fff6e5,#fffdf6);border:1px solid #f0d9a8;border-radius:16px;padding:16px;box-shadow:var(--sh);margin-bottom:16px}
.quest h2{margin:0 0 4px}
.mbars{display:flex;gap:4px;align-items:flex-end;height:52px;margin-top:10px}
.mbars .mb{flex:1;min-width:6px;background:linear-gradient(180deg,#3ddc84,#2563eb);border-radius:4px 4px 0 0;min-height:3px;position:relative}
.mbars .mb.today{background:linear-gradient(180deg,#f59e0b,#fbbf24)}
.mbars .mb span{position:absolute;bottom:-16px;left:0;right:0;text-align:center;font-size:9px;color:var(--dim)}
.ach{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
.ach .a{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px;text-align:center;box-shadow:var(--sh);opacity:.5;filter:grayscale(.9)}
.ach .a.on{opacity:1;filter:none;border-color:var(--pos);background:linear-gradient(135deg,#eafff3,#fff)}
.ach .a .ic{font-size:28px;line-height:1}.ach .a .n{font-size:12.5px;font-weight:700;margin-top:5px}.ach .a .s{font-size:11px;color:var(--dim);margin-top:1px}
.msg{border:1px solid var(--line);border-radius:10px;padding:9px 12px;margin-bottom:8px;background:var(--card2)}
.msg.center{background:#eef2ff;border-color:#c7d2fe}
.msg .mh{font-size:11.5px;color:var(--dim);margin-bottom:3px;display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.msg .who{font-weight:750;color:var(--tx)} .msg.center .who{color:var(--acc)}
.kbadge{font-size:10px;border-radius:5px;padding:1px 6px;border:1px solid var(--line);text-transform:uppercase;letter-spacing:.02em}
.k-report{color:#2563eb;border-color:#bcd0ff}.k-finding{color:#d97706;border-color:#f0d9a8}.k-question{color:#7c3aed;border-color:#ddd0f7}.k-task{color:#16a34a;border-color:#b6ecc8}.k-answer{color:#0891b2;border-color:#b8e6f0}
.mref{font-family:ui-monospace,Menlo,monospace;font-size:10px;color:var(--dim)}
.mt{margin-left:auto} .mtext{font-size:13.5px;white-space:pre-wrap;word-break:break-word;line-height:1.45}

.fm-prod .fm-note{margin-bottom:12px}
.fm-line{border:1px solid var(--line);border-radius:13px;padding:12px;margin-bottom:10px;background:var(--card2)}
.fm-line-h{display:flex;gap:11px;align-items:flex-start}
.fm-line-h .ic{font-size:26px;line-height:1}
.fm-line-h b{font-size:15px}
.fm-line-s{font-size:12px;color:var(--dim);margin-top:2px}
.fm-line-p{margin-left:auto;text-align:right;font-size:19px;font-weight:800;letter-spacing:-.02em}
.fm-line-p span{display:block;font-size:10px;font-weight:600;color:var(--dim);text-transform:uppercase;letter-spacing:.04em}
.fm-line-f{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}
.fm-line-f .tag{font-size:11.5px;border:1px solid var(--line);border-radius:8px;padding:2px 8px;background:#fff;color:var(--dim)}
.fm-line-f .tag.money{background:#fff6e8;border-color:#f0d9a8;color:#8a5a00;font-weight:700}
.fm-line-f .tag.time{background:#eef2ff;border-color:#c7d2fe;color:#3730a3;font-weight:700}
.fm-lim{font-size:12px;margin-top:8px;padding:8px 10px;border-radius:9px;background:#fdf3f3;border:1px solid #f3d5d5;color:#7a2e2e;line-height:1.5}
.fm-tl{display:grid;gap:8px}
.fm-tl-i{display:flex;gap:12px;align-items:flex-start;border-left:3px solid var(--acc);padding:6px 0 6px 11px}
.fm-tl-d{min-width:64px;font-weight:800;font-size:14px}
.fm-tl-d span{display:block;font-size:10.5px;font-weight:600;color:var(--dim)}
.fm-tl-t{font-size:13px}
.fm-tl-t div{color:var(--dim);font-size:12px;margin-top:2px}
CSS; }
