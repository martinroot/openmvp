<?php
declare(strict_types=1);
/**
 * Аналитический центр сетки — роутер представлений.
 *   ?view=overview|servers|domains|trends|analytics  &days=1|7|30|90
 * Ядро, агрегация и вёрстка — в lib.php.
 */
require __DIR__ . '/lib.php';

$view = (string)($_GET['view'] ?? 'overview');
if (!isset(VIEWS[$view])) $view = 'overview';
$days = (int)($_GET['days'] ?? 7);
if (!in_array($days, [1,7,30,90], true)) $days = 7;

if (($_SERVER['REQUEST_METHOD']??'')==='POST' && (($_POST['action']??'')==='savemeta')) {
    header('Content-Type: application/json; charset=utf-8');
    $dm=trim((string)($_POST['domain']??''));
    $dn=substr(trim((string)($_POST['donor']??'')),0,64);
    $md=((int)($_POST['modernized']??0))?1:0;
    if($dm===''){ echo json_encode(['error'=>'no domain']); exit; }
    $st=db()->prepare("INSERT INTO domain_meta(domain,html_donor,modernized) VALUES(?,?,?) ON DUPLICATE KEY UPDATE html_donor=VALUES(html_donor),modernized=VALUES(modernized)");
    $st->bind_param('ssi',$dm,$dn,$md); $st->execute();
    echo json_encode(['ok'=>true]); exit;
}

if (($_SERVER['REQUEST_METHOD']??'')==='POST' && (($_POST['action']??'')==='snap')) {
    header('Content-Type: application/json; charset=utf-8');
    $dm=trim((string)($_POST['domain']??'')); if($dm===''){echo json_encode(['error'=>'no domain']);exit;}
    $dir=__DIR__.'/shots'; @mkdir($dir,0777);
    $cap=function($url,$out){ $u=escapeshellarg('https://image.thum.io/get/width/460/crop/560/noanimate/'.$url); $o=escapeshellarg($out.'.tmp'); @exec("curl -s --max-time 30 -o $o ".$u); if(is_file($out.'.tmp')&&filesize($out.'.tmp')>1000){ @rename($out.'.tmp',$out); return true; } @unlink($out.'.tmp'); return false; };
    $cap('https://'.$dm, $dir.'/site_'.md5($dm).'.jpg');
    $row=q(db(),"SELECT html_donor FROM domain_meta WHERE domain=?",[$dm],'s');
    $dn=(string)($row[0]['html_donor']??'');
    if($dn!==''){ $cat=json_decode((string)@file_get_contents(__DIR__.'/donors.json'),true); $u=''; foreach(($cat['templates']??[]) as $t){ if(($t['id']??'')===$dn){ $u=$t['donors']['index']??''; break; } } if($u) $cap($u,$dir.'/tpl_'.$dn.'.jpg'); }
    echo json_encode(['ok'=>true,'ts'=>time()]); exit;
}

/* правка дерева: структуру ведёт владелец, поэтому всё пишется сразу в базу */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string)($_POST['action'] ?? ''), 'node_')) {
    header('Content-Type: application/json; charset=utf-8');
    $act = (string)$_POST['action'];
    $db  = db();
    $cut = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);

    if ($act === 'node_add') {
        $title = $cut('title', 190);
        if ($title === '') { echo json_encode(['error' => 'нужно название']); exit; }
        $par = trim((string)($_POST['parent'] ?? ''));
        $parent = $par === '' ? null : (int)$par;
        $ico = $cut('ico', 8) ?: '•'; $descr = $cut('descr', 600);
        $bind = $cut('bind', 190); $link = $cut('link', 255);
        $sort = (int)(q($db, "SELECT COALESCE(MAX(sort),-1)+1 s FROM tree_node WHERE parent_id <=> ?", [$parent])[0]['s'] ?? 0);
        $st = $db->prepare("INSERT INTO tree_node (parent_id, sort, ico, title, descr, bind, link) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param('iisssss', $parent, $sort, $ico, $title, $descr, $bind, $link);
        $st->execute();
        echo json_encode(['ok' => true, 'id' => $db->insert_id]); exit;
    }
    if ($act === 'node_save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = $cut('title', 190);
        if (!$id || $title === '') { echo json_encode(['error' => 'нужны id и название']); exit; }
        $ico = $cut('ico', 8) ?: '•'; $descr = $cut('descr', 600);
        $bind = $cut('bind', 190); $link = $cut('link', 255);
        $st = $db->prepare("UPDATE tree_node SET ico=?, title=?, descr=?, bind=?, link=? WHERE id=?");
        $st->bind_param('sssssi', $ico, $title, $descr, $bind, $link, $id);
        $st->execute();
        echo json_encode(['ok' => true]); exit;
    }
    if ($act === 'node_del') {
        /* удаляем поддерево целиком: висячие дети были бы хуже потери узла */
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'нужен id']); exit; }
        $kill = [$id]; $queue = [$id];
        while ($queue) {
            $cur = array_shift($queue);
            foreach (q($db, "SELECT id FROM tree_node WHERE parent_id = ?", [$cur]) as $r) {
                $kill[] = (int)$r['id']; $queue[] = (int)$r['id'];
            }
        }
        $in = implode(',', array_map('intval', $kill));
        $db->query("DELETE FROM tree_node WHERE id IN ($in)");
        echo json_encode(['ok' => true, 'removed' => count($kill)]); exit;
    }
    if ($act === 'node_move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = (string)($_POST['dir'] ?? 'up');
        $me  = q($db, "SELECT id, parent_id, sort FROM tree_node WHERE id = ?", [$id]);
        if (!$me) { echo json_encode(['error' => 'нет такого узла']); exit; }
        $me = $me[0]; $pid = $me['parent_id'];
        $sib = q($db, "SELECT id FROM tree_node WHERE parent_id <=> ? ORDER BY sort, id", [$pid]);
        $ids = array_map(fn($r) => (int)$r['id'], $sib);
        $pos = array_search($id, $ids, true);
        $to  = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && $to >= 0 && $to < count($ids)) {
            [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
            foreach ($ids as $k => $nid) {
                $st = $db->prepare("UPDATE tree_node SET sort=? WHERE id=?");
                $st->bind_param('ii', $k, $nid); $st->execute();
            }
        }
        echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['error' => 'неизвестное действие']); exit;
}

/* первичное заполнение дерева из данных */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'tree_seed')) {
    if (empty($_POST['empty'])) tree_seed((int)($_GET['days'] ?? 7) ?: 7);
    else {
        $st = db()->prepare("INSERT INTO tree_node (parent_id, sort, ico, title, descr) VALUES (NULL,0,'📁','Новый раздел','')");
        $st->execute();
    }
    header('Location: ?view=tree&days='.(int)($_GET['days'] ?? 7));
    exit;
}

/* отметка «коснулся»: пишем по дню, чтобы вечером был счётчик действий */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'touch')) {
    header('Content-Type: application/json; charset=utf-8');
    $node = substr(trim((string)($_POST['node'] ?? '')), 0, 190);
    $on   = ((string)($_POST['on'] ?? '1')) === '1';
    if ($node === '') { echo json_encode(['error' => 'no node']); exit; }
    $d = (new DateTime())->format('Y-m-d');
    if ($on) {
        $st = db()->prepare("INSERT INTO tree_touch (d, node, ts) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE ts=NOW()");
        $st->bind_param('ss', $d, $node);
    } else {
        $st = db()->prepare("DELETE FROM tree_touch WHERE d=? AND node=?");
        $st->bind_param('ss', $d, $node);
    }
    $st->execute();
    $n = (int)(q(db(), "SELECT COUNT(*) c FROM tree_touch WHERE d=?", [$d])[0]['c'] ?? 0);
    echo json_encode(['ok' => true, 'count' => $n]);
    exit;
}

/* AJAX-эндпоинты: JSON без вёрстки */
/* дерево как данные: отдаём до вёрстки, иначе JSON уедет под шапку страницы */
if ($view === 'tree' && (($_GET['format'] ?? '') === 'json')) {
    header('Content-Type: application/json; charset=utf-8');
    $ctxJ = tree_ctx($days);
    $byJ  = tree_rows();
    $walk = function(?int $parent) use (&$walk, $byJ, $ctxJ): array {
        $out = [];
        foreach ($byJ[(int)$parent] ?? [] as $n) {
            [$stJ, $mtJ] = bind_resolve((string)$n['bind'], $ctxJ);
            $out[] = ['id'=>(int)$n['id'], 'ico'=>$n['ico'], 'title'=>$n['title'], 'desc'=>$n['descr'],
                      'bind'=>$n['bind'], 'link'=>$n['link'], 'state'=>$stJ, 'metric'=>$mtJ,
                      'kids'=>$walk((int)$n['id'])];
        }
        return $out;
    };
    echo json_encode($walk(null), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (($view === 'chart' || $view === 'live') && (($_GET['format'] ?? '') === 'json')) {
    header('Content-Type: application/json; charset=utf-8');
    echo $view === 'live' ? live_json_data() : chart_json_data();
    exit;
}

$yest = ((string)($_GET['day'] ?? '') === 'y');
$yd = (new DateTime('-1 day'))->format('Y-m-d');
$C = $yest ? canon_range($yd,$yd) : canon($days);
$A = aggregate($C['rows']);
$servers = servers_all();
$today = (new DateTime())->format('Y-m-d');

$totCost = array_sum(array_column($A['day'],'cost'));
$totRev  = array_sum(array_column($A['day'],'rev'));
$totProfit = $totRev - $totCost;

/* админ-API списка сервисов (для icrm): X-Admin-Key + POST JSON */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_SERVER['HTTP_X_ADMIN_KEY'])) {
    header('Content-Type: application/json; charset=utf-8');
    $adminKey = trim((string)@file_get_contents(__DIR__ . '/admin_key'));
    if ($adminKey === '' || !hash_equals($adminKey, (string)$_SERVER['HTTP_X_ADMIN_KEY'])) {
        http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
    }
    $op = json_decode((string)file_get_contents('php://input'), true);
    $act = (string)($op['action'] ?? '');
    try {
        if ($act === 'rename') {
            $id = (int)($op['id'] ?? 0); $code = trim((string)($op['code'] ?? ''));
            if ($id <= 0 || $code === '' || mb_strlen($code) > 64) throw new Exception('bad args');
            $st = db()->prepare("UPDATE servers SET code=? WHERE id=?");
            $st->bind_param('si', $code, $id); $st->execute();
            echo json_encode(['ok' => true]); exit;
        }
        if ($act === 'add') {
            $code = trim((string)($op['code'] ?? '')); $ip = trim((string)($op['ip'] ?? ''));
            $kind = trim((string)($op['kind'] ?? 'tube'));
            if (!in_array($kind, ['tube', 'tracker', 'game', 'win', 'edge'], true)) $kind = 'tube';
            if ($code === '' || mb_strlen($code) > 64 || filter_var($ip, FILTER_VALIDATE_IP) === false) throw new Exception('bad args');
            $token = bin2hex(random_bytes(32));
            $th = hash('sha256', $token);
            $st = db()->prepare("INSERT INTO servers (code, ip, note, token_hash, last_ip, kind) VALUES (?,?,?,?, '', ?)");
            $st->bind_param('sssss', $code, $ip, $code, $th, $kind);
            $st->execute();
            echo json_encode(['ok' => true, 'id' => db()->insert_id, 'token' => $token]); exit;
        }
        if ($act === 'toggle') {
            $id = (int)($op['id'] ?? 0); $en = (int)!empty($op['enabled']);
            if ($id <= 0) throw new Exception('bad args');
            $st = db()->prepare("UPDATE servers SET is_enabled=? WHERE id=?");
            $st->bind_param('ii', $en, $id); $st->execute();
            echo json_encode(['ok' => true]); exit;
        }
        if ($act === 'check') {
            $id = (int)($op['id'] ?? 0); $url = trim((string)($op['check_url'] ?? ''));
            if ($id <= 0 || mb_strlen($url) > 255) throw new Exception('bad args');
            if ($url !== '' && !preg_match('#^https?://#i', $url)) throw new Exception('bad url');
            if ($url === '') { /* пустое поле = не трогать существующий endpoint (защита от затирания формой без поля) */
                echo json_encode(['ok' => true, 'kept' => true]); exit;
            }
            $st = db()->prepare("UPDATE servers SET check_url=? WHERE id=?");
            $st->bind_param('si', $url, $id); $st->execute();
            echo json_encode(['ok' => true]); exit;
        }
        if ($act === 'ping') {
            // CRM сам опрашивает endpoint'ы сервисов; 2xx = на связи
            $res = [];
            $q = db()->query("SELECT id, code, check_url FROM servers WHERE is_enabled=1 AND check_url <> ''");
            while ($r = $q->fetch_assoc()) {
                $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: icrm-money-ping/1.0\r\n"]]);
                $code = 0;
                $body = @file_get_contents($r['check_url'], false, $ctx);
                if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $mm)) $code = (int)$mm[1];
                $ok = $code >= 200 && $code < 300;
                $res[$r['code']] = ['code' => $code, 'ok' => $ok];
                // фиксируем КАЖДУЮ попытку: неудача тоже факта ради
                $st = db()->prepare("UPDATE servers SET last_ping_ok=?, last_ping_at=NOW(), last_seen_at=IF(?=1,NOW(),last_seen_at) WHERE id=?");
                $okint = $ok ? 1 : 0;
                $st->bind_param('iii', $okint, $okint, $r['id']); $st->execute();
            }
            echo json_encode(['ok' => true, 'ping' => $res]); exit;
        }
        if ($act === 'kind') {
            $id = (int)($op['id'] ?? 0); $kind = trim((string)($op['kind'] ?? ''));
            if ($id <= 0 || !in_array($kind, ['tube', 'tracker', 'game', 'win', 'edge'], true)) throw new Exception('bad args');
            $st = db()->prepare("UPDATE servers SET kind=? WHERE id=?");
            $st->bind_param('si', $kind, $id); $st->execute();
            echo json_encode(['ok' => true]); exit;
        }
        if ($act === 'ip') {
            $id = (int)($op['id'] ?? 0); $ip = trim((string)($op['ip'] ?? ''));
            if ($id <= 0 || filter_var($ip, FILTER_VALIDATE_IP) === false) throw new Exception('bad args');
            $st = db()->prepare("UPDATE servers SET ip=? WHERE id=?");
            $st->bind_param('si', $ip, $id); $st->execute();
            echo json_encode(['ok' => true]); exit;
        }
        if ($act === 'delete') {
            $id = (int)($op['id'] ?? 0);
            if ($id <= 0) throw new Exception('bad args');
            $st = db()->prepare("DELETE FROM servers WHERE id=?");
            $st->bind_param('i', $id); $st->execute();
            echo json_encode(['ok' => true]); exit;
        }
        throw new Exception('unknown action');
    } catch (Exception $e) {
        http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit;
    }
}

/* JSON для встроенных дашбордов icrm */
if (($view === 'servers' || $view === 'days') && (($_GET['format'] ?? '') === 'json')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: https://wifepornhub.ru');
    if ($view === 'servers') {
        $ttc = array_sum(array_column($A['day'],'cost'));
        $ttr = array_sum(array_column($A['day'],'rev'));
        $out = ['ok'=>true, 'days'=>$days, 'today'=>$today,
                'total'=>['cost'=>round($ttc,2), 'rev'=>round($ttr,2), 'profit'=>round($ttr-$ttc,2)],
                'servers'=>[]];
        usort($servers, function($a,$b) use ($A){
            $ea=(int)$a['is_enabled']; $eb=(int)$b['is_enabled']; if($ea!==$eb) return $eb<=>$ea;
            $ra=(float)($A['srv'][(int)$a['id']]['rev']??0); $rb=(float)($A['srv'][(int)$b['id']]['rev']??0);
            return $rb<=>$ra;
        });
        foreach ($servers as $s) {
            $sid=(int)$s['id']; $a=$A['srv'][$sid]??['cost'=>0,'rev'=>0,'clicks'=>0,'visits'=>0,'doms'=>[]];
            $cost=(float)$a['cost']; $rev=(float)$a['rev'];
            $out['servers'][] = [
                'id'=>$sid, 'ip'=>(string)($s['ip']??''), 'code'=>(string)($s['code']??''), 'check_url'=>(string)($s['check_url']??''),
                'kind'=>(string)($s['kind']??'tube'), 'enabled'=>(int)$s['is_enabled'],
                'last_seen'=>(string)($s['last_seen_at']??''),
                'st'=>(isset($s['last_ping_ok']) && $s['last_ping_ok']===0) ? 'fail' : null,
                'cost'=>round($cost,2), 'rev'=>round($rev,2), 'profit'=>round($rev-$cost,2),
                'roi'=> $cost>0 ? round($rev/$cost,2) : null,
                'domains'=>count($a['doms']??[]), 'visits'=>(int)$a['visits'], 'clicks'=>(int)$a['clicks'],
                'series'=> array_map('round', (array)($A['srvDay'][$sid]??[]), [2]),
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $ttc = array_sum(array_column($A['day'],'cost'));
        $ttr = array_sum(array_column($A['day'],'rev'));
        $rows = [];
        foreach ($A['day'] as $d => $v) $rows[$d] = ['d'=>$d, 'cost'=>round((float)$v['cost'],2), 'rev'=>round((float)$v['rev'],2), 'profit'=>round((float)$v['rev']-(float)$v['cost'],2)];
        krsort($rows);
        echo json_encode(['ok'=>true, 'days'=>$days, 'today'=>$today,
            'total'=>['cost'=>round($ttc,2), 'rev'=>round($ttr,2), 'profit'=>round($ttr-$ttc,2)],
            'days'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

head($view, $days, $yest);
switch ($view) {
    case 'live':      view_live(); break;
    case 'agents':    view_agents(); break;
    case 'mainloop':  view_mainloop(); break;
    case 'days':      view_days($days); break;
    case 'progress':  view_progress($A,$servers,$today); break;
    case 'chart':     view_chart($C,$A,$servers,$days,$today); break;
    case 'wall':      view_wall($A,$servers,$today); break;
    case 'trackers':  view_trackers($days); break;
    case 'issues':    view_issues($A,$servers); break;
    case 'servers':   view_servers($A,$servers,$days); break;
    case 'domains':   view_domains($A, $days); break;
    case 'templates': view_templates($A); break;
    case 'registrar': view_registrar($A, $days); break;
    case 'games':     view_games($days); break;
    case 'farms':     view_farms($days); break;
    case 'idle':      view_idle($days); break;
    case 'tree':      view_tree($days); break;
    case 'trends':    view_trends($A); break;
    case 'analytics': view_analytics($C,$A,$servers,$today); break;
    default:          view_overview($C,$A,$servers,$totCost,$totRev,$totProfit,$today);
}
foot();

/* ===================== ОБЗОР ===================== */
function view_overview(array $C, array $A, array $servers, float $tc, float $tr, float $tp, string $today): void {
    $roi = $tc>0 ? $tr/$tc : null;
    $active = array_values(array_filter($servers, fn($s)=>(int)$s['is_enabled']===1));
    $parked = array_values(array_filter($servers, fn($s)=>(int)$s['is_enabled']===0));
    $staleN = 0; foreach ($active as $s) if (is_stale($s['last_seen_at'])) $staleN++;
    $li = last_ingest();
    $collapsed = $C['raw_count'] - count($C['rows']);

    /* прогноз на сегодня + проекция */
    $doneDays = array_filter($A['day'], fn($v,$d)=>$d!==$today, ARRAY_FILTER_USE_BOTH);
    $avg = $doneDays ? array_sum(array_map(fn($v)=>$v['rev']-$v['cost'],$doneDays))/count($doneDays) : 0.0;
    $todayP = isset($A['day'][$today]) ? $A['day'][$today]['rev']-$A['day'][$today]['cost'] : 0.0;
    $frac = ((int)gmdate('H')*3600 + (int)gmdate('i')*60)/86400;
    $fc = $frac>0.05 ? $todayP/$frac : null;
    $proj30 = $avg*30;

    echo '<h1>Обзор сети</h1>';
    echo '<div class="sub">'.h($C['from']).' … '.h($today).' · '.gmdate('H:i').' UTC · автообновление 60с'.($li?' · последний приём '.h((string)$li):'').'</div>';

    /* ---- ГЕРОЙ: главное сразу ---- */
    echo '<div class="hero">';
    hero('Профит за период', dot($tp).' '.money($tp),
         'ROI <b>'.($roi===null?'—':number_format($roi,2).'x').'</b> · доход '.money($tr).' / расход '.money($tc), cls($tp));
    hero('Сегодня · прошло '.round($frac*100).'% суток', dot($todayP).' '.money($todayP),
         $fc!==null ? 'прогноз к полуночи ≈ <b class="'.cls($fc).'">'.money($fc).'</b>' : 'копим данные', cls($todayP));
    hero('Проекция на 30 дней', ($proj30>=0?'🟢':'🔴').' '.money($proj30),
         'средний завершённый день <b>'.money($avg).'</b>', cls($proj30));
    echo '</div>';

    /* ---- KPI ---- */
    echo '<div class="kpis">';
    kpi('Доход', money($tr));
    kpi('Расход', money($tc));
    kpi('Серверов', (string)count($servers), '', count($active).' в учёте · '.count($parked).' парк');
    kpi('На связи', (count($active)-$staleN).' / '.count($active));
    kpi('Доменов', (string)count($A['dom']));
    if ($collapsed>0) kpi('Свёрнуто дублей', (string)$collapsed);
    echo '</div>';

    /* ---- ЧТО ПРОИСХОДИТ: сигналы ---- */
    $sigs = [];
    $stale = array_filter($active, fn($s)=>is_stale($s['last_seen_at']));
    if ($stale) $sigs[]=['bad','🔕',count($stale).' сервер(ов) молчат >1ч', implode(', ', array_map(fn($s)=>$s['code'],$stale))];
    $burn=[]; foreach ($A['dom'] as $dm=>$v){ $c=(float)$v['cost']; $rv=(float)$v['rev']; if($c>=0.3 && $rv<0.1*$c) $burn[$dm]=$c-$rv; }
    arsort($burn);
    if ($burn) $sigs[]=['bad','🔥',count($burn).' доменов жгут бюджет без дохода','минус ≈ '.money(array_sum($burn)).' · '.implode(', ',array_slice(array_keys($burn),0,3))];
    if ($fc!==null) $sigs[]=[$fc>=$avg?'good':'warn', $fc>=$avg?'📈':'📉', 'Темп сегодня '.($fc>=$avg?'выше':'ниже').' среднего', 'прогноз '.money($fc).' против ср. '.money($avg)];
    /* лучший/худший сервер за период */
    $sp=[]; foreach ($active as $s){ $a=$A['srv'][(int)$s['id']]??null; if($a) $sp[$s['code']]=(float)$a['rev']-(float)$a['cost']; }
    if ($sp){ arsort($sp); $best=array_key_first($sp); $bestV=(float)reset($sp);
        $sigs[]=['good','🏆','Лучший сервер: '.$best, money($bestV).' профита за период'];
    }
    if ($parked) $sigs[]=['warn','⏸',count($parked).' сервера не в учёте', implode(', ', array_map(fn($s)=>$s['code'].' ('.$s['ip'].')',$parked))];
    echo '<div class="card"><h2>Что происходит</h2><div class="sigs">';
    foreach (array_slice($sigs,0,6) as [$lvl,$ico,$t,$d])
        echo '<div class="sig '.$lvl.'"><div class="ico">'.$ico.'</div><div class="tx"><div class="t">'.h($t).'</div><div class="d">'.h($d).'</div></div></div>';
    echo '</div></div>';

    /* ---- графики (фикс. высота, не уезжают) ---- */
    $labels=[]; $prof=[]; $rev=[]; $cost=[];
    foreach ($A['day'] as $d=>$v){ $labels[]=substr($d,5); $prof[]=round($v['rev']-$v['cost'],4); $rev[]=round($v['rev'],4); $cost[]=round($v['cost'],4); }
    echo '<div class="grid g2">';
    echo '<div class="card"><h2>Профит по дням</h2><div class="chartbox"><canvas id="cProfit"></canvas></div></div>';
    echo '<div class="card"><h2>Доход и расход по дням</h2><div class="chartbox"><canvas id="cRC"></canvas></div></div>';
    echo '</div>';

    /* ---- внутридневной график по минутам + пунктирный прогноз EOD ---- */
    $intr = q(db(), "SELECT ts,cost,revenue FROM intraday WHERE ts >= UTC_DATE() ORDER BY ts");
    $iL=[]; $iC=[]; $iR=[];
    foreach ($intr as $row){ $iL[]=substr((string)$row['ts'],11,5); $iC[]=round((float)$row['cost'],4); $iR[]=round((float)$row['revenue'],4); }
    $n=count($intr); $projC=[]; $projR=[];
    if ($n>0){
        $lastC=(float)end($iC); $lastR=(float)end($iR);
        $eodC=$frac>0.05?$lastC/$frac:$lastC; $eodR=$frac>0.05?$lastR/$frac:$lastR;
        $iL[]='24:00'; $iC[]=null; $iR[]=null;
        $projC=array_fill(0,$n+1,null); $projC[$n-1]=round($lastC,4); $projC[$n]=round($eodC,4);
        $projR=array_fill(0,$n+1,null); $projR[$n-1]=round($lastR,4); $projR[$n]=round($eodR,4);
    }
    echo '<div class="card"><h2>Доход и расход сегодня · по минутам <small>· пунктир — прогноз на конец дня</small></h2>';
    if ($n>0) echo '<div class="chartbox" style="height:230px"><canvas id="cIntra"></canvas></div>';
    else echo '<div class="note">Кривая копится с этой минуты (снимок раз в минуту). Загляни через несколько минут — точки появятся, а пунктиром пойдёт прогноз на конец дня.</div>';
    echo '</div>';

    /* ---- лидеры ---- */
    $doms=[]; foreach ($A['dom'] as $dm=>$v){ $v['domain']=$dm; $v['p']=(float)$v['rev']-(float)$v['cost']; $doms[]=$v; }
    usort($doms, fn($a,$b)=>$b['p']<=>$a['p']);
    echo '<div class="grid g2">';
    echo '<div class="card"><h2>Топ прибыльных</h2>'; mini_dom_table(array_slice($doms,0,7)); echo '</div>';
    echo '<div class="card"><h2>Топ убыточных</h2>'; mini_dom_table(array_slice(array_reverse($doms),0,7)); echo '</div>';
    echo '</div>';

    chart_js('cProfit', "{type:'bar',data:{labels:".json_encode($labels).",datasets:[{data:".json_encode($prof).
        ",backgroundColor:".json_encode(array_map(fn($x)=>$x<0?'#dc2626':'#16a34a',$prof))."}]},options:".baseOpts()."}");
    chart_js('cRC', "{type:'line',data:{labels:".json_encode($labels).",datasets:[".
        "{label:'доход',data:".json_encode($rev).",borderColor:'#16a34a',tension:.3,fill:false,pointRadius:2},".
        "{label:'расход',data:".json_encode($cost).",borderColor:'#dc2626',tension:.3,fill:false,pointRadius:2}]},options:".baseOpts(true)."}");
    if ($n>0) chart_js('cIntra', "{type:'line',data:{labels:".json_encode($iL).",datasets:[".
        "{label:'доход',data:".json_encode($iR).",borderColor:'#16a34a',tension:.25,pointRadius:0,fill:false,spanGaps:false},".
        "{label:'расход',data:".json_encode($iC).",borderColor:'#dc2626',tension:.25,pointRadius:0,fill:false,spanGaps:false},".
        "{label:'прогноз дохода',data:".json_encode($projR).",borderColor:'#16a34a',borderDash:[6,4],pointRadius:0,fill:false,spanGaps:true},".
        "{label:'прогноз расхода',data:".json_encode($projC).",borderColor:'#dc2626',borderDash:[6,4],pointRadius:0,fill:false,spanGaps:true}]},options:".baseOpts(true)."}");
}
function hero(string $l, string $v, string $s, string $cls=''): void {
    echo '<div class="h"><div class="l">'.h($l).'</div><div class="v '.$cls.'">'.$v.'</div><div class="s">'.$s.'</div></div>';
}

/* ===================== 💬 ЧАТ АГЕНТОВ ===================== */
function view_agents(): void {
    $rows = q(db(), "SELECT id,ts,agent,kind,ref,text FROM agent_chat ORDER BY id DESC LIMIT 200");
    $rows = array_reverse($rows);
    echo '<h1>💬 Чат агентов</h1>';
    echo '<div class="sub">Общее пространство центра и 10 серверных клодов: задачи, находки, отчёты, вопросы. Обновляется каждые 15с.</div>';
    echo '<div class="card">';
    if (!$rows) { echo '<div class="note">Пока тихо. Агенты пишут сюда через /money/api/chat.php.</div>'; }
    foreach ($rows as $m) {
        $center = $m['agent']==='center';
        echo '<div class="msg'.($center?' center':'').'">';
        echo '<div class="mh"><span class="who">'.($center?'🧠 ':'🖥 ').h($m['agent']).'</span>';
        echo '<span class="kbadge k-'.h($m['kind']).'">'.h($m['kind']).'</span>';
        if ($m['ref']!=='') echo '<span class="mref">'.h($m['ref']).'</span>';
        echo '<span class="mt">'.h(substr((string)$m['ts'],5,11)).'</span></div>';
        echo '<div class="mtext">'.h($m['text']).'</div></div>';
    }
    echo '</div>';
    echo '<script>window.scrollTo(0,document.body.scrollHeight);</script>';
}

/* ===================== 🔴 LIVE · нагрузка по минутам ===================== */
function live_json_data(): string {
    $db = db();
    $hours = (int)($_GET['hours'] ?? 3); if(!in_array($hours,[1,3,6],true)) $hours=3;
    $rows = q($db, "SELECT DATE_FORMAT(ts,'%H:%i') hm, ROUND(SUM(cost),6) c, ROUND(SUM(revenue),6) r, SUM(clicks) k, SUM(visits) v
                    FROM intraday_snap WHERE ts >= (UTC_TIMESTAMP() - INTERVAL $hours HOUR) GROUP BY ts ORDER BY ts");
    $labels=[]; $clk=[]; $vis=[]; $cost=[]; $rev=[];
    $pc=null;$pr=0;$pk=0;$pv=0;
    foreach ($rows as $row){
        $c=(float)$row['c']; $r=(float)$row['r']; $k=(int)$row['k']; $v=(int)$row['v'];
        if ($pc!==null){
            $dc=$c-$pc; $dr=$r-$pr; $dk=$k-$pk; $dv=$v-$pv;
            /* сброс суток / первое появление метрики -> отбрасываем всплеск */
            if($dc<0||$dc>2)$dc=0; if($dr<0||$dr>3)$dr=0; if($dk<0||$dk>2000)$dk=0; if($dv<0||$dv>2000)$dv=0;
            $labels[]=$row['hm']; $cost[]=round($dc,5); $rev[]=round($dr,5); $clk[]=$dk; $vis[]=$dv;
        }
        $pc=$c;$pr=$r;$pk=$k;$pv=$v;
    }
    $n=count($labels);
    $sumK=array_sum($clk); $sumV=array_sum($vis); $sumC=array_sum($cost); $sumR=array_sum($rev);
    return json_encode([
        'labels'=>$labels,'clicks'=>$clk,'visits'=>$vis,'cost'=>$cost,'revenue'=>$rev,
        'lastK'=>$n?end($clk):0,'lastC'=>$n?round(end($cost),4):0,'lastR'=>$n?round(end($rev),4):0,
        'sumK'=>$sumK,'sumV'=>$sumV,'sumC'=>round($sumC,2),'sumR'=>round($sumR,2),'hours'=>$hours,
    ], JSON_UNESCAPED_UNICODE);
}
function view_live(): void {
    $hours = (int)($_GET['hours'] ?? 3); if(!in_array($hours,[1,3,6],true)) $hours=3;
    echo '<h1>🔴 Live · нагрузка сети</h1>';
    echo '<div class="sub">Поминутно по всей сети (UTC). Клики / расход / доход за окно, общий таймлайн. Автообновление 20с.</div>';
    echo '<div class="srvbar">';
    foreach ([1,3,6] as $hh) echo '<button class="stab'.($hh===$hours?' on':'').'" onclick="location=\'?view=live&hours='.$hh.'\'">'.$hh.' час'.($hh==1?'':($hh<5?'а':'ов')).'</button>';
    echo '</div>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><div class="l">Кликов/мин</div><div class="v" id="kK">—</div></div>';
    echo '<div class="kpi"><div class="l">Расход/мин</div><div class="v" id="kC">—</div></div>';
    echo '<div class="kpi"><div class="l">Доход/мин</div><div class="v" id="kR">—</div></div>';
    echo '<div class="kpi"><div class="l">За окно</div><div class="v" id="kSum" style="font-size:15px">—</div></div>';
    echo '</div>';
    echo '<div class="card"><h2>🖱 Трафик · кликов в минуту</h2><div class="chartbox" style="height:170px"><canvas id="lTraf"></canvas></div></div>';
    echo '<div class="card"><h2>💸 Расход · $/мин</h2><div class="chartbox" style="height:170px"><canvas id="lCost"></canvas></div></div>';
    echo '<div class="card"><h2>💰 Доход · $/мин</h2><div class="chartbox" style="height:170px"><canvas id="lRev"></canvas></div></div>';
    echo '<script>'.
    'var HRS='.$hours.',ch={};'.
    'function grd(id,rgb){var c=document.getElementById(id).getContext("2d");var g=c.createLinearGradient(0,0,0,170);g.addColorStop(0,"rgba("+rgb+",.35)");g.addColorStop(1,"rgba("+rgb+",0)");return g;}'.
    'function mk(id,type,rgb,data,labels){var cfg={type:type,data:{labels:labels,datasets:[{data:data,'.
      'borderColor:"rgb("+rgb+")",backgroundColor:(type==="bar"?"rgba("+rgb+",.6)":grd(id,rgb)),fill:type!=="bar",'.
      'tension:.25,pointRadius:0,borderWidth:2,barPercentage:1,categoryPercentage:1}]},'.
      'options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false}},'.
      'scales:{x:{ticks:{color:"#64748b",maxTicksLimit:12,autoSkip:true},grid:{display:false}},y:{beginAtZero:true,ticks:{color:"#64748b"},grid:{color:"#e5e9f0"}}}}};'.
      'if(ch[id]){ch[id].data.labels=labels;ch[id].data.datasets[0].data=data;ch[id].update("none");}else ch[id]=new Chart(document.getElementById(id),cfg);}'.
    'function load(){fetch("?view=live&format=json&hours="+HRS).then(r=>r.json()).then(function(d){'.
      'document.getElementById("kK").textContent=d.lastK+" кл";'.
      'document.getElementById("kC").textContent="$"+Number(d.lastC).toFixed(4);'.
      'document.getElementById("kR").textContent="$"+Number(d.lastR).toFixed(4);'.
      'document.getElementById("kSum").textContent=d.sumK+" кл · "+d.sumV+" виз · $"+d.sumC+" / $"+d.sumR;'.
      'mk("lTraf","bar","14,165,233",d.clicks,d.labels);'.
      'mk("lCost","line","220,38,38",d.cost,d.labels);'.
      'mk("lRev","line","22,163,74",d.revenue,d.labels);'.
    '}).catch(function(){});}'.
    'load();setInterval(load,20000);'.
    '</script>';
}

/* ===================== 🎮 ПРОГРЕСС К 400 (геймификация) ===================== */
function view_progress(array $A, array $servers, string $today): void {
    $db = db();
    $GOAL_DOM = 400; $GOAL_MONEY = 60.0; $STRETCH = 70.0;
    $DEADLINE = '2026-09-30';

    /* живые домены = активны за последние 2 дня */
    $live = (int)(q($db,"SELECT COUNT(DISTINCT d.domain) n FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1 WHERE d.d>=UTC_DATE()-INTERVAL 1 DAY")[0]['n'] ?? 0);
    /* профит по дням (для темпа/проекции) */
    $pr = q($db,"SELECT d.d, ROUND(SUM(d.revenue)-SUM(d.cost),4) p FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1 WHERE d.d>=UTC_DATE()-INTERVAL 7 DAY GROUP BY d.d ORDER BY d.d");
    $byd=[]; foreach($pr as $r){ $byd[$r['d']]=(float)$r['p']; }
    $done=array_filter($byd, fn($v,$d)=>$d!==$today, ARRAY_FILTER_USE_BOTH);
    $avgDay = $done ? array_sum($done)/count($done) : 0.0;
    $todayP = $byd[$today] ?? 0.0;
    $frac = ((int)gmdate('H')*3600+(int)gmdate('i')*60)/86400;
    $eod = $frac>0.05 ? $todayP/$frac : $todayP;
    $curDaily = max($eod, $avgDay);
    $perDom = $live>0 ? $avgDay/$live : 0.0;
    $projAt400 = $perDom*400;

    /* появление доменов по дням */
    $fs = q($db,"SELECT fd, COUNT(*) c FROM (SELECT d.domain, MIN(d.d) fd FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1 GROUP BY d.domain) x GROUP BY fd ORDER BY fd");
    $addByDay=[]; foreach($fs as $r){ $addByDay[$r['fd']]=(int)$r['c']; }
    $addedToday = $addByDay[$today] ?? 0;

    $daysLeft = max(1, (int)((strtotime($DEADLINE)-strtotime($today))/86400));
    $pace = (int)ceil(($GOAL_DOM-$live)/$daysLeft);

    /* уровни */
    $levels=[[0,'Старт','🌱'],[25,'Разгон','⚙️'],[50,'Полста','🔥'],[100,'Сотня','💯'],[150,'Поток','🌊'],
             [200,'Экватор','⛰️'],[250,'Машина','🤖'],[300,'Сетка','🕸️'],[350,'Финишная прямая','🏁'],[400,'ЦЕЛЬ 400','👑']];
    $li=0; for($i=0;$i<count($levels);$i++){ if($live>=$levels[$i][0]) $li=$i; }
    $cur=$levels[$li]; $next=$levels[min($li+1,count($levels)-1)];
    $lvlFrom=$cur[0]; $lvlTo=$next[0]; $lvlPct = $lvlTo>$lvlFrom ? min(100,round(($live-$lvlFrom)/($lvlTo-$lvlFrom)*100)) : 100;
    $domPct = min(100, round($live/$GOAL_DOM*100));
    $moneyPct = min(100, round($curDaily/$GOAL_MONEY*100));

    echo '<h1>🎮 Прогресс к 400 доменам</h1>';
    echo '<div class="sub">Цель: 400 доменов × ~$0.15/день = $60/день к '.h($DEADLINE).'. Дней осталось: '.$daysLeft.'.</div>';

    /* уровень */
    echo '<div class="lvl"><div class="bdg">'.$cur[2].'</div><div class="info">';
    echo '<div class="lnum">Уровень '.($li+1).' из '.count($levels).'</div>';
    echo '<div class="lname">'.h($cur[1]).'</div>';
    echo '<div class="lsub">Живых доменов: <b>'.$live.'</b>'.($li+1<count($levels)?' · до «'.h($next[1]).'» ещё '.max(0,$lvlTo-$live).' ('.$next[2].')':' · максимум взят! 👑').'</div>';
    echo '<div class="bar" style="margin-top:8px"><i style="width:'.$lvlPct.'%"></i></div>';
    echo '</div></div>';

    /* две дорожки прогресса */
    echo '<div class="grid g2">';
    echo '<div class="card"><div class="barwrap"><div class="lab"><span>Домены</span><span><b>'.$live.'</b> / '.$GOAL_DOM.' · '.$domPct.'%</span></div><div class="bar"><i style="width:'.$domPct.'%"></i></div></div>';
    echo '<div class="note">Средний чек: <b>$'.number_format($perDom,3).'</b>/домен/день. При таком качестве 400 доменов = <b class="'.cls($projAt400).'">$'.number_format($projAt400,2).'</b>/день.</div></div>';
    echo '<div class="card"><div class="barwrap"><div class="lab"><span>Профит в день</span><span><b>$'.number_format($curDaily,2).'</b> / $'.(int)$GOAL_MONEY.' · '.$moneyPct.'%</span></div><div class="bar gold"><i style="width:'.$moneyPct.'%"></i></div></div>';
    echo '<div class="note">Сегодня набежало $'.number_format($todayP,2).', прогноз EOD $'.number_format($eod,2).'. Средний завершённый день $'.number_format($avgDay,2).'.</div></div>';
    echo '</div>';

    /* дневной квест */
    $qDone = min($addedToday,$pace); $qPct = $pace>0?min(100,round($addedToday/$pace*100)):100;
    echo '<div class="quest"><h2>🎯 Квест дня</h2>';
    echo '<div class="note" style="margin-top:0">Чтобы взять 400 к '.h($DEADLINE).', темп — <b>'.$pace.' новых домена(ов) в день</b>. Сегодня заведено: <b>'.$addedToday.'</b> из '.$pace.'.</div>';
    echo '<div class="bar '.($addedToday>=$pace?'':'gold').'" style="margin-top:8px"><i style="width:'.$qPct.'%"></i></div>';
    /* мини-бары появления доменов за 14 дней */
    $daysAxis=[]; for($i=13;$i>=0;$i--) $daysAxis[]=date('Y-m-d',strtotime($today.' -'.$i.' day'));
    $maxAdd=1; foreach($daysAxis as $d) $maxAdd=max($maxAdd,$addByDay[$d]??0);
    echo '<div class="mbars">';
    foreach($daysAxis as $d){ $c=$addByDay[$d]??0; $hh=max(3,round($c/$maxAdd*46)); echo '<div class="mb'.($d===$today?' today':'').'" style="height:'.$hh.'px" title="'.h($d).': +'.$c.'"><span>'.substr($d,8,2).'</span></div>'; }
    echo '</div><div class="note" style="margin-top:22px">Новых доменов по дням (14 дней). Оранжевый — сегодня.</div></div>';

    /* достижения */
    $ach=[
      ['💯','100 доменов', $live>=100, $live.'/100'],
      ['🕸️','250 доменов', $live>=250, $live.'/250'],
      ['👑','400 доменов', $live>=400, $live.'/400'],
      ['💵','$10/день', $curDaily>=10, '$'.number_format($curDaily,0).'/10'],
      ['💰','$30/день', $curDaily>=30, '$'.number_format($curDaily,0).'/30'],
      ['🏆','$60/день', $curDaily>=60, '$'.number_format($curDaily,0).'/60'],
      ['🌐','10 серверов в бою', count(array_filter($servers,fn($s)=>(int)$s['is_enabled']===1))>=10, ''],
      ['⚖️','Сервер на 40', (function()use($A){$m=0;foreach($A['srv'] as $a){$m=max($m,count($a['doms']??[]));}return $m;})()>=40, ''],
    ];
    echo '<div class="card"><h2>🏅 Достижения</h2><div class="ach">';
    foreach($ach as [$ic,$n,$on,$s]){ echo '<div class="a'.($on?' on':'').'"><div class="ic">'.$ic.'</div><div class="n">'.h($n).'</div>'.($s?'<div class="s">'.h($s).'</div>':'<div class="s">'.($on?'✓ взято':'заперто').'</div>').'</div>'; }
    echo '</div></div>';

    /* отбраковка */
    $cull = q($db,"SELECT d.domain, ROUND(SUM(CASE WHEN d.d>=UTC_DATE()-INTERVAL 3 DAY THEN d.revenue-d.cost END),4) net FROM daily d JOIN servers s ON s.id=d.server_id AND s.is_enabled=1 GROUP BY d.domain HAVING MIN(d.d)<=UTC_DATE()-INTERVAL 5 DAY AND net<=0 ORDER BY net ASC LIMIT 12");
    echo '<div class="card"><h2>🗑️ На отбраковку <small>· вызрели (5+ дней), но в нуле/минусе</small></h2>';
    if(!$cull){ echo '<div class="note">Балласта нет — все вызревшие домены в плюсе 👍</div>'; }
    else { echo '<table><tr><th>Домен</th><th>Профит 3д</th></tr>';
      foreach($cull as $c){ echo '<tr><td>🔴 '.h($c['domain']).'</td><td class="neg">'.money((float)$c['net']).'</td></tr>'; }
      echo '</table><div class="note">Кандидаты на снос/пере-шаблон — освободят сервера и бюджет под свежие.</div>'; }
    echo '</div>';
}

/* ===================== ГРАФИК · живой мультиграфик по минутам ===================== */
/* JSON-данные внутридневного ряда по охвату (сеть / сервер / домен) для AJAX */
function chart_json_data(): string {
    $db = db();
    $srv = (string)($_GET['srv'] ?? '');
    $dom = (string)($_GET['dom'] ?? '');
    $where = "ts >= UTC_DATE()";
    $params = []; $types = '';
    if ($srv !== '') {
        $r = q($db, "SELECT id FROM servers WHERE code=? LIMIT 1", [$srv]);
        $where .= $r ? " AND server_id=".(int)$r[0]['id'] : " AND 1=0";
    }
    if ($dom !== '') { $where .= " AND domain=?"; $params[]=$dom; $types.='s'; }
    $sql = "SELECT DATE_FORMAT(ts,'%H:%i') hm, ROUND(SUM(revenue),4) r, ROUND(SUM(cost),4) c
            FROM intraday_snap WHERE $where GROUP BY ts ORDER BY ts";
    $rows = $params ? q($db,$sql,$params,$types) : q($db,$sql);
    $labels=[]; $rev=[]; $cost=[];
    foreach ($rows as $row){ $labels[]=$row['hm']; $rev[]=(float)$row['r']; $cost[]=(float)$row['c']; }
    $frac = ((int)gmdate('H')*3600+(int)gmdate('i')*60)/86400;
    $lastR = $rev ? (float)end($rev) : 0.0; $lastC = $cost ? (float)end($cost) : 0.0;
    $eodR = $frac>0.05 ? $lastR/$frac : $lastR; $eodC = $frac>0.05 ? $lastC/$frac : $lastC;
    return json_encode([
        'labels'=>$labels,'revenue'=>$rev,'cost'=>$cost,
        'nowR'=>round($lastR,4),'nowC'=>round($lastC,4),
        'eodR'=>round($eodR,4),'eodC'=>round($eodC,4),
        'frac'=>round($frac,3),
    ], JSON_UNESCAPED_UNICODE);
}

function view_chart(array $C, array $A, array $servers, int $days, string $today): void {
    $srvSel=(string)($_GET['srv']??''); $domSel=(string)($_GET['dom']??'');
    echo '<h1>График · по минутам</h1>';
    echo '<div class="sub">Живой мультиграфик за сегодня (UTC). Кликни сервер, затем домен — график перестраивается без перезагрузки. Автообновление 20с.</div>';

    /* плитки серверов (AJAX-табы) */
    echo '<div class="srvbar">';
    echo '<button class="stab'.($srvSel===''?' on':'').'" data-srv="">🌐 Вся сеть</button>';
    foreach ($servers as $s){
        if ((int)$s['is_enabled']!==1) continue;
        $a=$A['srv'][(int)$s['id']]??['rev'=>0,'cost'=>0]; $p=(float)$a['rev']-(float)$a['cost'];
        echo '<button class="stab'.($srvSel===$s['code']?' on':'').'" data-srv="'.h($s['code']).'">'.h($s['ip']).' <span class="'.cls($p).'">'.money($p).'</span></button>';
    }
    echo '</div>';

    /* чипы доменов (скрыты, показываются под выбранный сервер) */
    echo '<div class="dombar" id="dombar"><button class="dtab on" data-dom="" data-srv="">Все домены</button>';
    foreach ($A['dom'] as $dm=>$v){
        echo '<button class="dtab" data-dom="'.h($dm).'" data-srv="'.h($v['server']).'" hidden>'.h($dm).'</button>';
    }
    echo '</div>';

    /* живые KPI охвата */
    echo '<div class="kpis">';
    echo '<div class="kpi"><div class="l">Доход сейчас</div><div class="v" id="kR">—</div></div>';
    echo '<div class="kpi"><div class="l">Расход сейчас</div><div class="v" id="kC">—</div></div>';
    echo '<div class="kpi"><div class="l">Профит сейчас</div><div class="v" id="kP">—</div></div>';
    echo '<div class="kpi"><div class="l">Прогноз EOD, профит</div><div class="v" id="kE">—</div></div>';
    echo '</div>';

    echo '<div class="card"><h2 id="chTitle">Доход и расход · по минутам <small>· пунктир — прогноз на конец дня</small></h2>';
    echo '<div class="chartbox" style="height:340px"><canvas id="cLive"></canvas></div>';
    echo '<div class="note" id="chEmpty" hidden>Кривая копится по минутам с момента запуска снимков. Если пусто — загляни через пару минут.</div></div>';

    /* карта code->ip для заголовка */
    $ipmap=[]; foreach ($servers as $s){ $ipmap[$s['code']]=$s['ip']; }
    echo '<script>'.
    'var SRV='.json_encode($srvSel).',DOM='.json_encode($domSel).',IP='.json_encode($ipmap).';'.
    'var chart=null;'.
    'function grad(rgb){var c=document.getElementById("cLive").getContext("2d");var g=c.createLinearGradient(0,0,0,340);g.addColorStop(0,"rgba("+rgb+",.30)");g.addColorStop(1,"rgba("+rgb+",0)");return g;}'.
    'function fmt(v){return "$"+Number(v).toFixed(4);}'.
    'function setK(id,v,col){var e=document.getElementById(id);e.textContent=fmt(v);e.className="v "+(col?(v>=0?"pos":"neg"):"");}'.
    'function draw(d){'.
      'document.getElementById("chEmpty").hidden=d.labels.length>0;'.
      'setK("kR",d.nowR,false);setK("kC",d.nowC,false);setK("kP",d.nowR-d.nowC,true);setK("kE",d.eodR-d.eodC,true);'.
      'var n=d.labels.length;'.
      'var lastMin=0;if(n>0){var pp=d.labels[n-1].split(":");lastMin=(+pp[0])*60+(+pp[1]);}'.
      'function hhmm(fm){if(fm>=1440)return "24:00";var h=Math.floor(fm/60),m=fm%60;return ("0"+h).slice(-2)+":"+("0"+m).slice(-2);}'.
      'var fut=[],start=Math.ceil((lastMin+1)/5)*5;for(var fm=start;fm<1440;fm+=5){fut.push(fm);}fut.push(1440);'.
      'var labels=d.labels.concat(fut.map(hhmm));'.
      'var rev=d.revenue.concat(fut.map(function(){return null;})),cost=d.cost.concat(fut.map(function(){return null;}));'.
      'var pr=new Array(n).fill(null),pc=new Array(n).fill(null);'.
      'if(n>0){pr[n-1]=d.revenue[n-1];pc[n-1]=d.cost[n-1];}'.
      'fut.forEach(function(fm){var ft=fm/1440;pr.push(+(d.eodR*ft).toFixed(4));pc.push(+(d.eodC*ft).toFixed(4));});'.
      'var ds=[{label:"доход",data:rev,borderColor:"#16a34a",backgroundColor:grad("22,163,74"),fill:true,tension:.25,pointRadius:0,borderWidth:3,spanGaps:false},'.
      '{label:"расход",data:cost,borderColor:"#dc2626",backgroundColor:grad("220,38,38"),fill:true,tension:.25,pointRadius:0,borderWidth:2,spanGaps:false},'.
      '{label:"прогноз дохода",data:pr,borderColor:"#16a34a",borderDash:[6,4],fill:false,pointRadius:0,borderWidth:2,spanGaps:true},'.
      '{label:"прогноз расхода",data:pc,borderColor:"#dc2626",borderDash:[6,4],fill:false,pointRadius:0,borderWidth:2,spanGaps:true}];'.
      'if(chart){chart.data.labels=labels;chart.data.datasets=ds;chart.update("none");}'.
      'else{chart=new Chart(document.getElementById("cLive"),{type:"line",data:{labels:labels,datasets:ds},'.
      'options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{labels:{color:"#64748b",boxWidth:12}}},'.
      'scales:{x:{ticks:{color:"#64748b",maxTicksLimit:14,autoSkip:true},grid:{display:false}},y:{beginAtZero:true,ticks:{color:"#64748b"},grid:{color:"#e5e9f0"}}}}});}'.
    '}'.
    'function title(){var t="Доход и расход · по минутам";if(SRV)t+=" · "+(IP[SRV]||SRV);if(DOM)t+=" · "+DOM;'.
      'document.getElementById("chTitle").innerHTML=t+" <small>· пунктир — прогноз на конец дня</small>";}'.
    'function load(){fetch("?view=chart&format=json&srv="+encodeURIComponent(SRV)+"&dom="+encodeURIComponent(DOM)).then(r=>r.json()).then(draw).catch(function(){});}'.
    'function syncDoms(){document.querySelectorAll(".dtab").forEach(function(d){'.
      'if(d.dataset.dom===""){d.hidden=false;d.classList.toggle("on",DOM==="");}'.
      'else{d.hidden=!(SRV&&d.dataset.srv===SRV);d.classList.toggle("on",d.dataset.dom===DOM);}});}'.
    'function urlsync(){history.replaceState({},"","?view=chart&srv="+encodeURIComponent(SRV)+"&dom="+encodeURIComponent(DOM));}'.
    'document.querySelectorAll(".stab").forEach(function(b){b.onclick=function(){SRV=b.dataset.srv;DOM="";'.
      'document.querySelectorAll(".stab").forEach(function(x){x.classList.toggle("on",x===b);});'.
      'syncDoms();urlsync();title();load();};});'.
    'document.querySelectorAll(".dtab").forEach(function(b){b.onclick=function(){DOM=b.dataset.dom;'.
      'document.querySelectorAll(".dtab").forEach(function(x){x.classList.toggle("on",x===b);});'.
      'urlsync();title();load();};});'.
    'syncDoms();title();load();setInterval(load,20000);'.
    '</script>';
}

/* ===================== СТЕНА ГРАФИКОВ · все серверы ===================== */
function view_wall(array $A, array $servers, string $today): void {
    echo '<h1>📊 Стена графиков · все серверы</h1>';
    echo '<div class="sub">Живой доход/расход по минутам за сегодня (UTC) по каждому серверу + домены за период. Пролистай — всё сразу. Автообновление 30с.</div>';
    $cards=[];
    foreach ($servers as $s){
        if ((int)$s['is_enabled']!==1) continue;
        $sid=(int)$s['id']; $code=(string)$s['code'];
        $ag=$A['srv'][$sid]??['rev'=>0,'cost'=>0,'visits'=>0];
        $p=(float)($ag['rev']??0)-(float)($ag['cost']??0);
        $doms=[]; foreach ($A['dom'] as $dm=>$v){ if((string)($v['server']??'')===$code) $doms[$dm]=$v; }
        uasort($doms, function($a,$b){ return (((float)$b['rev']-(float)$b['cost'])<=>((float)$a['rev']-(float)$a['cost'])); });
        $cid='wc_'.$sid;
        echo '<div class="card">';
        echo '<h2>'.h($s['ip']).' <small>· '.h($code).'</small><span class="'.cls($p).'" style="float:right">'.money($p).' <span class="dim" style="font-weight:400">/ период</span></span></h2>';
        echo '<div class="chartbox" style="height:180px"><canvas id="'.$cid.'"></canvas></div>';
        echo '<div class="kpis" style="margin:8px 0">'.
             '<div class="kpi"><div class="l">Доход сегодня</div><div class="v" id="'.$cid.'_r">—</div></div>'.
             '<div class="kpi"><div class="l">Расход сегодня</div><div class="v" id="'.$cid.'_c">—</div></div>'.
             '<div class="kpi"><div class="l">Профит сейчас</div><div class="v" id="'.$cid.'_p">—</div></div>'.
             '<div class="kpi"><div class="l">Прогноз EOD</div><div class="v" id="'.$cid.'_e">—</div></div></div>';
        if ($doms){
            echo '<table><tr><th>Домен</th><th>Доход</th><th>Расход</th><th>Профит</th><th>Виз.</th></tr>';
            foreach ($doms as $dm=>$v){ $dp=(float)$v['rev']-(float)$v['cost'];
                echo '<tr><td>'.h($dm).'</td><td>'.money((float)$v['rev']).'</td><td>'.money((float)$v['cost']).'</td><td class="'.cls($dp).'">'.money($dp).'</td><td>'.(int)($v['visits']??0).'</td></tr>'; }
            echo '</table>';
        } else { echo '<div class="note">Нет доменов с данными за период.</div>'; }
        echo '</div>';
        $cards[]=['code'=>$code,'cid'=>$cid];
    }
    echo '<script>'.
    'var WCARDS='.json_encode($cards).',WCH={};'.
    'function wfmt(v){return "$"+Number(v).toFixed(3);}'.
    'function wsetk(id,v,col){var e=document.getElementById(id);if(!e)return;e.textContent=wfmt(v);e.className="v "+(col?(v>=0?"pos":"neg"):"");}'.
    'function wload(){WCARDS.forEach(function(o){'.
      'fetch("?view=chart&format=json&srv="+encodeURIComponent(o.code)).then(function(r){return r.json();}).then(function(d){'.
        'var el=document.getElementById(o.cid);if(!el)return;'.
        'wsetk(o.cid+"_r",d.nowR,false);wsetk(o.cid+"_c",d.nowC,false);wsetk(o.cid+"_p",d.nowR-d.nowC,true);wsetk(o.cid+"_e",d.eodR-d.eodC,true);'.
        'var ds=[{label:"доход",data:d.revenue,borderColor:"#16a34a",backgroundColor:"rgba(22,163,74,.15)",fill:true,tension:.25,pointRadius:0,borderWidth:2},'.
        '{label:"расход",data:d.cost,borderColor:"#dc2626",backgroundColor:"rgba(220,38,38,.10)",fill:true,tension:.25,pointRadius:0,borderWidth:2}];'.
        'if(WCH[o.cid]){WCH[o.cid].data.labels=d.labels;WCH[o.cid].data.datasets=ds;WCH[o.cid].update("none");}'.
        'else{WCH[o.cid]=new Chart(el,{type:"line",data:{labels:d.labels,datasets:ds},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:"#64748b",maxTicksLimit:8,autoSkip:true},grid:{display:false}},y:{beginAtZero:true,ticks:{color:"#64748b",maxTicksLimit:4},grid:{color:"#eef2f7"}}}}});}'.
      '}).catch(function(){});'.
    '});}'.
    'wload();setInterval(wload,30000);'.
    '</script>';
}

/* ===================== ТРЕКЕРЫ / RTB (изолированный тип сервера) ===================== */
function view_trackers(int $days): void {
    $db=db();
    $from=(new DateTime("-".($days-1)." days"))->format('Y-m-d');
    $rows=q($db,"SELECT dd.d, dd.domain, s.code, s.ip, dd.cost, dd.revenue, dd.clicks, dd.visits
                   FROM daily dd JOIN servers s ON s.id=dd.server_id
                  WHERE s.kind='tracker' AND dd.d >= ? ORDER BY dd.d DESC, dd.revenue DESC",[$from]);
    echo '<h1>\U0001F3AF Трекеры / RTB <small>· '.(int)$days.'д</small></h1>';
    echo '<div class="sub">Изолированный тип сервера (kind=tracker). Клик-трекеры/ротаторы офферов — не смешиваются с тубами. cost=расход трафика, доход=выплата, визиты=лиды.</div>';
    if(!$rows){ echo '<div class="card"><div class="note">Пока нет данных от трекеров. Фид шлёт раз в 10 мин (money-push-rtb.php на 169).</div></div>'; return; }
    $tc=$tr=0;$tk=$tv=0; $byday=[]; $bycamp=[];
    foreach($rows as $r){ $c=(float)$r['cost'];$rv=(float)$r['revenue'];$k=(int)$r['clicks'];$v=(int)$r['visits'];
        $tc+=$c;$tr+=$rv;$tk+=$k;$tv+=$v;
        $byday[$r['d']]['r']=($byday[$r['d']]['r']??0)+$rv; $byday[$r['d']]['c']=($byday[$r['d']]['c']??0)+$c;
        $cm=$r['domain']; if(!isset($bycamp[$cm]))$bycamp[$cm]=['c'=>0,'r'=>0,'k'=>0,'v'=>0]; $bycamp[$cm]['c']+=$c;$bycamp[$cm]['r']+=$rv;$bycamp[$cm]['k']+=$k;$bycamp[$cm]['v']+=$v;
    }
    $tp=$tr-$tc; $epm=$tk>0?$tr/$tk*1000:0;
    echo '<div class="kpis">';
    echo '<div class="kpi"><div class="l">Доход</div><div class="v pos">'.money($tr).'</div></div>';
    echo '<div class="kpi"><div class="l">Расход</div><div class="v">'.money($tc).'</div></div>';
    echo '<div class="kpi"><div class="l">Профит</div><div class="v '.cls($tp).'">'.money($tp).'</div></div>';
    echo '<div class="kpi"><div class="l">ROI</div><div class="v">'.roi_s($tc,$tr).'</div></div>';
    echo '<div class="kpi"><div class="l">Клики</div><div class="v">'.num($tk).'</div></div>';
    echo '<div class="kpi"><div class="l">Лиды</div><div class="v">'.num($tv).'</div></div>';
    echo '<div class="kpi"><div class="l">EPM /1k</div><div class="v">'.number_format($epm,3).'</div></div>';
    echo '</div>';
    ksort($byday); $labels=array_keys($byday); $prof=[]; $rev=[]; $cost=[];
    foreach($byday as $d=>$v){ $prof[]=round($v['r']-$v['c'],4); $rev[]=round($v['r'],4); $cost[]=round($v['c'],4); }
    echo '<div class="card"><h2>Доход / расход / профит по дням</h2><div class="chartbox"><canvas id="tD"></canvas></div></div>';
    chart_js('tD', "{type:'bar',data:{labels:".json_encode($labels).",datasets:[".
      "{label:'доход',data:".json_encode($rev).",backgroundColor:'#16a34a'},".
      "{label:'расход',data:".json_encode($cost).",backgroundColor:'#dc2626'},".
      "{label:'профит',type:'line',data:".json_encode($prof).",borderColor:'#2563eb',borderWidth:2,pointRadius:0}]},".
      "options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#64748b'}}},scales:{x:{ticks:{color:'#64748b'},grid:{display:false}},y:{ticks:{color:'#64748b'},grid:{color:'#e5e9f0'}}}}}");
    echo '<div class="card"><h2>Кампании / офферы</h2><table><tr><th>Кампания</th><th>Доход</th><th>Расход</th><th>Профит</th><th>ROI</th><th>Клики</th><th>Лиды</th><th>EPM/1k</th></tr>';
    uasort($bycamp, fn($a,$b)=>($b['r']-$b['c'])<=>($a['r']-$a['c']));
    foreach($bycamp as $cm=>$v){ $p=$v['r']-$v['c']; $e=$v['k']>0?$v['r']/$v['k']*1000:0;
        echo '<tr><td>'.h($cm).'</td><td>'.money($v['r']).'</td><td>'.money($v['c']).'</td><td class="'.cls($p).'">'.money($p).'</td><td>'.roi_s($v['c'],$v['r']).'</td><td>'.num((int)$v['k']).'</td><td>'.num((int)$v['v']).'</td><td>'.number_format($e,3).'</td></tr>'; }
    echo '</table></div>';
    echo '<div class="note">Тип сервера «tracker» изолирован от туб: в Обзоре/Доменах/Серверах он не учитывается. Добавляй новые трекеры тем же kind — будут падать сюда.</div>';
}

/* ===================== ЗАДАЧИ / ПРОБЛЕМЫ ===================== */
function view_issues(array $A, array $servers): void {
    $issues = detect_issues($A, $servers);
    $bad = count(array_filter($issues, fn($i)=>$i[0]===0));
    $warn = count(array_filter($issues, fn($i)=>$i[0]===1));
    echo '<h1>Задачи · проблемы сети</h1>';
    echo '<div class="sub">Автодетект по данным центра, обновляется при каждом заходе · '.$bad.' срочных, '.$warn.' на контроль</div>';
    if (!$issues) { echo '<div class="flag ok"><div class="t">Проблем не найдено 🎉</div><div class="d">Все серверы на связи, доход идёт, пожирателей бюджета нет.</div></div>'; return; }
    foreach ($issues as [$sev,$ico,$t,$d,$act]) {
        $cls = $sev===0 ? 'bad' : ($sev===1 ? '' : 'ok');
        echo '<div class="flag '.$cls.'"><div class="t">'.$ico.' '.h($t).'</div>';
        if ($d!=='') echo '<div class="d">'.h($d).'</div>';
        echo '<div class="d">→ <b>'.h($act).'</b></div></div>';
    }
    echo '<div class="note">Правила детекта: не в учёте · молчит &gt;1ч · сервер в глубоком минусе (недобор дохода) · домены жгут бюджет. Пороги и новые правила добавляем по ходу.</div>';
}

/* ===================== СЕРВЕРЫ ===================== */
function view_servers(array $A, array $servers, int $days=7): void {
    $today = gmdate('Y-m-d');
    /* трекеры (kind=tracker) в этой вьюхе учитываем в динамике и в тотале (в остальных вьюхах они изолированы) */
    $tfrom=(new DateTime("-".($days-1)." days"))->format('Y-m-d');
    foreach (q(db(),"SELECT dd.d,dd.domain,dd.server_id,dd.cost,dd.revenue,dd.clicks,dd.visits FROM daily dd JOIN servers s ON s.id=dd.server_id WHERE s.kind IN ('tracker','game') AND dd.d>=?",[$tfrom]) as $r){
        $sid=(int)$r['server_id']; $c=(float)$r['cost']; $rv=(float)$r['revenue']; $k=(int)$r['clicks']; $v=(int)$r['visits']; $d=$r['d'];
        $A['day'][$d]['cost']=($A['day'][$d]['cost']??0)+$c; $A['day'][$d]['rev']=($A['day'][$d]['rev']??0)+$rv;
        if(!isset($A['srv'][$sid])) $A['srv'][$sid]=['cost'=>0,'rev'=>0,'clicks'=>0,'visits'=>0,'doms'=>[]];
        $A['srv'][$sid]['cost']+=$c; $A['srv'][$sid]['rev']+=$rv; $A['srv'][$sid]['clicks']+=$k; $A['srv'][$sid]['visits']+=$v; $A['srv'][$sid]['doms'][$r['domain']]=1;
        $A['srvDay'][$sid][$d]=($A['srvDay'][$sid][$d]??0)+($rv-$c);
        if(!isset($A['dom'][$r['domain']])) $A['dom'][$r['domain']]=['cost'=>0,'rev'=>0,'clicks'=>0,'visits'=>0,'server'=>(string)$r['server_id']];
        $A['dom'][$r['domain']]['cost']+=$c; $A['dom'][$r['domain']]['rev']+=$rv; $A['dom'][$r['domain']]['clicks']+=$k; $A['dom'][$r['domain']]['visits']+=$v;
    }
    /* ---- метрики сети (сверху) ---- */
    $tc=array_sum(array_column($A['day'],'cost')); $tr=array_sum(array_column($A['day'],'rev')); $tp=$tr-$tc;
    $roi=$tc>0?$tr/$tc:null;
    $vis=0; $clk=0; foreach ($A['dom'] as $v){ $vis+=(int)$v['visits']; $clk+=(int)$v['clicks']; }
    $doneDays=array_filter($A['day'],fn($v,$d)=>$d!==$today,ARRAY_FILTER_USE_BOTH);
    $avg=$doneDays?array_sum(array_map(fn($v)=>$v['rev']-$v['cost'],$doneDays))/count($doneDays):0.0;
    $proj30=$avg*30;
    $rpv=$vis>0?$tr/$vis*1000:0;
    $cpv=$vis>0?$tc/$vis*1000:0;
    $todayP = isset($A['day'][$today]) ? $A['day'][$today]['rev']-$A['day'][$today]['cost'] : 0.0;
    $frac = ((int)gmdate('H')*3600+(int)gmdate('i')*60)/86400;
    $fcEOD = $frac>0.05 ? $todayP/$frac : $todayP;

    /* прогноз с разгоном: темп профита по последним часам из intraday + ускорение */
    $ip3 = q(db(), "SELECT
      (SELECT ROUND(revenue-cost,4) FROM intraday ORDER BY ts DESC LIMIT 1) p0,
      (SELECT ROUND(revenue-cost,4) FROM intraday WHERE ts<=UTC_TIMESTAMP()-INTERVAL 60 MINUTE ORDER BY ts DESC LIMIT 1) p1,
      (SELECT ROUND(revenue-cost,4) FROM intraday WHERE ts<=UTC_TIMESTAMP()-INTERVAL 120 MINUTE ORDER BY ts DESC LIMIT 1) p2");
    $p0=(float)($ip3[0]['p0']??0); $p1=(float)($ip3[0]['p1']??0); $p2=(float)($ip3[0]['p2']??0);
    $rate1=$p0-$p1; $rate2=$p1-$p2; $accel=$rate1-$rate2;
    $hoursLeft = max(0, 24 - ((int)gmdate('H') + (int)gmdate('i')/60));
    if ($p1>0 && $rate1>0) {
        $eodAccel=$p0; $whole=(int)floor($hoursLeft);
        for ($i=0;$i<$whole;$i++) $eodAccel += max(0, $rate1+$accel*$i);
        $eodAccel += max(0, $rate1+$accel*$whole)*($hoursLeft-$whole);
        $eodAccel = min($eodAccel, max($fcEOD,$p0)*3); /* защита от разгона в бесконечность */
    } else { $eodAccel=$fcEOD; }
    $accelPct = $rate2>0.0001 ? ($rate1/$rate2-1)*100 : 0.0;

    echo '<h1>Серверы · метрики сети</h1>';
    echo '<div class="kpis">';
    kpi('Прогноз EOD ⚡ разгон', dot($eodAccel).' '.money($eodAccel), cls($eodAccel), 'темп '.money($rate1).'/ч · ускорение '.($accel>=0?'▲':'▼').number_format(abs($accelPct),0).'%');
    kpi('Прогноз EOD (линейный)', dot($fcEOD).' '.money($fcEOD), cls($fcEOD), 'сейчас '.money($todayP).' ('.round($frac*100).'% суток)');
    kpi('Профит период', money($tp), cls($tp));
    kpi('Доход', money($tr));
    kpi('Расход', money($tc));
    kpi('ROI', $roi===null?'—':number_format($roi,2).'x');
    kpi('Проекция 30д', money($proj30), cls($proj30));
    kpi('Доменов', (string)count($A['dom']));
    kpi('Трафик, визиты', num($vis), '', num($clk).' кликов');
    kpi('Доход / 1k виз.', number_format($rpv,4));
    kpi('Расход / 1k виз.', number_format($cpv,4));
    echo '</div>';

    /* ---- сервера, сортировка по доходу (включённые сверху, парковка вниз) ---- */
    usort($servers, function($a,$b) use ($A){
        $ea=(int)$a['is_enabled']; $eb=(int)$b['is_enabled']; if($ea!==$eb) return $eb<=>$ea;
        $ra=(float)($A['srv'][(int)$a['id']]['rev']??0); $rb=(float)($A['srv'][(int)$b['id']]['rev']??0);
        return $rb<=>$ra;
    });
    echo '<h2 style="margin:8px 0 12px">Серверы · '.count($servers).' <small>· по доходу</small></h2>';
    echo '<div class="tiles">';
    $sparks=[];
    foreach ($servers as $s) {
        $sid=(int)$s['id']; $a=$A['srv'][$sid]??['cost'=>0,'rev'=>0,'clicks'=>0,'visits'=>0,'doms'=>[]];
        $cost=(float)$a['cost']; $rev=(float)$a['rev']; $p=$rev-$cost;
        $series=[]; foreach (($A['srvDay'][$sid]??[]) as $d=>$pp){ $series[$d]=round($pp,4); } ksort($series);
        $sid_dom='sp'.$sid; $sparks[$sid_dom]=array_values($series);
        $stale=is_stale($s['last_seen_at']); $parked=(int)$s['is_enabled']===0;
        $kind=(string)($s['kind']??'tube'); $isTrk=($kind==='tracker'); $isGame=($kind==='game');
        $href = $isGame ? '?view=games&days=1' : ($isTrk ? '?view=trackers&days=1' : '?view=domains&srv='.h(rawurlencode($s['code'])).'&days=1');
        $ttl  = $isGame ? 'Яндекс.Игры — доход в рублях, приведён к доллару' : ($isTrk ? 'трекер — RTB' : 'домены '.h($s['ip']).' за сегодня');
        echo '<a class="tilewrap" href="'.$href.'" title="'.$ttl.'">';
        echo '<div class="tile"'.($parked?' style="opacity:.6"':'').'>';
        echo '<div class="hd"><span class="code">'.($parked?'⏸':dot($p)).' '.h($s['ip']).
             (((int)$s['is_aggregate'])?'<span class="agg">агрег.</span>':'').
             ((($s['kind']??'')==='tracker')?'<span class="agg">трекер</span>':((($s['kind']??'')==='game')?'<span class="agg">игры</span>':'')).
             ($parked?'<span class="agg">не в учёте</span>':'').'</span><span class="net">'.money($rev).'</span></div>';
        echo '<div class="big '.($parked?'zero':cls($p)).'">'.($parked?'—':money($p)).'</div>';
        if(!$parked) echo '<div class="chartbox" style="height:36px"><canvas id="'.$sid_dom.'"></canvas></div>';
        else echo '<div class="row">'.h($s['note']).'</div>';
        echo '<div class="row"><span>ROI '.roi_s($cost,$rev).'</span><span>'.($isGame?'игр ':'доменов ').count($a['doms']??[]).'</span></div>';
        echo '<div class="row"><span>дох '.money($rev).'</span><span>рас '.money($cost).'</span></div>';
        echo '<div class="row"><span class="'.($stale?'stale':'').'">'.($s['last_seen_at']?'был '.substr((string)$s['last_seen_at'],11,5):'ни разу').'</span><span>'.num((int)$a['visits']).' виз.</span></div>';
        echo '</div></a>';
    }
    echo '</div>';
    echo '<div class="note">Плитки по доходу (справа сверху — доход сервера). 🟢/🔴 — знак профита. «был» жёлтым — молчит &gt;1ч. Спарклайн — профит по дням. Глубина (pageviews/визит) появится, когда добавим её в отправку данных — скажи, включу.</div>';

    /* инициализация спарклайнов */
    echo '<script>const SP='.json_encode($sparks).';for(const id in SP){const c=document.getElementById(id);if(!c)continue;'.
        'new Chart(c,{type:"line",data:{labels:SP[id].map((_,i)=>i),datasets:[{data:SP[id],borderColor:"#2563eb",borderWidth:1.5,'.
        'pointRadius:0,tension:.35,fill:false}]},options:{plugins:{legend:{display:false},tooltip:{enabled:false}},'.
        'scales:{x:{display:false},y:{display:false}},elements:{line:{}},maintainAspectRatio:false}});}</script>';

    $crm=['donor-146'=>'https://maximhot.ru/fapacrm/','srv-150'=>'https://hardpulse.ru/fapacrm/','srv-94'=>'https://vixn.online/fapacrm/','ds-test'=>'https://fapa.io/fapacrm/','analfluxtube'=>'https://analfluxtube.ru/fapacrm/','asianfuckhub'=>'https://asianfuckhub.ru/fapacrm/','asianstreamtube'=>'https://asianstreamtube.ru/fapacrm/','bigasstube'=>'https://bigasstube.ru/fapacrm/','wifepornhub'=>'https://wifepornhub.ru/fapacrm/','wildfucktube'=>'https://wildfucktube.ru/fapacrm/'];
    echo '<div class="card"><h2>fapacrm — админки серверов</h2><table><tr><th>Сервер</th><th>IP</th><th>CRM</th></tr>';
    foreach ($servers as $s){ $u=$crm[(string)$s['code']]??''; echo '<tr><td>'.h((string)$s['code']).'</td><td class="note">'.h((string)($s['ip']??'')).'</td><td>'.($u?'<a class="badge" href="'.h($u).'" target="_blank" rel="noopener">открыть fapacrm ↗</a>':'—').'</td></tr>'; }
    echo '</table></div>';
}

/* ===================== ДОМЕНЫ ===================== */
function view_domains(array $A, int $days=7): void {
    $dom=(string)($_GET['dom'] ?? ''); if($dom!==''){ view_domain_days($dom,$days); return; }
    $meta=[]; foreach (q(db(),"SELECT domain,html_donor,modernized FROM domain_meta") as $mm) $meta[$mm['domain']]=$mm;
    $reg=[]; foreach (q(db(),"SELECT domain,registrar FROM fapacrm.crm_domains") as $rr) $reg[$rr['domain']]=(string)$rr['registrar'];
    $donors=donor_catalog();
    $srvf = (string)($_GET['srv'] ?? '');
    $rows=[]; foreach ($A['dom'] as $dm=>$v){ if($srvf!==''&&$v['server']!==$srvf) continue; $v['domain']=$dm; $v['p']=(float)$v['rev']-(float)$v['cost']; $rows[]=$v; }
    usort($rows, fn($a,$b)=>$a['p']<=>$b['p']);
    echo '<h1>Домены · '.count($rows).($srvf!==''?' · '.h($srvf):'').'</h1>';
    $nmod=0; foreach($rows as $rr){ if((int)($meta[$rr['domain']]['modernized']??0)) $nmod++; }
    echo '<div class="sub">Модернизировано: <b>'.$nmod.'</b> / '.count($rows).' · <span style="background:#fdecec;padding:1px 7px;border-radius:3px">светло-красным</span> — ещё не уникализированы</div>';
    echo '<div class="sub">Фильтр по серверу: <a class="badge" href="?view=domains">все</a>';
    $codes=[]; foreach ($A['srv'] as $s){ $codes[$s['code']]=1; } ksort($codes);
    foreach (array_keys($codes) as $c) echo '<a class="badge" href="?view=domains&srv='.h($c).'">'.h($c).'</a>';
    echo '</div>';
    echo '<div class="card"><table><tr><th>Домен</th><th>Сервер</th><th>Регистратор</th><th>Расход</th><th>Доход</th><th>Профит</th><th>ROI</th><th>Rev/1k кл.</th><th>Rev/1k виз.</th><th>HTML донор</th><th>✓ модерн</th><th></th></tr>';
    foreach ($rows as $x){
        $cost=(float)$x['cost']; $rev=(float)$x['rev']; $p=$x['p'];
        $rpc=(int)$x['clicks']>0?$rev/(int)$x['clicks']*1000:null; $rpv=(int)$x['visits']>0?$rev/(int)$x['visits']*1000:null;
        $dmn=(string)$x['domain']; $dnr=(string)($meta[$dmn]['html_donor']??''); $mdz=(int)($meta[$dmn]['modernized']??0);
        echo '<tr'.($mdz?'':' class="notmod"').'><td>'.dot($p).' '.h($x['domain']).'</td><td class="note">'.h($x['server']).'</td><td class="note">'.((($reg[(string)$x['domain']]??'')!=='')?h($reg[(string)$x['domain']]):'—').'</td>';
        echo '<td>'.money($cost).'</td><td>'.money($rev).'</td><td class="'.cls($p).'">'.money($p).'</td><td>'.roi_s($cost,$rev).'</td>';
        echo '<td>'.($rpc===null?'—':number_format($rpc,4)).'</td><td>'.($rpv===null?'—':number_format($rpv,4)).'</td>';
        $dnrLbl = $dnr!=='' ? h($donors[$dnr]??$dnr) : '<span class="note">задать…</span>';
        echo '<td><a href="#" class="donlink" data-dom="'.h($dmn).'" data-donor="'.h($dnr).'" data-mod="'.$mdz.'">'.$dnrLbl.'</a></td>';
        echo '<td style="text-align:center"><input type="checkbox" class="modchk" data-dom="'.h($dmn).'" data-donor="'.h($dnr).'" '.($mdz?'checked':'').'></td>';
        echo '<td><a class="badge" href="?view=domains&dom='.rawurlencode($x['domain']).'">дни</a></td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="12" class="note">Нет данных.</td></tr>';
    echo '</table></div>';
    $opts=''; foreach($donors as $id=>$nm){ $opts.='<option value="'.h($id).'">'.h($nm).'</option>'; }
    echo '<style>.notmod{background:#fdecec}.notmod:hover{background:#fbe0e0}</style>';
    echo '<div id="donorModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200" onclick="if(event.target===this)this.style.display=&#39;none&#39;"><div style="background:#fff;max-width:440px;margin:12vh auto;padding:20px;border-radius:10px"><h3 style="margin:0 0 12px">HTML донор: <span id="dmDom"></span></h3><div>Донор (дизайн для уникализации):</div><select id="dmDonor" style="width:100%;padding:7px;margin-top:4px"><option value="">— не выбран —</option>'.$opts.'<option value="__custom">+ кастомный…</option></select><input id="dmCustom" placeholder="свой id донора" style="display:none;width:100%;margin-top:6px;padding:7px"><label style="display:block;margin-top:12px"><input type="checkbox" id="dmMod"> модернизирован</label><div style="margin-top:16px;text-align:right"><button onclick="document.getElementById(&#39;donorModal&#39;).style.display=&#39;none&#39;" style="padding:6px 12px">Отмена</button> <button onclick="saveDonor()" style="background:#2563eb;color:#fff;border:0;padding:7px 16px;border-radius:6px">Сохранить</button></div></div></div>';
    echo "<script>(function(){var m=document.getElementById('donorModal');document.querySelectorAll('a.donlink').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();openDonor(a.dataset.dom,a.dataset.donor,a.dataset.mod==='1');});});document.querySelectorAll('input.modchk').forEach(function(c){c.addEventListener('change',function(){quickMod(c,c.dataset.dom,c.dataset.donor,c.checked?1:0);});});document.getElementById('dmDonor').addEventListener('change',function(){document.getElementById('dmCustom').style.display=this.value==='__custom'?'block':'none';});window.openDonor=function(dom,donor,mod){document.getElementById('dmDom').textContent=dom;var s=document.getElementById('dmDonor');var known=false;for(var i=0;i<s.options.length;i++){if(s.options[i].value===donor){known=true;break;}}if(donor&&!known){s.value='__custom';document.getElementById('dmCustom').style.display='block';document.getElementById('dmCustom').value=donor;}else{s.value=donor||'';document.getElementById('dmCustom').style.display='none';}document.getElementById('dmMod').checked=!!mod;m.dataset.dom=dom;m.style.display='block';};window.saveDonor=function(){var dom=m.dataset.dom;var s=document.getElementById('dmDonor').value;var donor=s==='__custom'?document.getElementById('dmCustom').value.trim():s;var mod=document.getElementById('dmMod').checked?1:0;post(dom,donor,mod,function(){location.reload();});};window.quickMod=function(el,dom,donor,mod){post(dom,donor,mod,function(){var tr=el.closest('tr');if(tr){if(mod)tr.classList.remove('notmod');else tr.classList.add('notmod');}});};function post(dom,donor,mod,cb){var fd=new FormData();fd.append('action','savemeta');fd.append('domain',dom);fd.append('donor',donor);fd.append('modernized',mod);fetch(location.pathname,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(){cb&&cb();});}})();</script>";
    echo '<div class="note">Одинаковый Rev/1k визитов до 4-го знака у нескольких доменов сразу — признак деления дохода mybid_revenue_share (см. Аналитика → аномалии).</div>';
}

/* ===================== ТРЕНДЫ ===================== */
function view_trends(array $A): void {
    $labels=[]; foreach ($A['day'] as $d=>$v) $labels[]=substr($d,5);
    $palette=['#2563eb','#16a34a','#f0a500','#dc2626','#b388ff','#4dd0e1','#ffb74d','#a1887f','#90a4ae','#ce93d8'];
    $ds=[]; $i=0;
    foreach ($A['srvDay'] as $sid=>$series){
        $code=$A['srv'][$sid]['code'] ?? ('srv'.$sid);
        $data=[]; foreach ($A['day'] as $d=>$_){ $data[]=round($series[$d]??0,4); }
        $ds[]="{label:".json_encode($code).",data:".json_encode($data).",backgroundColor:'".$palette[$i%count($palette)]."'}"; $i++;
    }
    $cum=[]; $run=0; foreach ($A['day'] as $d=>$v){ $run+=($v['rev']-$v['cost']); $cum[]=round($run,4); }
    echo '<h1>Тренды</h1>';
    echo '<div class="card"><h2>Профит по дням, стек по серверам</h2><div class="chartbox" style="height:300px"><canvas id="cStack"></canvas></div></div>';
    echo '<div class="card"><h2>Накопительный профит за период</h2><div class="chartbox" style="height:240px"><canvas id="cCum"></canvas></div></div>';
    chart_js('cStack', "{type:'bar',data:{labels:".json_encode($labels).",datasets:[".implode(',',$ds)."]},".
        "options:{plugins:{legend:{labels:{color:'#64748b',boxWidth:12}}},responsive:true,maintainAspectRatio:false,".
        "scales:{x:{stacked:true,ticks:{color:'#64748b'},grid:{color:'#e5e9f0'}},y:{stacked:true,ticks:{color:'#64748b'},grid:{color:'#e5e9f0'}}}}}");
    chart_js('cCum', "{type:'line',data:{labels:".json_encode($labels).",datasets:[{label:'накоплено',data:".json_encode($cum).
        ",borderColor:'#16a34a',backgroundColor:'#16a34a33',fill:true,tension:.3}]},options:".baseOpts(true)."}");
}

/* ===================== АНАЛИТИКА ===================== */
function view_analytics(array $C, array $A, array $servers, string $today): void {
    echo '<h1>Аналитика</h1><div class="sub">Каркас: детектор аномалий (v1) + проекция профита (v0). Дальше расширяем.</div>';
    $servers = array_values(array_filter($servers, fn($s)=>(int)$s['is_enabled']===1));

    /* --- аномалии --- */
    echo '<div class="card"><h2>Аномалии</h2>';
    $flags=[];

    /* 1) mybid-подобное деление: >=3 домена с одинаковым rev/1k визитов до 4 знаков в один день */
    $perDay=[];
    foreach ($C['rows'] as $r){ $vs=(int)$r['visits']; if($vs<=0) continue; $rpv=round((float)$r['revenue']/$vs*1000,4); if($rpv<=0) continue; $perDay[$r['d']][number_format($rpv,4)][]=$r['domain']; }
    $mybid=[];
    foreach ($perDay as $d=>$vals) foreach ($vals as $k=>$list) if(count($list)>=3) $mybid[]="$d: ".count($list)." доменов с Rev/1k виз=$k";
    if($mybid) $flags[]=['bad',count($mybid).' дн. с признаком деления дохода (mybid_revenue_share)',implode('; ',array_slice($mybid,0,4))];
    else $flags[]=['ok','Деления дохода не видно','Rev/1k визитов уникальны — коннектор mybid_revenue_share в учёт не течёт.'];

    /* 2) домены со спендом без дохода (cost>=0.3, rev < 10% cost) за период */
    $burn=[]; foreach ($A['dom'] as $dm=>$v){ $c=(float)$v['cost']; $rv=(float)$v['rev']; if($c>=0.3 && $rv < 0.1*$c) $burn[$dm]=$c-$rv; }
    arsort($burn);
    if($burn){ $top=array_slice(array_keys($burn),0,8); $flags[]=['bad',count($burn).' домен(ов) жгут бюджет почти без дохода',
        'минус на них ≈ '.money(array_sum($burn)).'. Напр.: '.implode(', ',$top)]; }

    /* 3) резкие скачки профита по серверам: последний завершённый день vs среднее прошлых (z>2.5) */
    foreach ($servers as $s){
        $sid=(int)$s['id']; $ser=$A['srvDay'][$sid]??[]; if(count($ser)<4) continue; ksort($ser);
        $done=$ser; unset($done[$today]); if(count($done)<3) continue;
        $vals=array_values($done); $last=array_pop($vals);
        $mean=array_sum($vals)/count($vals);
        $sd=sqrt(array_sum(array_map(fn($x)=>($x-$mean)**2,$vals))/count($vals)); if($sd<=0.0001) continue;
        $z=($last-$mean)/$sd;
        if(abs($z)>2.5) $flags[]=[$z<0?'bad':'ok','Скачок у '.h($s['code']).' в последний день',
            'профит '.money($last).' против среднего '.money($mean).' (z='.number_format($z,1).')'];
    }

    /* 4) молчащие серверы */
    $stale=array_filter($servers, fn($s)=>is_stale($s['last_seen_at']));
    if($stale) $flags[]=['bad',count($stale).' сервер(ов) молчат >1ч',implode(', ',array_map(fn($s)=>$s['code'],$stale))];

    if(!$flags) echo '<div class="flag ok"><div class="t">Аномалий не найдено</div></div>';
    foreach ($flags as [$lvl,$t,$d]) echo '<div class="flag '.($lvl==='ok'?'ok':'bad').'"><div class="t">'.$t.'</div><div class="d">'.$d.'</div></div>';
    echo '<div class="note">Правила v1: деление дохода (одинаковый Rev/1k виз), пожиратели бюджета без дохода, z-скачки профита по серверам, молчание >1ч. Пороги настроим.</div></div>';

    /* --- проекция профита (v0) --- */
    echo '<div class="card"><h2>Проекция профита · 30 дней <span class="badge">v0, наивная линейная</span></h2>';
    $doms=[];
    foreach ($A['dom'] as $dm=>$v){
        $p=(float)$v['rev']-(float)$v['cost'];
        $daysN = max(1, count($A['day']) - 1); // завершённые дни
        $perDayP = $p / $daysN;
        $doms[]=['domain'=>$dm,'network'=>$v['network'],'p'=>$p,'perday'=>$perDayP,'proj'=>$perDayP*30,'roi'=>((float)$v['cost']>0?(float)$v['rev']/(float)$v['cost']:0)];
    }
    usort($doms, fn($a,$b)=>$b['proj']<=>$a['proj']);
    echo '<table><tr><th>Домен</th><th>Сеть</th><th>Профит/день</th><th>Проекция 30д</th><th>ROI</th><th>Сигнал</th></tr>';
    foreach (array_merge(array_slice($doms,0,10), array_slice($doms,-6)) as $x){
        $sig = $x['roi']>=2 && $x['perday']>0 ? '<span class="pos">масштабировать</span>' : ($x['perday']<0 ? '<span class="neg">резать</span>' : '<span class="dim">держать</span>');
        echo '<tr><td>'.h($x['domain']).'</td><td class="note">'.h($x['network']).'</td><td class="'.cls($x['perday']).'">'.money($x['perday']).'</td>';
        echo '<td class="'.cls($x['proj']).'">'.money($x['proj']).'</td><td>'.number_format($x['roi'],2).'x</td><td>'.$sig.'</td></tr>';
    }
    echo '</table><div class="note">v0 = средний дневной профит за период × 30. Это заглушка каркаса: дальше заменим на модель с трендом, сезонностью день/ночь и удержанием трафика. «масштабировать» = ROI≥2 и плюс, «резать» = стабильный минус.</div></div>';
}

/* ===================== helpers вёрстки ===================== */
function kpi(string $l, string $v, string $cls='', string $sub=''): void {
    echo '<div class="kpi"><div class="l">'.h($l).'</div><div class="v '.$cls.'">'.$v.'</div>'.
         ($sub!==''?'<div class="s" style="color:var(--dim);font-size:11px;margin-top:3px">'.h($sub).'</div>':'').'</div>';
}
function mini_dom_table(array $rows): void {
    echo '<table><tr><th>Домен</th><th>Профит</th><th>ROI</th></tr>';
    foreach ($rows as $x){ $cost=(float)$x['cost']; $rev=(float)$x['rev']; $p=(float)$x['p'];
        echo '<tr><td>'.dot($p).' '.h($x['domain']).'</td><td class="'.cls($p).'">'.money($p).'</td><td>'.roi_s($cost,$rev).'</td></tr>'; }
    echo '</table>';
}
function baseOpts(bool $legend=false): string {
    return "{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:".($legend?'true':'false').",labels:{color:'#64748b'}}},".
           "scales:{x:{ticks:{color:'#64748b'},grid:{color:'#e5e9f0'}},y:{ticks:{color:'#64748b'},grid:{color:'#e5e9f0'}}}}";
}
function chart_js(string $id, string $cfg): void { echo '<script>new Chart(document.getElementById("'.$id.'"),'.$cfg.');</script>'; }

function view_mainloop(): void {
    $rows = q(db(), "SELECT id,ts,status,summary,detail FROM center_loop ORDER BY id DESC LIMIT 120");
    echo '<h1>🧠 Main-loop центра</h1>';
    echo '<div class="sub">Тело рассуждений центра по каждому 5-мин циклу: находка/действие или «пусто». Свежие сверху.</div>';
    echo '<div class="card">';
    if (!$rows) { echo '<div class="note">Пока нет записей.</div>'; }
    foreach ($rows as $m) {
        $st = (string)$m['status'];
        $ico = $st==='action'?'🛠':($st==='finding'?'🔎':($st==='propose'?'💡':'·'));
        echo '<div class="msg'.($st==='noop'?'':' center').'">';
        echo '<div class="mh"><span class="who">'.$ico.' '.h($st).'</span>';
        echo '<span class="mt">'.h(substr((string)$m['ts'],5,11)).'</span></div>';
        echo '<div class="mtext"><b>'.h((string)$m['summary']).'</b>';
        if ((string)$m['detail']!=='') echo '<br><span style="color:#64748b">'.nl2br(h((string)$m['detail'])).'</span>';
        echo '</div></div>';
    }
    echo '</div>';
}

/* ===================== ПО ДНЯМ ===================== */
function view_days(int $days): void {
    $rows = q(db(), "SELECT d,
        ROUND(SUM(cost),4) cost, ROUND(SUM(revenue),4) rev, ROUND(SUM(revenue)-SUM(cost),4) profit,
        SUM(clicks) clicks, SUM(visits) visits,
        COUNT(DISTINCT domain) doms, COUNT(DISTINCT server_id) srvs
      FROM daily WHERE d >= CURDATE() - INTERVAL ? DAY
      GROUP BY d ORDER BY d DESC", [max($days,1)], 'i');
    $today = gmdate('Y-m-d');
    $done = array_filter($rows, fn($r)=>$r['d']!==$today);
    $sumP = array_sum(array_map(fn($r)=>(float)$r['profit'],$rows));
    $avgP = $done ? array_sum(array_map(fn($r)=>(float)$r['profit'],$done))/count($done) : 0.0;
    $best=null; $worst=null;
    foreach ($done as $r){ $p=(float)$r['profit'];
        if($best===null||$p>$best[1]) $best=[$r['d'],$p];
        if($worst===null||$p<$worst[1]) $worst=[$r['d'],$p]; }
    echo '<h1>📅 Цифры по дням</h1>';
    echo '<div class="kpis">';
    kpi('Профит за период', money($sumP), cls($sumP));
    kpi('Средний профит/день', money($avgP), cls($avgP), (string)count($done).' дн (без сегодня)');
    if($best) kpi('Лучший день', money($best[1]), cls($best[1]), (string)$best[0]);
    if($worst) kpi('Худший день', money($worst[1]), cls($worst[1]), (string)$worst[0]);
    echo '</div>';
    echo '<div class="card"><table><tr><th>Дата</th><th>Профит</th><th>ROI</th><th>Доход</th><th>Расход</th><th>Клики</th><th>Визиты</th><th>RPM/1k виз.</th><th>Домены</th><th>Серв.</th></tr>';
    foreach ($rows as $r){
        $p=(float)$r['profit']; $cost=(float)$r['cost']; $rev=(float)$r['rev'];
        $vis=(int)$r['visits']; $rpm=$vis>0?$rev/$vis*1000:null; $isT=$r['d']===$today;
        echo '<tr><td>'.dot($p).' '.h((string)$r['d']).($isT?' <span class="note">(сегодня)</span>':'').'</td>';
        echo '<td class="'.cls($p).'">'.money($p).'</td><td>'.roi_s($cost,$rev).'</td>';
        echo '<td>'.money($rev).'</td><td>'.money($cost).'</td>';
        echo '<td>'.num((int)$r['clicks']).'</td><td>'.num($vis).'</td>';
        echo '<td>'.($rpm===null?'—':number_format($rpm,3)).'</td>';
        echo '<td>'.(int)$r['doms'].'</td><td>'.(int)$r['srvs'].'</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="10" class="note">Нет данных.</td></tr>';
    echo '</table></div>';
    // --- подробные графики по дням ---
    $rr=array_reverse($rows); $lab=[];$cR=[];$cC=[];$cP=[];$cV=[];$cK=[];$cRU=[];$cCU=[];$cRPM=[];
    foreach($rr as $r){ $lab[]=substr((string)$r['d'],5); $rev=(float)$r['rev'];$cost=(float)$r['cost'];$vis=(int)$r['visits'];
        $cR[]=round($rev,2);$cC[]=round($cost,2);$cP[]=round((float)$r['profit'],2);$cV[]=$vis;$cK[]=(int)$r['clicks'];
        $cRU[]=$vis>0?round($rev/$vis,4):0;$cCU[]=$vis>0?round($cost/$vis,4):0;$cRPM[]=$vis>0?round($rev/$vis*1000,3):0; }
    if($rows){
      echo '<div class="card"><h2>Доход · Расход · Профит по дням</h2><div class="chartbox"><canvas id="dRCP"></canvas></div></div>';
      echo '<div class="card"><h2>Визиты и клики (показы) по дням</h2><div class="chartbox"><canvas id="dVK"></canvas></div></div>';
      echo '<div class="card"><h2>Доход и расход на юзера (на визит)</h2><div class="chartbox"><canvas id="dPU"></canvas></div></div>';
      echo '<div class="card"><h2>RPM · доход на 1000 визитов</h2><div class="chartbox"><canvas id="dRPM"></canvas></div></div>';
      chart_js('dRCP', "{type:'line',data:{labels:".json_encode($lab).",datasets:[{label:'доход',data:".json_encode($cR).",borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.08)',tension:.3,fill:true,pointRadius:2},{label:'расход',data:".json_encode($cC).",borderColor:'#dc2626',tension:.3,fill:false,pointRadius:2},{label:'профит',data:".json_encode($cP).",borderColor:'#2563eb',tension:.3,fill:false,pointRadius:2,borderWidth:2.5}]},options:".baseOpts(true)."}");
      chart_js('dVK', "{type:'line',data:{labels:".json_encode($lab).",datasets:[{label:'визиты',data:".json_encode($cV).",borderColor:'#7c3aed',tension:.3,fill:false,pointRadius:2},{label:'клики (показы)',data:".json_encode($cK).",borderColor:'#ea580c',tension:.3,fill:false,pointRadius:2}]},options:".baseOpts(true)."}");
      chart_js('dPU', "{type:'line',data:{labels:".json_encode($lab).",datasets:[{label:'доход/юзер',data:".json_encode($cRU).",borderColor:'#16a34a',tension:.3,fill:false,pointRadius:2},{label:'расход/юзер',data:".json_encode($cCU).",borderColor:'#dc2626',tension:.3,fill:false,pointRadius:2}]},options:".baseOpts(true)."}");
      chart_js('dRPM', "{type:'bar',data:{labels:".json_encode($lab).",datasets:[{label:'RPM',data:".json_encode($cRPM).",backgroundColor:'#0891b2'}]},options:".baseOpts()."}");
    }
    echo '<div class="note">Визиты/RPM могут быть занижены при недоступной Яндекс-Метрике; доход считается по коннекторам факта, не по визитам.</div>';
}


/* ============ ДОМЕН ПО ДНЯМ ============ */
function view_domain_days(string $dom, int $days): void {
    $win = max($days, 30);
    $rows = q(db(), "SELECT d,
        ROUND(SUM(cost),4) cost, ROUND(SUM(revenue),4) rev, ROUND(SUM(revenue)-SUM(cost),4) profit,
        SUM(clicks) clicks, SUM(visits) visits
      FROM daily WHERE domain=? AND d >= CURDATE() - INTERVAL ? DAY
      GROUP BY d ORDER BY d DESC", [$dom,$win], 'si');
    $today = gmdate('Y-m-d');
    $sumP = array_sum(array_map(fn($r)=>(float)$r['profit'],$rows));
    $sumC = array_sum(array_map(fn($r)=>(float)$r['cost'],$rows));
    $sumR = array_sum(array_map(fn($r)=>(float)$r['rev'],$rows));
    echo '<h1>📅 '.h($dom).' · по дням</h1>';
    echo '<div class="sub"><a class="badge" href="?view=domains">← ко всем доменам</a> <span class="note">последние '.$win.' дн</span></div>';
    echo '<div class="kpis">';
    kpi('Профит', money($sumP), cls($sumP));
    kpi('Доход', money($sumR));
    kpi('Расход', money($sumC));
    kpi('ROI', roi_s($sumC,$sumR));
    echo '</div>';
    echo '<div class="card"><table><tr><th>Дата</th><th>Профит</th><th>ROI</th><th>Доход</th><th>Расход</th><th>Клики</th><th>Визиты</th><th>RPM/1k виз.</th></tr>';
    foreach ($rows as $r){
        $p=(float)$r['profit']; $cost=(float)$r['cost']; $rev=(float)$r['rev'];
        $vis=(int)$r['visits']; $rpm=$vis>0?$rev/$vis*1000:null; $isT=$r['d']===$today;
        echo '<tr><td>'.dot($p).' '.h((string)$r['d']).($isT?' <span class="note">(сегодня)</span>':'').'</td>';
        echo '<td class="'.cls($p).'">'.money($p).'</td><td>'.roi_s($cost,$rev).'</td>';
        echo '<td>'.money($rev).'</td><td>'.money($cost).'</td>';
        echo '<td>'.num((int)$r['clicks']).'</td><td>'.num($vis).'</td>';
        echo '<td>'.($rpm===null?'—':number_format($rpm,3)).'</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="8" class="note">Нет данных по домену.</td></tr>';
    echo '</table></div>';
}

/* ===================== ШАБЛОНЫ ===================== */
function view_templates(array $A): void {
    $meta=[]; foreach (q(db(),"SELECT domain,html_donor,modernized FROM domain_meta") as $m) $meta[$m['domain']]=$m;
    $cat=json_decode((string)@file_get_contents(__DIR__.'/donors.json'),true);
    $src=[]; $nm=[]; foreach (($cat['templates']??[]) as $t){ $src[$t['id']]=$t['donors']['index']??($t['source_domain']??''); if(is_file(__DIR__.'/templates/v1/'.$t['id'].'/index.html')) $src[$t['id']]='https://pornfluxx.ru/money/templates/v1/'.$t['id'].'/index.html'; elseif(is_file(__DIR__.'/templates/v1/'.$t['id'].'/categories.html')) $src[$t['id']]='https://pornfluxx.ru/money/templates/v1/'.$t['id'].'/categories.html'; elseif(is_file(__DIR__.'/templates/v1/'.$t['id'].'/category.html')) $src[$t['id']]='https://pornfluxx.ru/money/templates/v1/'.$t['id'].'/category.html'; $nm[$t['id']]=$t['name']??$t['id']; }
    $paths=[]; foreach (($cat['templates']??[]) as $t){ $b='https://pornfluxx.ru/money/templates/v1'; $l=$t['local']??[]; $paths[$t['id']]=['i'=>isset($l['index'])?$b.$l['index']:'','c'=>isset($l['category'])?$b.$l['category']:(isset($l['search'])?$b.$l['search']:''),'v'=>isset($l['video'])?$b.$l['video']:'']; }
    $ptpl = implode("\n", [
      "ТЗ: пересобрать сайт {SITE} из шаблонной помойки в УНИКАЛЬНЫЙ конверсионный актив (движок + Claude + Grok).",
      "Философия: купленный трафик не должен отторгаться — сайт уникальный, нишевый, быстрый, красивый; цель — деньги на дистанции (возвраты, органика), а не сегодня. Заложись хорошо.",
      "",
      "ВХОД: домен {SITE}; донор-шаблон {DONOR} («{DNAME}», папка /temp/{DONOR}/ на hardpulse.ru): главная {TI} | категория {TC} | видео {TV}; БД сайта (видео/категории/теги); коннекторы дохода в fapacrm.",
      "Вероятная ниша домена: {NICHE} (уточни по названию и контенту).",
      "ДАННЫЕ (это продуктовая работа — у центра есть ВСЯ стата): гео трафика, доход по коннекторам, статистика просмотров видео и посетителей. Используй: приоритизируй топ-видео по реальным просмотрам, подтверди нишу и основной язык по топ-гео, локализуй под топ-страны, в топ выдачи выноси то, что реально смотрят.",
      "",
      "0. ГИГИЕНА БД (СНАЧАЛА): оставь ТОЛЬКО видео с реально рабочим файлом/embed (бывает перекос ~12k записей vs ~3k живых). Пустые/битые — в архив (бэкап таблицы, не жёсткое удаление). Убери дубли, мёртвые превью/embed, пересчитай счётчики категорий. Итог: каталог = только живой контент.",
      "1. ПЕРЕНОС НА ШАБЛОН: вёрстку и UX донора перенеси на движок (index.php, cat.php, video.php), данные бери из нашей БД. Сохрани сортировку популярных, пагинацию, рабочий плеер. Перенеси обвязку: трекинг кликов/переходов, my_js, коннекторы дохода, привязки ad_block_pages.",
      "2. МОНЕТИЗАЦИЯ: строго по коннекторам домена в БД (fapacrm) — tacoloco/clickadu/mybid, универсально; сохрани существующие ad_blocks/коннекторы. Если в сетке домена доступны inpage push и web push — вшить ОБЯЗАТЕЛЬНО (лучшие форматы, ненавязчивы, дают 80%+ дохода). Баннер сверху не ставить, форматы размещай ненавязчиво.",
      "3. УНИКАЛИЗАЦИЯ (анти-футпринт): мутируй HTML/CSS-структуру (имена классов, порядок блоков, обёртки) — сайт не должен быть клоном ни донора, ни соседних наших. Свой favicon, логотип, набор иконок; уникальные тексты (заголовки, описания, about, футер) — генерим, не копируем.",
      "4. НИША/СТИЛИЗАЦИЯ: подстрой сайт под нишу {NICHE} — поднимай/выбирай релевантные видео и теги, делай тематические подборки и «горячие разделы», палитра и настроение под нишу.",
      "5. GROK-ГЕНЕРАТИВКА (xAI, ключ предоставит центр): grok-imagine-image-2.0 — уникальные логотип/favicon/og-превью/иконки/фоны на КАЖДЫЙ сайт; grok-4.6 — уникальные мета-тексты, описания видео и категорий, about, микрокопирайт под нишу и язык; (на дистанции) grok-imagine-video — интро/тизеры.",
      "6. ЛОКАЛИЗАЦИЯ → НАТИВНОСТЬ: сейчас у нас /en и др. Сделай язык нативным: корректные lang/hreflang, локализованные тексты через Grok (не машинный мусор), убери костыльный /en если домен моноязычный. Один домен = один основной язык + чистая разметка.",
      "7. SEO (под органику): schema.org (VideoObject/ItemList), OpenGraph, чистые ЧПУ, sitemap.xml, robots.txt. On-the-fly уникализация тегов и описаний под каждое видео (Grok) — против дублей контента. Скорость: lazy-load, сжатие, минимум мусорного JS.",
      "8. PWA/ТЕХ (на дистанцию): manifest + service worker (кэш, add-to-home) → возвраты и push-ретеншен; быстрая мобильная версия (основной трафик).",
      "9. ПРОВЕРКА (без неё НЕ отчитывайся «готово»): php -l всех правленых файлов — чисто; curl главной/категории/видео — рендерится новый шаблон с нашими данными; консоль JS без ошибок (плеер, пагинация, lazy); коннекторы и пуши реально отдаются; скорость ок (Lighthouse). Сделай БЭКАП до правок. Отчёт в chat: что сделано + curl-evidence + URL.",
      "",
      "БУДУЩЕЕ (заложи в архитектуру, но не блокируй запуск): авто-уникализация видео (обрезка/интро через grok-imagine-video); ресёрч трендов и фич tube-сайтов через Grok; A/B тем и подборок; метрика возвратов (после починки токена Метрики).",
      "ДИСЦИПЛИНА: бэкап перед правками; не трогай чужие домены; Kadam 141381 — только свои кампании; работай так, будто через полгода этот сайт должен приносить много.",
    ]);
    $dsrv=[]; foreach(q(db(),"SELECT d.domain, s.code FROM daily d JOIN servers s ON s.id=d.server_id WHERE d.d>=CURDATE()-INTERVAL 30 DAY GROUP BY d.domain,s.code") as $r){ $dsrv[$r['domain']]=$r['code']; }
    $srvf=(string)($_GET['srv'] ?? '');
    $doms=array_keys($meta); sort($doms);
    if($srvf!=='') $doms=array_values(array_filter($doms, fn($d)=>($dsrv[$d]??'')===$srvf));
    $nmod=0; foreach($doms as $d){ if((int)($meta[$d]['modernized']??0)) $nmod++; }
    $shot=fn($u)=> 'https://s.wordpress.com/mshots/v1/'.rawurlencode($u).'?w=420&h=300';
    $shotLocal=function(string $key,string $fb){ $f=__DIR__.'/shots/'.$key; if(is_file($f)&&filesize($f)>0) return 'shots/'.$key.'?v='.filemtime($f); return 'https://s.wordpress.com/mshots/v1/'.rawurlencode($fb).'?w=420&h=300'; };
    echo '<h1>🎨 Домены · шаблоны</h1>';
    echo '<div class="sub">📦 Архив всех шаблонов: <a href="https://pornfluxx.ru/money/templates/donors-templates-backup-2026-10-29.tar.gz" download>donors-templates-backup-2026-10-29.tar.gz (4.3 МБ, 39 доноров)</a> · файлы физически: /var/www/pornfluxx.ru/money/templates/v1/</div>';
    echo '<div class="sub">Сервер: <a class="badge" style="'.($srvf===''?'font-weight:700':'').'" href="?view=templates">все</a>';
    $codes=[]; foreach($dsrv as $c){ $codes[$c]=1; } ksort($codes);
    foreach(array_keys($codes) as $c) echo '<a class="badge" style="'.($srvf===$c?'font-weight:700':'').'" href="?view=templates&srv='.h($c).'">'.h($c).'</a>';
    echo '</div>';
    echo '<div class="sub">Слева — донор-шаблон, справа — текущий сайт. Выбери донор и отметь «готово» прямо на плитке. Модернизировано: <b>'.$nmod.'</b> / '.count($doms).'</div>';
    $opts='<option value="">— не выбран —</option>'; foreach($nm as $id=>$label){ $opts.='<option value="'.h($id).'">'.h($label).'</option>'; }
    echo '<div id="promptModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:250" onclick="if(event.target===this)this.style.display=&#39;none&#39;"><div style="background:#fff;max-width:720px;margin:6vh auto;padding:18px;border-radius:10px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b>Промт для агента (перепись сайта на шаблон)</b><span><button onclick="pmCopy(this)" style="background:#2563eb;color:#fff;border:0;padding:6px 14px;border-radius:6px;cursor:pointer">Копировать</button> <button onclick="document.getElementById(&#39;promptModal&#39;).style.display=&#39;none&#39;" style="padding:6px 10px;cursor:pointer">✕</button></span></div><textarea id="pmText" style="width:100%;height:54vh;font:12px/1.45 ui-monospace,monospace;padding:10px;border:1px solid #cbd5e1;border-radius:6px" readonly></textarea></div></div>';
    echo '<div class="tpl-grid">';
    foreach ($doms as $dm){
        $dn=(string)($meta[$dm]['html_donor']??''); $md=(int)($meta[$dm]['modernized']??0);
        $site='https://'.$dm; $tplUrl=$dn!==''?(string)($src[$dn]??''):'';
        $selOpts = $dn!=='' ? str_replace('value="'.h($dn).'"','value="'.h($dn).'" selected',$opts) : $opts;
        echo '<div class="tpl-card'.($md?' done':'').'">';
        echo '<div class="tpl-h"><a href="'.h($site).'" target="_blank" rel="noopener"><b>'.h($dm).'</b> ↗</a><span class="tbadge '.($md?'ok':'no').'">'.($md?'готово':'не готово').'</span></div>';
        echo '<div class="tpl-row">';
        echo '<div class="tpl-col"><div class="tpl-lbl">шаблон</div><img class="tplimg tplimg-tpl" loading="lazy" src="'.($tplUrl!==''?h($shotLocal('tpl_'.$dn.'.jpg',$tplUrl)):'').'" onerror="tplRetry(this)" alt=""></div>';
        echo '<div class="tpl-col"><div class="tpl-lbl">текущий сайт</div><a href="'.h($site).'" target="_blank" rel="noopener"><img class="tplimg" loading="lazy" src="'.h($shotLocal('site_'.md5($dm).'.jpg',$site)).'" onerror="tplRetry(this)" alt=""></a></div>';
        echo '</div>';
        $niche=''; foreach(['milf'=>'MILF','wife'=>'жёны/wife','asian'=>'азиатки/asian','teen'=>'teen','bbw'=>'BBW','anal'=>'anal','mom'=>'mature/mom','babe'=>'babes','порев'=>'русское','porev'=>'русское','tube'=>'general tube','xxx'=>'general xxx'] as $k=>$v){ if(stripos($dm,$k)!==false){ $niche=$v; break; } }
        $prompt = $dn!=='' ? strtr($ptpl, ['{SITE}'=>$site,'{DNAME}'=>(string)($nm[$dn]??$dn),'{DONOR}'=>$dn,'{TI}'=>(string)($paths[$dn]['i']??''),'{TC}'=>(string)($paths[$dn]['c']??''),'{TV}'=>(string)($paths[$dn]['v']??''),'{NICHE}'=>($niche!==''?$niche:'определи по названию/контенту')]) : '';
        echo '<div class="tpl-ctl"><select class="tpl-donor" data-dom="'.h($dm).'">'.$selOpts.'</select><label class="tpl-done-l"><input type="checkbox" class="tpl-done" data-dom="'.h($dm).'" '.($md?'checked':'').'> готово</label>';
        if($prompt!=='') echo '<button type="button" class="tpl-prompt" data-p="'.h(base64_encode($prompt)).'" title="промт для агента">📋</button>';
        echo '<button type="button" class="tpl-snap" data-dom="'.h($dm).'" title="пересъёмка скринов">⟳</button></div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<script>';
    echo 'var DONORSRC='.json_encode($src, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';';
    echo 'function shot(u){return u?"https://s.wordpress.com/mshots/v1/"+encodeURIComponent(u)+"?w=420&h=300":"";}';
    echo 'function tplRetry(img){var t=+(img.dataset.tries||0);if(t>=3)return;img.dataset.tries=t+1;var b=img.src.split("&_r=")[0];setTimeout(function(){img.src=b+"&_r="+Date.now();},4000);}';
    echo 'function tpost(dom,donor,mod){var fd=new FormData();fd.append("action","savemeta");fd.append("domain",dom);fd.append("donor",donor);fd.append("modernized",mod);return fetch(location.pathname,{method:"POST",body:fd}).then(function(r){return r.json();});}';
    echo 'document.querySelectorAll(".tpl-donor").forEach(function(sel){sel.addEventListener("change",function(){var card=sel.closest(".tpl-card");var dom=sel.dataset.dom;var mod=card.querySelector(".tpl-done").checked?1:0;tpost(dom,sel.value,mod);var img=card.querySelector(".tplimg-tpl");var u=DONORSRC[sel.value]||"";if(img){img.dataset.tries=0;img.src=shot(u);}});});';
    echo 'document.querySelectorAll(".tpl-done").forEach(function(cb){cb.addEventListener("change",function(){var card=cb.closest(".tpl-card");var dom=cb.dataset.dom;var donor=card.querySelector(".tpl-donor").value;var mod=cb.checked?1:0;tpost(dom,donor,mod);card.classList.toggle("done",cb.checked);var b=card.querySelector(".tbadge");b.className="tbadge "+(cb.checked?"ok":"no");b.textContent=cb.checked?"готово":"не готово";});});';
    echo 'document.querySelectorAll(".tpl-snap").forEach(function(b){b.addEventListener("click",function(){var dom=b.dataset.dom;var o=b.textContent;b.textContent="…";b.disabled=true;var fd=new FormData();fd.append("action","snap");fd.append("domain",dom);fetch(location.pathname,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(){location.reload();}).catch(function(){b.textContent=o;b.disabled=false;});});});';
    echo 'document.querySelectorAll(".tpl-prompt").forEach(function(b){b.addEventListener("click",function(){var t=decodeURIComponent(escape(atob(b.dataset.p)));document.getElementById("pmText").value=t;document.getElementById("promptModal").style.display="block";});});window.pmCopy=function(btn){var ta=document.getElementById("pmText");ta.select();ta.setSelectionRange(0,999999);try{document.execCommand("copy");}catch(e){}if(navigator.clipboard)navigator.clipboard.writeText(ta.value);var o=btn.textContent;btn.textContent="Скопировано";setTimeout(function(){btn.textContent=o;},1200);};';
    echo '</script>';
    echo '<style>.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;margin-top:10px}.tpl-card{background:#fff;border:1px solid #e5e9f0;border-radius:10px;padding:10px}.tpl-card.done{border-color:#16a34a;box-shadow:0 0 0 1px #16a34a33}.tpl-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px}.tpl-h a{color:#2563eb;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tpl-row{display:flex;gap:8px}.tpl-col{flex:1;min-width:0}.tpl-lbl{font-size:11px;color:#64748b;margin-bottom:4px}.tpl-col img{width:100%;height:160px;object-fit:cover;object-position:top center;border:1px solid #e5e9f0;border-radius:6px;background:#f1f5f9;display:block}.tbadge{font-size:11px;padding:2px 8px;border-radius:10px;white-space:nowrap}.tbadge.ok{background:#dcfce7;color:#166534}.tbadge.no{background:#fdecec;color:#b91c1c}.tpl-ctl{display:flex;gap:8px;align-items:center;margin-top:8px}.tpl-donor{flex:1;min-width:0;padding:5px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px}.tpl-done-l{font-size:12px;white-space:nowrap;cursor:pointer}.tpl-snap{border:1px solid #cbd5e1;background:#f8fafc;border-radius:6px;cursor:pointer;padding:4px 9px;font-size:14px;line-height:1}.tpl-snap:hover{background:#eef2f7}.tpl-prompt{border:1px solid #cbd5e1;background:#f8fafc;border-radius:6px;cursor:pointer;padding:4px 8px;font-size:14px;line-height:1}.tpl-prompt:hover{background:#eef2f7}</style>';
}

/* ===================== РЕГИСТРАТОР ===================== */
/**
 * Статус доменов у реестра. Данные кладёт /root/domain-registrar-watch.php
 * (крон, раз в 3 часа) — здесь только показ, никаких запросов наружу.
 *
 * Смысл раздела: отличить «домен не приносит денег» от «домена у нас больше нет».
 * Второе снаружи выглядит так же — трафик просто исчезает, — но лечится не ставками.
 */
function view_registrar(array $A, int $days=7): void {
    $db = db();
    $rows = q($db, "SELECT * FROM domain_registrar ORDER BY FIELD(verdict,'dead','warn','unknown','ok'), domain");
    echo '<h1>🛂 Регистратор <small>· статус доменов у реестра</small></h1>';
    if (!$rows) {
        echo '<div class="card"><div class="note">Обхода ещё не было. Запусти <code>php /root/domain-registrar-watch.php</code> на 146.</div></div>';
        return;
    }

    $cnt = ['ok'=>0,'warn'=>0,'dead'=>0,'unknown'=>0];
    $byReg = [];
    $lastCheck = '';
    foreach ($rows as $r) {
        $v = $r['verdict']; $cnt[$v] = ($cnt[$v] ?? 0) + 1;
        $k = $r['registrar'] ?: '—';
        $byReg[$k][$v] = ($byReg[$k][$v] ?? 0) + 1;
        if ($r['checked_at'] > $lastCheck) $lastCheck = (string)$r['checked_at'];
    }
    $stale = $lastCheck === '' || (time() - strtotime($lastCheck)) > 6*3600;
    echo '<div class="sub">Последний обход: '.h($lastCheck ?: 'никогда').($stale ? ' — <b>данные устарели</b>' : '').
         ' · источник: RDAP реестра, для .ru/.io — whois TCI/NIC · проверяется реестр + DNS + ответ сайта</div>';

    echo '<div class="kpis">';
    echo '<div class="kpi"><div class="l">Доменов</div><div class="v">'.num(count($rows)).'</div></div>';
    echo '<div class="kpi"><div class="l">🟢 Живые</div><div class="v pos">'.num($cnt['ok']).'</div></div>';
    echo '<div class="kpi"><div class="l">🟡 Под вопросом</div><div class="v">'.num($cnt['warn']).'</div></div>';
    echo '<div class="kpi"><div class="l">🔴 Потеряны</div><div class="v neg">'.num($cnt['dead']).'</div></div>';
    echo '<div class="kpi"><div class="l">⚪ Реестр молчит</div><div class="v">'.num($cnt['unknown']).'</div></div>';
    echo '</div>';

    /* Блокировка аккаунта против смерти одного домена: если у одного регистратора
       разом слегла половина и больше — дело в аккаунте, чинить надо там. */
    foreach ($byReg as $rg => $c) {
        $tot = array_sum($c); $dead = $c['dead'] ?? 0;
        if ($dead >= 3 && $dead >= $tot/2)
            echo '<div class="flag bad"><div class="t">⚠️ Похоже на блокировку аккаунта у '.h($rg).'</div>'.
                 '<div class="d">Из '.$tot.' доменов этого регистратора разом легло '.$dead.'. Один домен так не падает — это аккаунт.</div>'.
                 '<div class="d">→ <b>Разбираться с регистратором, домены переносить к другому. Ставки и креативы тут ни при чём.</b></div></div>';
    }

    /* Сколько денег стоит простой: расход по мёртвым доменам за период */
    $lostCost = $lostRev = 0.0;
    foreach ($rows as $r) if ($r['verdict']==='dead') {
        $d = $A['dom'][$r['domain']] ?? null;
        if ($d) { $lostCost += (float)$d['cost']; $lostRev += (float)$d['rev']; }
    }
    if ($lostCost > 0)
        echo '<div class="flag bad"><div class="t">По мёртвым доменам за '.(int)$days.'д всё ещё идёт расход '.money($lostCost).'</div>'.
             '<div class="d">доход с них '.money($lostRev).'. Трафик покупается на домены, которые не отвечают.</div>'.
             '<div class="d">→ <b>Остановить кампании Kadam по этим доменам, пока домены не вернутся.</b></div></div>';

    echo '<div class="card"><h2>По регистраторам</h2><table><tr><th>Регистратор</th><th>Всего</th><th>🟢</th><th>🟡</th><th>🔴</th><th>⚪</th></tr>';
    uasort($byReg, fn($a,$b)=>($b['dead']??0)<=>($a['dead']??0));
    foreach ($byReg as $rg=>$c)
        echo '<tr><td>'.h($rg).'</td><td>'.array_sum($c).'</td><td>'.($c['ok']??0).'</td><td>'.($c['warn']??0).
             '</td><td class="'.((($c['dead']??0)>0)?'neg':'').'">'.($c['dead']??0).'</td><td>'.($c['unknown']??0).'</td></tr>';
    echo '</table></div>';

    $ico = ['ok'=>'🟢','warn'=>'🟡','dead'=>'🔴','unknown'=>'⚪'];
    echo '<div class="card"><h2>Домены</h2><table><tr><th>Домен</th><th></th><th>Регистратор</th><th>Оплачен до</th>'.
         '<th>NS</th><th>A-запись</th><th>HTTP</th><th>Расход '.(int)$days.'д</th><th>Что не так</th></tr>';
    foreach ($rows as $r) {
        $exp = $r['expires_at'];
        $left = $exp ? (int)floor((strtotime($exp) - time())/86400) : null;
        $expCls = ($left !== null && $left <= 30) ? 'neg' : '';
        $dm = $A['dom'][$r['domain']] ?? null;
        $cost = $dm ? (float)$dm['cost'] : 0.0;
        echo '<tr>';
        echo '<td><a href="?view=domains&days='.(int)$days.'">'.h($r['domain']).'</a></td>';
        echo '<td>'.($ico[$r['verdict']] ?? '⚪').'</td>';
        echo '<td>'.h(mb_substr((string)$r['registrar'], 0, 24) ?: '—').'</td>';
        echo '<td class="'.$expCls.'">'.h((string)($exp ?: '—')).($left!==null ? ' <span class="dim">'.$left.'д</span>' : '').'</td>';
        echo '<td class="dim" style="font-size:11px">'.h(mb_substr((string)$r['ns'], 0, 46) ?: '—').'</td>';
        echo '<td class="dim" style="font-size:11px">'.h((string)($r['dns_a'] ?: 'нет')).'</td>';
        echo '<td>'.((int)$r['http_code'] ?: '—').'</td>';
        echo '<td class="'.($cost>0 && $r['verdict']==='dead' ? 'neg' : '').'">'.($cost>0 ? money($cost) : '—').'</td>';
        echo '<td style="font-size:12px">'.h((string)$r['reason']).'</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    $log = q($db, "SELECT * FROM domain_registrar_log ORDER BY ts DESC LIMIT 40");
    if ($log) {
        echo '<div class="card"><h2>Смены статуса</h2><table><tr><th>Когда</th><th>Домен</th><th>Было</th><th>Стало</th><th>Причина</th></tr>';
        foreach ($log as $l)
            echo '<tr><td class="dim">'.h((string)$l['ts']).'</td><td>'.h($l['domain']).'</td><td>'.($ico[$l['old_v']]??'').' '.h($l['old_v']).
                 '</td><td>'.($ico[$l['new_v']]??'').' '.h($l['new_v']).'</td><td style="font-size:12px">'.h($l['reason']).'</td></tr>';
        echo '</table></div>';
    }

    echo '<div class="note">Что считается смертью: статус реестра clientHold/serverHold/redemption, домена нет в реестре, '.
         'для .ru снято делегирование, срок оплаты истёк, NS уведены на заглушку регистратора, домен резолвится на чужой IP, '.
         'сайт не отвечает или отдаёт парковку. Жёлтое: до продления меньше 30 дней, сменились NS или регистратор, '.
         'HTTP 4xx/5xx, редирект на чужой хост. Обход раз в 3 часа кроном; вручную — '.
         '<code>php /root/domain-registrar-watch.php --domain=x.ru</code>.</div>';
}

/* ===================== ИГРЫ ===================== */
/**
 * Яндекс.Игры. Единица учёта здесь не домен, а игра: в daily она лежит под
 * ключом g<game_id>, имя и рублёвый оригинал — в meta_json.
 *
 * Деньги приходят уже в долларах: сервер игр делит рубли на курс при отправке,
 * чтобы вся сетка считалась в одном знаменателе. Рубли показываем рядом —
 * их и сверяют с Консолью Игр.
 */
function view_games(int $days=7): void {
    $db = db();
    $from = (new DateTime('-'.($days-1).' days'))->format('Y-m-d');
    $rows = q($db, "SELECT dd.d, dd.domain, dd.cost, dd.revenue, dd.clicks, dd.visits, dd.meta_json, s.code
                      FROM daily dd JOIN servers s ON s.id = dd.server_id
                     WHERE s.kind='game' AND dd.d >= ? ORDER BY dd.d", [$from]);
    $all  = q($db, "SELECT dd.domain, dd.d, dd.cost, dd.revenue, dd.visits, dd.meta_json
                      FROM daily dd JOIN servers s ON s.id = dd.server_id WHERE s.kind='game'");

    echo '<h1>🕹 Игры <small>· '.(int)$days.'д</small></h1>';
    echo '<div class="sub">Яндекс.Игры. Считаем по играм, а не по доменам. Источник платит в рублях — в центр приходит доллар по курсу '.
         number_format(RUB_USD, 0).' ₽/$, рубли рядом для сверки с Консолью.</div>';

    if (!$all) {
        echo '<div class="card"><div class="note">Данных по играм ещё нет. Отправка: <code>php /root/money-push-games.php</code> на 167.172.183.157, крон каждые 10 минут.</div></div>';
        return;
    }

    /* --- разбор: имена и рубли лежат в meta --- */
    $nameOf = [];
    $mk = function(array $r) use (&$nameOf): array {
        $m = json_decode((string)($r['meta_json'] ?? ''), true) ?: [];
        $key = (string)$r['domain'];
        if (!empty($m['name'])) $nameOf[$key] = (string)$m['name'];
        return $m;
    };

    $per = []; $byDay = []; $tc = $tr = 0.0; $tv = 0; $trub = $crub = 0.0;
    foreach ($rows as $r) {
        $m = $mk($r);
        $k = (string)$r['domain'];
        $c = (float)$r['cost']; $rv = (float)$r['revenue']; $v = (int)$r['visits'];
        if (!isset($per[$k])) $per[$k] = ['cost'=>0.0,'rev'=>0.0,'vis'=>0,'days'=>0,'ads'=>0,'rub'=>0.0,'crub'=>0.0,'last'=>''];
        $per[$k]['cost'] += $c; $per[$k]['rev'] += $rv; $per[$k]['vis'] += $v; $per[$k]['days']++;
        $per[$k]['ads']  += (int)($m['ad_shows'] ?? 0);
        $per[$k]['rub']  += (float)($m['rev_rub'] ?? 0); $per[$k]['crub'] += (float)($m['cost_rub'] ?? 0);
        if ($r['d'] > $per[$k]['last']) $per[$k]['last'] = (string)$r['d'];
        $byDay[$r['d']]['rev'] = ($byDay[$r['d']]['rev'] ?? 0) + $rv;
        $byDay[$r['d']]['cost'] = ($byDay[$r['d']]['cost'] ?? 0) + $c;
        $tc += $c; $tr += $rv; $tv += $v;
        $trub += (float)($m['rev_rub'] ?? 0); $crub += (float)($m['cost_rub'] ?? 0);
    }
    /* всё время — по нему видно, что игра вообще заработала, а не только за окно */
    $lc = $lr = 0.0; $lv = 0; $lifeGames = []; $lrub = 0.0;
    foreach ($all as $r) {
        $m = $mk($r);
        $lc += (float)$r['cost']; $lr += (float)$r['revenue']; $lv += (int)$r['visits'];
        $lrub += (float)($m['rev_rub'] ?? 0);
        $lifeGames[(string)$r['domain']] = 1;
    }

    $tp = $tr - $tc;
    $rpm = $tv > 0 ? $tr / $tv * 1000 : 0.0;
    $rub = fn(float $v) => number_format($v, 2, ',', ' ').' ₽';
    echo '<div class="kpis">';
    kpi('Доход',             money($tr), 'pos',    $rub($trub));
    kpi('Расход',            money($tc), '',       $rub($crub));
    kpi('Профит',            money($tp), cls($tp), $rub($trub - $crub));
    kpi('ROI',               roi_s($tc, $tr));
    kpi('Игр',               num(count($per)), '', 'всего заведено '.num(count($lifeGames)));
    kpi('Сессии',            num($tv));
    kpi('Доход / 1k сессий', number_format($rpm, 4));
    echo '</div>';

    echo '<div class="flag ok"><div class="t">За всё время: профит '.money($lr-$lc).' <span class="dim">('.number_format($lrub,2,',',' ').' ₽ дохода)</span></div>'.
         '<div class="d">доход '.money($lr).', расход '.money($lc).', сессий '.num($lv).', игр '.num(count($lifeGames)).'. Курс '.number_format(RUB_USD,0).' ₽/$.</div></div>';

    /* --- по дням --- */
    ksort($byDay);
    $labels = array_keys($byDay); $rev = $cost = $prof = [];
    foreach ($byDay as $v) { $rev[] = round($v['rev'],4); $cost[] = round($v['cost'],4); $prof[] = round($v['rev']-$v['cost'],4); }
    echo '<div class="card"><h2>Доход / расход / профит по дням</h2><div class="chartbox"><canvas id="gD"></canvas></div></div>';
    chart_js('gD', "{type:'bar',data:{labels:".json_encode($labels).",datasets:[".
      "{label:'доход',data:".json_encode($rev).",backgroundColor:'#16a34a'},".
      "{label:'расход',data:".json_encode($cost).",backgroundColor:'#dc2626'},".
      "{label:'профит',type:'line',data:".json_encode($prof).",borderColor:'#2563eb',borderWidth:2,pointRadius:0}]},".
      "options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#64748b'}}},scales:{x:{ticks:{color:'#64748b'},grid:{display:false}},y:{ticks:{color:'#64748b'},grid:{color:'#e5e9f0'}}}}}");

    /* --- по играм --- */
    uasort($per, fn($a,$b)=>($b['rev']-$b['cost'])<=>($a['rev']-$a['cost']));
    echo '<div class="card"><h2>Игры · '.count($per).'</h2><table><tr><th>Игра</th><th>Доход</th><th>Доход, ₽</th><th>Расход</th><th>Профит</th>'.
         '<th>ROI</th><th>Сессии</th><th>Дох/1k сес.</th><th>Дней</th><th>Последний</th></tr>';
    foreach ($per as $k=>$v) {
        $p = $v['rev'] - $v['cost'];
        $e = $v['vis'] > 0 ? $v['rev']/$v['vis']*1000 : 0.0;
        echo '<tr><td>'.h($nameOf[$k] ?? $k).' <span class="dim" style="font-size:11px">'.h($k).'</span></td>'.
             '<td>'.money($v['rev']).'</td><td class="dim">'.number_format($v['rub'],2,',',' ').'</td>'.
             '<td>'.money($v['cost']).'</td><td class="'.cls($p).'">'.money($p).'</td>'.
             '<td>'.roi_s($v['cost'],$v['rev']).'</td><td>'.num($v['vis']).'</td>'.
             '<td>'.number_format($e,4).'</td><td>'.(int)$v['days'].'</td><td class="dim">'.h($v['last']).'</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="note">Тип сервера «game» изолирован от туб и трекеров: в Обзоре, Доменах и Аналитике он не участвует, '.
         'чтобы рублёвые игры не размывали долларовую арбитражную математику. Курс зашит константой RUB_USD в lib.php и '.
         'в money-push-games.php на сервере игр — менять надо в обоих местах. Исходные рубли по каждому дню лежат в meta_json.</div>';
}

/* ===================== 🚜 ФЕРМЫ (игра) ===================== */
/**
 * Игровой слой над теми же цифрами, что и в остальных разделах.
 *
 * Идея простая: сервер — ферма, домен — грядка, доход — урожай, расход — топливо.
 * Ничего не выдумывается: уровень фермы считается по накопленному профиту,
 * «пожары» — это реальные убыточные домены, апгрейды — реальные действия
 * с посчитанной отдачей. Игра тут — форма подачи, а не отдельная реальность.
 */

/** Уровни фермы по накопленному за всё время профиту. */
function farm_level(float $life): array {
    $L = [[0,'Пустошь','🪨'],[1,'Делянка','🌱'],[5,'Огород','🥕'],[15,'Ферма','🚜'],[40,'Хозяйство','🏡'],
          [100,'Плантация','🌻'],[250,'Агрохолдинг','🏭'],[500,'Империя','🏰'],[1000,'Легенда','👑']];
    $i = 0;
    for ($k = 0; $k < count($L); $k++) if ($life >= $L[$k][0]) $i = $k;
    $cur = $L[$i]; $next = $L[min($i+1, count($L)-1)];
    $pct = $next[0] > $cur[0] ? min(100, (int)round(($life-$cur[0])/($next[0]-$cur[0])*100)) : 100;
    return ['i'=>$i+1, 'n'=>count($L), 'name'=>$cur[1], 'ico'=>$cur[2],
            'next'=>$next[1], 'nextAt'=>$next[0], 'pct'=>$pct, 'max'=>$i === count($L)-1];
}

/** Редкость фермы по ROI за период — чисто визуальный ярлык качества. */
function farm_rarity(float $cost, float $rev): array {
    if ($cost <= 0) return ['спящая', 'r0'];
    $roi = $rev / $cost;
    if ($roi < 1.0)  return ['горит',       'r1'];
    if ($roi < 1.5)  return ['обычная',     'r2'];
    if ($roi < 2.5)  return ['редкая',      'r3'];
    if ($roi < 4.0)  return ['эпическая',   'r4'];
    return ['легендарная', 'r5'];
}

function view_farms(int $days): void {
    $db   = db();
    $from = (new DateTime('-'.($days-1).' days'))->format('Y-m-d');

    /* Строки за период по ВСЕМ типам серверов, с той же дедупликацией, что и в
       остальном центре: на пару (день, домен) берём выделенный сервер, а не донора. */
    $raw = q($db, "SELECT dd.d, dd.domain, dd.server_id, dd.cost, dd.revenue, dd.clicks, dd.visits,
                          dd.updated_at, s.is_aggregate
                     FROM daily dd JOIN servers s ON s.id = dd.server_id
                    WHERE dd.d >= ?", [$from]);
    $pick = [];
    foreach ($raw as $r) {
        $k = $r['d'].'|'.$r['domain'];
        $c = $pick[$k] ?? null;
        if ($c === null) { $pick[$k] = $r; continue; }
        $cmp = (int)$r['is_aggregate'] <=> (int)$c['is_aggregate'];
        if ($cmp < 0 || ($cmp === 0 && $r['updated_at'] > $c['updated_at'])) $pick[$k] = $r;
    }

    $srv = []; $dom = []; $srvDay = [];
    foreach ($pick as $r) {
        $sid = (int)$r['server_id']; $c = (float)$r['cost']; $rv = (float)$r['revenue'];
        if (!isset($srv[$sid])) $srv[$sid] = ['cost'=>0.0,'rev'=>0.0,'clicks'=>0,'visits'=>0,'doms'=>[]];
        $srv[$sid]['cost'] += $c; $srv[$sid]['rev'] += $rv;
        $srv[$sid]['clicks'] += (int)$r['clicks']; $srv[$sid]['visits'] += (int)$r['visits'];
        $srv[$sid]['doms'][$r['domain']] = 1;
        $srvDay[$sid][$r['d']] = ($srvDay[$sid][$r['d']] ?? 0) + ($rv - $c);
        if (!isset($dom[$r['domain']])) $dom[$r['domain']] = ['cost'=>0.0,'rev'=>0.0,'clicks'=>0,'visits'=>0,'srv'=>$sid];
        $dom[$r['domain']]['cost'] += $c; $dom[$r['domain']]['rev'] += $rv;
        $dom[$r['domain']]['clicks'] += (int)$r['clicks']; $dom[$r['domain']]['visits'] += (int)$r['visits'];
    }

    $life = [];
    foreach (q($db, "SELECT server_id, ROUND(SUM(revenue)-SUM(cost),4) p FROM daily GROUP BY server_id") as $r)
        $life[(int)$r['server_id']] = (float)$r['p'];

    $servers = q($db, "SELECT id,code,ip,note,kind,network,is_enabled,is_aggregate,last_seen_at FROM servers ORDER BY code");

    /* ---------- глобальные ресурсы ---------- */
    $gc = $gr = 0.0; $gv = $gk = 0;
    foreach ($srv as $a) { $gc += $a['cost']; $gr += $a['rev']; $gv += $a['visits']; $gk += $a['clicks']; }
    $gp = $gr - $gc;
    $lifeAll = array_sum($life);

    /* справочник сеток живёт в CRM основной сетки, не в базе центра */
    try {
        $nets = q($db, "SELECT code,title,status,payout_min,min_daily_traffic,approval_time FROM fapacrm.crm_network_status ORDER BY FIELD(status,'connected','approved','pending','not-signed'), code");
    } catch (Throwable $e) { $nets = []; }
    $netOn = array_values(array_filter($nets, fn($n)=>$n['status']==='connected'));
    $netOff= array_values(array_filter($nets, fn($n)=>$n['status']==='not-signed'));

    /* ---------- локации ---------- */
    $LOC = [
        'tube'    => ['🔞 Адалт-тюбы',   'Основной биом. Покупаем клики в Kadam, продаём показы рекламным сеткам.', 'loc-tube'],
        'tracker' => ['🎯 Трекер-смарт', 'Клик-трекеры и смартлинки. Другая механика: платят за лид, не за показ.', 'loc-trk'],
        'game'    => ['🕹 Игровая долина','Яндекс.Игры. Рубли, приведённые к доллару по курсу '.number_format(RUB_USD,0).'.', 'loc-game'],
    ];

    /* ---------- пожары: убыточные домены ---------- */
    $fires = [];
    foreach ($dom as $d => $v) {
        $p = $v['rev'] - $v['cost'];
        if ($v['cost'] > 0.02 && $p < 0) $fires[$d] = ['loss'=>-$p] + $v;
    }
    uasort($fires, fn($a,$b)=>$b['loss'] <=> $a['loss']);
    $firesBySrv = [];
    foreach ($fires as $d => $v) $firesBySrv[$v['srv']][$d] = $v;

    /* ---------- апгрейды: реальные действия с посчитанной отдачей ---------- */
    $meta = [];
    foreach (q($db, "SELECT domain, modernized, html_donor FROM domain_meta") as $r) $meta[$r['domain']] = $r;
    $regs = [];
    foreach (q($db, "SELECT domain, verdict, reason FROM domain_registrar") as $r) $regs[$r['domain']] = $r;

    $ups = [];   /* [sid] => list of [ico, title, detail, reward, time] */
    foreach ($dom as $d => $v) {
        $sid = $v['srv']; $p = $v['rev'] - $v['cost'];
        $roi = $v['cost'] > 0 ? $v['rev']/$v['cost'] : 0;

        /* грядка плодоносит — долить объёма. Отдача считается по её же кривой. */
        if ($v['cost'] >= 0.05 && $roi >= 2.0)
            $ups[$sid][] = ['⬆️', 'Долить объём: '.$d,
                'ROI '.number_format($roi,2).'x на расходе '.money($v['cost']).'. Каждый доллар возвращает '.number_format($roi,2).'.',
                '+'.money($p/max(1,$days)).' /день за каждое удвоение', '15 мин'];

        /* трафик идёт, денег нет — реклама не отдаётся */
        if ($v['visits'] >= 50 && $v['rev'] <= 0.001)
            $ups[$sid][] = ['🔌', 'Починить монетизацию: '.$d,
                num($v['visits']).' визитов за период и ноль дохода. Так было с maximhot.ru: площадки заведены, а блок в ad_blocks не прописан.',
                'до +'.money($v['visits']/1000*0.15).' /период', '30–60 мин'];

        /* домен потерян у регистратора, а клики за него всё ещё покупаются */
        if (isset($regs[$d]) && $regs[$d]['verdict'] === 'dead' && $v['cost'] > 0)
            $ups[$sid][] = ['🛂', 'Вернуть или отключить: '.$d,
                'Домен мёртв у регистратора ('.mb_substr((string)$regs[$d]['reason'],0,60).'), а расход идёт.',
                '+'.money($v['cost']).' перестанет гореть', '5 мин на стоп кампании'];

        /* шаблонный домен без модернизации */
        if (isset($meta[$d]) && (int)$meta[$d]['modernized'] === 0 && $v['visits'] >= 100 && $p > -0.05)
            $ups[$sid][] = ['🎨', 'Модернизировать: '.$d,
                'Живой трафик ('.num($v['visits']).' виз.), но сайт ещё шаблонный. Рецепт — в RECIPE.md.',
                'x1.5–2 к доходу домена', '2–3 часа'];
    }
    foreach ($ups as $sid => &$list) {
        usort($list, fn($a,$b)=>strcmp($a[0],$b[0]));
        $list = array_slice($list, 0, 6);
    }
    unset($list);

    /* ---------- пайплайны и вердикты ---------- */
    /* средняя отдача сетки на 1000 визитов — по ней считаем недополученное,
       чтобы «потенциал» был измеренным, а не выдуманным */
    $netVis = 0; $netRev = 0.0;
    foreach ($dom as $v) { $netVis += (int)$v['visits']; $netRev += $v['rev']; }
    $netRev1k = $netVis > 0 ? $netRev / $netVis * 1000 : 0.0;

    /* свежий расход по домену: последние двое суток, чтобы «горит» означало сейчас */
    $recent = [];
    foreach (q($db, "SELECT domain, ROUND(SUM(cost)/2,6) c FROM daily WHERE d >= CURDATE()-INTERVAL 1 DAY GROUP BY domain") as $r)
        $recent[$r['domain']] = (float)$r['c'];

    $regMap = []; foreach ($regs as $d => $r) $regMap[$d] = (string)$r['verdict'];
    $metaMap = []; foreach ($meta as $d => $r) $metaMap[$d] = (int)$r['modernized'];

    $domsBySrv = [];
    foreach ($dom as $dn => $dv) $domsBySrv[$dv['srv']][$dn] = $dv;

    $PL = []; $VD = [];
    foreach ($servers as $s0) {
        $sid0 = (int)$s0['id'];
        $a0 = $srv[$sid0] ?? ['cost'=>0.0,'rev'=>0.0,'clicks'=>0,'visits'=>0,'doms'=>[]];
        $md = $domsBySrv[$sid0] ?? [];
        $pk = (int)$s0['is_enabled'] === 0;
        $sl = !$pk && is_stale($s0['last_seen_at']);
        $PL[$sid0] = fm_pipeline($md, $regMap, $metaMap, $a0['cost'], $a0['rev'], $sl, $pk, $netRev1k, $recent);
        $VD[$sid0] = fm_verdict($PL[$sid0], $sl, $pk, $a0['rev'] - $a0['cost'], $days);
    }

    /* ---------- шапка ---------- */
    echo farm_css();
    echo '<div class="fm-hero">';
    echo '<div class="fm-hero-t"><h1>🚜 Фермы</h1><div class="fm-sub">Сервер — ферма, домен — грядка, доход — урожай, расход — топливо. '.
         'Цифры те же, что в Обзоре, просто видно, где растёт, а где горит. Период: '.(int)$days.'д.</div></div>';
    echo '<div class="fm-res">';
    echo fm_res('💰','Золото за период', money($gp), $gp>=0?'good':'bad', 'урожай '.money($gr).' − топливо '.money($gc));
    echo fm_res('🏆','Всего намыто', money($lifeAll), $lifeAll>=0?'good':'bad', 'за всю историю сетки');
    echo fm_res('🌾','Грядок', num(count($dom)), '', num(count(array_filter($dom, fn($v)=>$v['rev']>$v['cost']))).' плодоносят');
    echo fm_res('👣','Посетителей', num($gv), '', num($gk).' кликов посеяно');
    echo fm_res('🔥','Пожаров', num(count($fires)), count($fires)?'bad':'good', count($fires)?'жгут '.money(array_sum(array_column($fires,'loss'))):'ни одного');
    echo fm_res('🔗','Сеток на приёме', num(count($netOn)), '', num(count($netOff)).' ещё не подключено');
    echo '</div></div>';

    /* ---------- штаб: 12 ферм одним взглядом ---------- */
    $ORDER = ['ТУШИТЬ'=>0, 'ЧИНИТЬ'=>1, 'РАСТИТЬ'=>2, 'ДЕРЖАТЬ'=>3, 'ЖДАТЬ'=>4, 'СПИТ'=>5];
    $hq = [];
    foreach ($servers as $s0) {
        $sid0 = (int)$s0['id'];
        $a0 = $srv[$sid0] ?? ['cost'=>0.0,'rev'=>0.0,'doms'=>[]];
        $hq[] = ['s'=>$s0, 'sid'=>$sid0, 'v'=>$VD[$sid0], 'pl'=>$PL[$sid0],
                 'p'=>$a0['rev'] - $a0['cost'], 'doms'=>count($a0['doms'])];
    }
    usort($hq, function($x, $y) use ($ORDER) {
        $c = ($ORDER[$x['v'][0]] ?? 9) <=> ($ORDER[$y['v'][0]] ?? 9);
        return $c !== 0 ? $c : ($y['v'][4] <=> $x['v'][4]);
    });
    $cnt = [];
    foreach ($hq as $x) $cnt[$x['v'][0]] = ($cnt[$x['v'][0]] ?? 0) + 1;
    $priceAll = 0.0;
    foreach ($hq as $x) $priceAll += $x['v'][4];

    echo '<div class="fm-card fm-hq"><div class="fm-hq-h"><h2>🧭 Штаб · что делать прямо сейчас</h2>';
    echo '<div class="fm-hq-sum">';
    foreach (['ТУШИТЬ'=>'f', 'ЧИНИТЬ'=>'w', 'РАСТИТЬ'=>'g', 'ДЕРЖАТЬ'=>'o', 'ЖДАТЬ'=>'o', 'СПИТ'=>'z'] as $k => $c)
        if (!empty($cnt[$k])) echo '<span class="hq-b '.$c.'">'.$k.' '.$cnt[$k].'</span>';
    echo '</div></div>';
    if ($priceAll > 0.0005)
        echo '<div class="fm-hq-price">Бездействие стоит <b>$'.number_format($priceAll, 2).' в сутки</b> — столько сетка теряет или недополучает, пока это не тронуто.</div>';
    echo '<div class="fm-hq-grid">';
    foreach ($hq as $x) {
        [$verd, $cls, $ico, $why, $price] = $x['v'];
        $pl = $x['pl'];
        echo '<div class="hq-t '.$cls.'"'.(($pl['cur'] !== null || $x['pl']['burn']) ? ' data-modal="m'.$x['sid'].'"' : '').'>';
        echo   '<div class="hq-ico">'.$ico.'</div>';
        echo   '<div class="hq-mid"><div class="hq-v">'.$verd.'</div>';
        echo     '<div class="hq-c">'.h((string)$x['s']['code']).'</div>';
        echo     '<div class="hq-w">'.h($why).'</div>';
        echo     '<div class="hq-lv">уровень '.$pl['passed'].' из '.$pl['total'].
                 ($pl['cur'] !== null ? ' · дальше: '.h($pl['levels'][$pl['cur']]['name']) : ' · всё пройдено 👑').'</div>';
        echo   '</div>';
        echo   '<div class="hq-p">'.($price > 0.0005 ? '−$'.number_format($price, 2).'<span>в сутки</span>' : '<span class="ok">—</span>').'</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="fm-note">Порядок плиток — это порядок работы: сперва то, что горит сейчас, потом сломанное, потом здоровое, которое можно растить. '
       . 'Цена бездействия у «тушить» — реальный убыток за сутки, у «чинить» — недополученный доход по средней отдаче сетки ($'
       . number_format($netRev1k, 3).' с 1000 визитов). Клик по плитке — пайплайн фермы.</div></div>';

    /* ---------- квест: +$1 в сутки ---------- */
    $today = (new DateTime())->format('Y-m-d');
    $started = [];
    foreach (q($db, "SELECT domain FROM domain_meta WHERE modernized=1 AND DATE(updated_at)=?", [$today]) as $r)
        $started[] = ['🎨', 'Модернизация отмечена: '.$r['domain']];
    /* только настоящее воскрешение: dead -> ok. Переход unknown -> ok это всего лишь
       ответивший реестр после рейт-лимита, выдавать его за работу нельзя */
    foreach (q($db, "SELECT domain FROM domain_registrar_log WHERE DATE(ts)=? AND new_v='ok' AND old_v='dead'", [$today]) as $r)
        $started[] = ['🛂', 'Домен вернулся в строй: '.$r['domain']];
    foreach (q($db, "SELECT domain FROM (SELECT domain, MIN(d) fd FROM daily GROUP BY domain) x WHERE fd=? AND domain LIKE '%.%'", [$today]) as $r)
        $started[] = ['🌱', 'Новая грядка засеяна: '.$r['domain']];

    /* задания сетки: берём текущий уровень каждой фермы, где он есть */
    $tasks = [];
    foreach ($hq as $x) {
        if ($x['pl']['cur'] === null) continue;
        $l = $x['pl']['levels'][$x['pl']['cur']];
        $tasks[] = ['srv'=>(string)$x['s']['code'], 'sid'=>$x['sid'], 'lvl'=>$l['n'],
                    'name'=>$l['name'], 'why'=>$l['why'], 'act'=>$l['act'],
                    'gain'=>$l['price'] / max(1,$days), 'time'=>$l['time']];
    }
    usort($tasks, fn($a,$b)=>$b['gain'] <=> $a['gain']);
    $goal = 1.0;
    $reach = 0.0;
    foreach (array_slice($tasks, 0, 5) as $t) $reach += $t['gain'];
    $pct = min(100, (int)round($reach / $goal * 100));

    echo '<div class="fm-card fm-quest"><h2>🎯 Сегодня · план на +$1 в сутки</h2>';
    echo '<div class="fm-note" style="margin-top:0">Концепция простая: каждый день делать одно действие, которое поднимает суточный профит на доллар. '
       . 'Ниже — задания, отсортированные по посчитанной отдаче.</div>';
    echo '<div class="barwrap" style="margin-top:10px"><div class="lab"><span>Верхние пять заданий дают</span><span><b>+$'
       . number_format($reach, 2).'</b> / $1.00 в сутки · '.$pct.'%</span></div>'
       . '<div class="bar gold"><i style="width:'.$pct.'%"></i></div></div>';

    if ($started) {
        echo '<div class="fm-sec">Запущено сегодня</div><div class="fm-started">';
        foreach (array_slice($started, 0, 8) as [$i, $t]) echo '<span class="st-i">'.$i.' '.h($t).'</span>';
        echo '</div>';
    } else {
        echo '<div class="fm-sec">Запущено сегодня</div><div class="fm-hint">Ничего. Ни одной новой грядки, ни одной отметки о модернизации, ни одного возвращённого домена. '
           . 'День идёт, а суточный профит не двигается — это и есть тот случай, ради которого затевался счёт «+$1 в сутки».</div>';
    }

    echo '<div class="fm-sec">Задания</div><div class="fm-tasks">';
    foreach (array_slice($tasks, 0, 6) as $t) {
        echo '<div class="fm-task" data-modal="m'.$t['sid'].'">';
        echo   '<div class="ft-lv">ур.'.$t['lvl'].'</div>';
        echo   '<div class="ft-b"><div class="ft-h">'.h($t['name']).' <span class="ft-s">'.h($t['srv']).'</span></div>';
        echo     '<div class="ft-w">'.h($t['why']).'</div>';
        echo     '<div class="ft-a">→ '.h($t['act']).'</div></div>';
        echo   '<div class="ft-f">'.($t['gain'] > 0.0005 ? '<span class="rw">+$'.number_format($t['gain'],2).'/сут</span>' : '<span class="rw n">отдача не считается</span>')
             . '<span class="tm">⏱ '.h($t['time']).'</span></div>';
        echo '</div>';
    }
    echo '</div></div>';

    /* ---------- склад: сетки ---------- */
    echo '<div class="fm-card"><h2>📦 Склад · куда сдаём урожай</h2><div class="fm-nets">';
    foreach ($nets as $n) {
        $st = $n['status'];
        $cls = $st==='connected' ? 'on' : ($st==='not-signed' ? 'off' : 'mid');
        $lbl = ['connected'=>'принимает','approved'=>'одобрено','pending'=>'на модерации','not-signed'=>'не подключено'][$st] ?? $st;
        echo '<div class="fm-net '.$cls.'"><div class="nm">'.h($n['title']).'</div>'.
             '<div class="st">'.$lbl.'</div>'.
             '<div class="dt">'.h((string)($n['payout_min'] ?: 'минималка не указана')).'</div>'.
             ($st==='not-signed' ? '<div class="dt dim">нужно: '.h((string)($n['min_daily_traffic'] ?: '—')).' · '.h((string)($n['approval_time'] ?: '?')).'</div>' : '').
             '</div>';
    }
    echo '</div><div class="fm-note">Подключённая сетка — открытый канал сбыта. Неподключённая — запертый склад: урожай есть, сдавать некуда. '.
         'Разные типы платят за разное, поэтому один домен может кормиться сразу с нескольких.</div></div>';

    /* ---------- локации ---------- */
    fm_production($srv, $dom, $servers, $days);

    $sparks = []; $modals = [];
    foreach ($LOC as $kind => [$title, $desc, $lcls]) {
        $list = array_values(array_filter($servers, fn($s)=>(string)$s['kind'] === $kind));
        if (!$list) continue;
        $lc = $lr = 0.0;
        foreach ($list as $s) { $a = $srv[(int)$s['id']] ?? null; if ($a) { $lc += $a['cost']; $lr += $a['rev']; } }
        $lp = $lr - $lc;

        echo '<div class="fm-loc '.$lcls.'">';
        echo '<div class="fm-loc-h"><div><h2>'.h($title).'</h2><div class="fm-loc-d">'.h($desc).'</div></div>'.
             '<div class="fm-loc-p '.($lp>=0?'good':'bad').'">'.money($lp).'<span>профит локации</span></div></div>';
        echo '<div class="fm-grid">';

        usort($list, function($a,$b) use ($srv) {
            $pa = ($srv[(int)$a['id']]['rev'] ?? 0) - ($srv[(int)$a['id']]['cost'] ?? 0);
            $pb = ($srv[(int)$b['id']]['rev'] ?? 0) - ($srv[(int)$b['id']]['cost'] ?? 0);
            return $pb <=> $pa;
        });

        foreach ($list as $s) {
            $sid  = (int)$s['id'];
            $a    = $srv[$sid] ?? ['cost'=>0.0,'rev'=>0.0,'clicks'=>0,'visits'=>0,'doms'=>[]];
            $p    = $a['rev'] - $a['cost'];
            $lv   = farm_level($life[$sid] ?? 0.0);
            [$rname, $rcls] = farm_rarity($a['cost'], $a['rev']);
            $parked = (int)$s['is_enabled'] === 0;
            $silent = !$parked && is_stale($s['last_seen_at']);
            $burning = $p < 0 && $a['cost'] > 0.02;
            $myFires = $firesBySrv[$sid] ?? [];
            $myUps   = $ups[$sid] ?? [];

            $state = $parked ? 'parked' : ($silent ? 'silent' : ($burning ? 'burning' : ($p > 0 ? 'growing' : 'idle')));
            $ser = $srvDay[$sid] ?? []; ksort($ser);
            $sparks['fs'.$sid] = array_values(array_map(fn($x)=>round($x,4), $ser));

            /* грядки этой фермы — каждый домен отдельной плиткой */
            $myDoms = [];
            foreach ($dom as $dn => $dv) if ($dv['srv'] === $sid) $myDoms[$dn] = $dv;
            uasort($myDoms, fn($x,$y)=>($y['rev']-$y['cost']) <=> ($x['rev']-$x['cost']));


            echo '<div class="fm-farm '.$state.' '.$rcls.'" data-modal="m'.$sid.'"'.
                 ' data-rate="'.number_format($p / max(1,$days) / 86400, 12, '.', '').'">';
            echo   fm_sprite($state, $lv, count($myDoms), $p);
            echo   '<div class="fm-body">';
            echo     '<div class="fm-top"><span class="fm-lv">'.$lv['ico'].' ур.'.$lv['i'].' · '.h($lv['name']).'</span>'.
                     '<span class="fm-rar '.$rcls.'">'.$rname.'</span></div>';
            echo     '<div class="fm-name">'.h((string)$s['code']).'<span class="fm-ip">'.h((string)$s['ip']).'</span></div>';
            echo     '<div class="fm-bar"><i style="width:'.$lv['pct'].'%"></i></div>';
            echo     '<div class="fm-lvsub">'.($lv['max'] ? 'максимальный уровень 👑'
                        : 'до «'.h($lv['next']).'» ещё '.money(max(0,$lv['nextAt']-($life[$sid]??0)))).'</div>';

            /* поле фермы: одна плитка — один домен, цвет по его профиту */
            if ($myDoms) {
                echo '<div class="fm-plots" title="каждая клетка — домен, наведи">';
                foreach ($myDoms as $dn => $dv) {
                    $pp = $dv['rev'] - $dv['cost'];
                    $cl = $pp > 0.02 ? 'g3' : ($pp > 0.002 ? 'g2' : ($pp > -0.001 ? 'g1' : ($pp > -0.05 ? 'r1' : 'r2')));
                    echo '<i class="'.$cl.'" data-dom="'.h($dn).'"'.
                         ' data-r="'.money($dv['rev']).'" data-c="'.money($dv['cost']).'"'.
                         ' data-p="'.($pp>=0?'+':'').money($pp).'" data-v="'.num((int)$dv['visits']).'"></i>';
                }
                echo '</div>';
            }

            echo     '<div class="fm-stats">';
            echo       '<span title="урожай">🌾 '.money($a['rev']).'</span>';
            echo       '<span title="топливо">⛽ '.money($a['cost']).'</span>';
            echo       '<span class="'.($p>=0?'good':'bad').'" title="профит">'.($p>=0?'📈':'📉').' '.money($p).'</span>';
            echo     '</div>';
            echo     '<div class="fm-stats dim">';
            echo       '<span>'.($kind==='game'?'🕹 игр ':'🌱 грядок ').count($a['doms']).'</span>';
            echo       '<span>👣 '.num($a['visits']).'</span>';
            echo       '<span>ROI '.roi_s($a['cost'],$a['rev']).'</span>';
            echo     '</div>';

            /* счётчик капает реальным темпом этой фермы, а не по таймеру */
            if (!$parked && abs($p) > 0.0001)
                echo '<div class="fm-tick '.($p>=0?'good':'bad').'"><span class="c">'.($p>=0?'🪙':'🔥').'</span>'.
                     '<b data-tick="'.$sid.'">0.000000</b>'.
                     '<span class="u">'.($p>=0?'намыто с открытия':'сожжено с открытия').'</span></div>';

            if (!$parked) echo '<div class="fm-spark"><canvas id="fs'.$sid.'"></canvas></div>';

            if ($burning && $myFires) {
                $loss = array_sum(array_column($myFires, 'loss'));
                echo '<button class="fm-btn fire" data-modal="m'.$sid.'">🔥 Горит '.count($myFires).' '.
                     plural(count($myFires),'грядка','грядки','грядок').' · −'.money($loss).'</button>';
            } elseif ($myUps) {
                echo '<button class="fm-btn up" data-modal="m'.$sid.'">⬆️ Апгрейды · '.count($myUps).'</button>';
            } elseif ($parked) {
                echo '<div class="fm-idle">ферма законсервирована</div>';
            } elseif ($silent) {
                echo '<div class="fm-idle">⚠ не выходит на связь</div>';
            } else {
                echo '<div class="fm-idle">всё ровно, апгрейдов не нашлось</div>';
            }
            echo   '</div></div>';
        }
        echo '</div></div>';
    }

    /* ---------- модалки: пайплайн фермы + её пожары и апгрейды ---------- */
    foreach ($servers as $s0) {
        $sid0 = (int)$s0['id'];
        $pl = $PL[$sid0]; $vd = $VD[$sid0];
        $f = $firesBySrv[$sid0] ?? []; $u = $ups[$sid0] ?? [];
        echo '<div class="fm-modal" id="m'.$sid0.'"><div class="fm-modal-in">';
        echo '<div class="fm-modal-h"><b>'.$vd[2].' '.h((string)$s0['code']).' — '.$vd[0].'</b>'
           . '<button class="fm-x" data-close="m'.$sid0.'">✕</button></div>';
        echo '<div class="fm-hint" style="margin-top:0">'.h($vd[3]).
             ($vd[4] > 0.0005 ? ' · бездействие стоит <b>$'.number_format($vd[4],2).' в сутки</b>' : '').'</div>';

        echo '<div class="fm-sec">Пайплайн фермы · уровень '.$pl['passed'].' из '.$pl['total'].'</div>';
        foreach ($pl['levels'] as $l) {
            $ic = $l['st'] === 'done' ? '✅' : ($l['st'] === 'now' ? '▶️' : '🔒');
            echo '<div class="fm-lvl '.$l['st'].'">';
            echo   '<div class="fl-n">'.$ic.' <b>'.$l['n'].'. '.h($l['name']).'</b></div>';
            echo   '<div class="fl-c">'.h($l['crit']).'</div>';
            if ($l['st'] !== 'done') {
                echo '<div class="fl-w">'.h($l['why']).'</div>';
                echo '<div class="fl-a">→ '.h($l['act']).'</div>';
                echo '<div class="fl-f">'.($l['price'] > 0.0005
                        ? '<span class="rw">вернёт +$'.number_format($l['price'] / max(1,$days),2).'/сут</span>' : '')
                   . '<span class="tm">⏱ '.h($l['time']).'</span></div>';
            }
            echo '</div>';
        }

        if ($f) {
            echo '<div class="fm-sec">🔥 Горящие грядки</div><table class="fm-tab"><tr><th>Грядка</th><th>Топливо</th><th>Урожай</th><th>Убыток</th></tr>';
            foreach ($f as $d => $v)
                echo '<tr><td>'.h($d).'</td><td>'.money($v['cost']).'</td><td>'.money($v['rev']).'</td><td class="bad">−'.money($v['loss']).'</td></tr>';
            echo '</table><div class="fm-hint">Прежде чем резать — посмотри по дням: бывает, весь минус недели дал один перезалив, и лечится он лимитом объёма, а не ставкой.</div>';
        }
        if ($u) {
            echo '<div class="fm-sec">⬆️ Что ещё можно улучшить</div>';
            foreach ($u as [$ico, $t, $dt, $rw, $tm])
                echo '<div class="fm-up"><div class="fm-up-h">'.$ico.' <b>'.h($t).'</b></div>'
                   . '<div class="fm-up-d">'.h($dt).'</div>'
                   . '<div class="fm-up-f"><span class="rw">🎁 '.h($rw).'</span><span class="tm">⏱ '.h($tm).'</span></div></div>';
        }
        echo '</div></div>';
    }

    /* ---------- дневной квест ---------- */
    $worst = array_slice($fires, 0, 3, true);
    echo '<div class="fm-card fm-quest"><h2>🎯 Квест дня</h2>';
    if ($worst) {
        echo '<div class="fm-note" style="margin-top:0">Потушить три самые горящие грядки — сетка сразу перестанет терять '.
             money(array_sum(array_column($worst,'loss'))).' за период:</div><ol class="fm-ol">';
        foreach ($worst as $d => $v)
            echo '<li><b>'.h($d).'</b> — жжёт '.money($v['loss']).' (топливо '.money($v['cost']).', урожай '.money($v['rev']).')</li>';
        echo '</ol>';
    } else {
        echo '<div class="fm-note" style="margin-top:0">Пожаров нет. Тогда квест другой: взять сетку со склада, которая ещё не подключена — это новый канал сбыта для всего урожая сразу.</div>';
    }
    echo '</div>';

    echo '<div class="fm-note fm-foot">Уровень фермы — накопленный профит за всю историю сервера. Редкость — ROI за выбранный период. '.
         'Пожар — домен, который за период потратил больше, чем принёс. Апгрейды не выдуманы: каждый собран из реальных '.
         'данных (ROI грядки, визиты без дохода, статус у регистратора, отметка о модернизации), и отдача посчитана по ним же. '.
         'Картинки нарисованы в SVG прямо на странице — ключа Grok на серверах нет, появится — можно будет генерить спрайты ферм.</div>';

    echo '<script>const FS='.json_encode($sparks).';for(const id in FS){const c=document.getElementById(id);if(!c||!FS[id].length)continue;'.
        'new Chart(c,{type:"line",data:{labels:FS[id].map((_,i)=>i),datasets:[{data:FS[id],borderColor:"#16a34a",borderWidth:1.5,pointRadius:0,tension:.4,fill:false}]},'.
        'options:{plugins:{legend:{display:false},tooltip:{enabled:false}},scales:{x:{display:false},y:{display:false}},maintainAspectRatio:false}});}';
    /* вся карточка кликабельна, не только кнопка — ферма ведёт себя как объект */
    echo 'document.querySelectorAll("[data-modal]").forEach(b=>b.addEventListener("click",e=>{'
       . 'if(e.target.closest(".fm-plots"))return;e.stopPropagation();'
       . 'const m=document.getElementById(b.dataset.modal);if(m)m.classList.add("open")}));';
    /* счётчики ферм: капают реальным темпом каждой, а не по общему таймеру */
    echo 'const FM_TICK=[...document.querySelectorAll("[data-rate]")].map(c=>({'
       . 'r:parseFloat(c.dataset.rate)||0,e:c.querySelector("[data-tick]")})).filter(x=>x.e);'
       . 'const FM_T0=performance.now();'
       . '(function fmTick(){const s=(performance.now()-FM_T0)/1000;'
       . 'FM_TICK.forEach(x=>{x.e.textContent=Math.abs(x.r*s).toFixed(6);});'
       . 'requestAnimationFrame(fmTick);})();';
    echo 'document.querySelectorAll("[data-close]").forEach(b=>b.addEventListener("click",()=>{document.getElementById(b.dataset.close).classList.remove("open")}));';
    echo 'document.querySelectorAll(".fm-modal").forEach(m=>m.addEventListener("click",e=>{if(e.target===m)m.classList.remove("open")}));';
    echo 'document.addEventListener("keydown",e=>{if(e.key==="Escape")document.querySelectorAll(".fm-modal.open").forEach(m=>m.classList.remove("open"))});';
    /* грядка — самостоятельный объект: своя подсказка и переход в разбор домена */
    echo 'const FM_TIP=document.createElement("div");FM_TIP.className="fm-tip";document.body.appendChild(FM_TIP);';
    echo 'document.querySelectorAll(".fm-plots i[data-dom]").forEach(t=>{'
       . 't.addEventListener("mouseenter",()=>{FM_TIP.innerHTML="<b>"+t.dataset.dom+"</b>"'
       . '+"<span>🌾 урожай "+t.dataset.r+"</span><span>⛽ топливо "+t.dataset.c+"</span>"'
       . '+"<span>итог "+t.dataset.p+" · 👣 "+t.dataset.v+"</span><span class=go>клик — разбор по дням →</span>";'
       . 'FM_TIP.classList.add("on");});'
       . 't.addEventListener("mousemove",e=>{const w=FM_TIP.offsetWidth;'
       . 'FM_TIP.style.left=Math.min(e.clientX+14,innerWidth-w-10)+"px";FM_TIP.style.top=(e.clientY+16)+"px";});'
       . 't.addEventListener("mouseleave",()=>FM_TIP.classList.remove("on"));'
       . 't.addEventListener("click",e=>{e.stopPropagation();'
       . 'location.href="?view=domains&dom="+encodeURIComponent(t.dataset.dom)+"&days="+'.(int)$days.';});'
       . '});';
    echo '</script>';
}

function fm_res(string $ico, string $label, string $val, string $cls, string $sub): string {
    return '<div class="fm-r"><div class="ic">'.$ico.'</div><div class="tx"><div class="l">'.h($label).'</div>'.
           '<div class="v '.$cls.'">'.$val.'</div><div class="s">'.h($sub).'</div></div></div>';
}

/**
 * Сцена фермы. Картинка выбирается по состоянию и уровню: горит — горящая,
 * спит — спящая, растёт — по тиру. Спрайты нарисованы Grok-ом и лежат в /art,
 * поэтому сцена ничего не запрашивает наружу и не стоит денег при показе.
 */
function fm_sprite(string $state, array $lv, int $plots, float $profit): string {
    $tier = $lv['i'] <= 2 ? 1 : ($lv['i'] <= 4 ? 2 : ($lv['i'] <= 6 ? 3 : ($lv['i'] <= 7 ? 4 : 5)));
    $img  = ($state === 'burning') ? 'burn'
          : (($state === 'silent' || $state === 'parked') ? 'idle' : 'tier'.$tier);

    $s  = '<div class="fm-scene s-'.$state.'">';
    $s .= '<div class="fm-sky"></div>';
    if ($state === 'growing') $s .= '<div class="fm-sun"></div>';
    $s .= '<img class="fm-isl" src="art/'.$img.'.png" alt="" loading="lazy">';

    /* частиц тем больше, чем больше ферма реально зарабатывает или жжёт */
    $n = max(2, min(6, (int)round(abs($profit) * 1.4) + 2));
    if ($state === 'growing') {
        for ($i = 0; $i < $n; $i++)
            $s .= '<span class="fm-coin2" style="left:'.(14 + $i * (72 / max(1,$n))).'%;'.
                  'animation-delay:'.number_format($i * (2.6 / $n), 2).'s"></span>';
    } elseif ($state === 'burning') {
        for ($i = 0; $i < $n; $i++)
            $s .= '<span class="fm-puff" style="left:'.(26 + $i * (46 / max(1,$n))).'%;'.
                  'animation-delay:'.number_format($i * (2.2 / $n), 2).'s"></span>';
    }
    $s .= '<div class="fm-plotcount">'.$plots.'</div>';
    $s .= '</div>';
    return $s;
}

function farm_css(): string { return <<<CSS
<style>
.fm-hero{background:linear-gradient(135deg,#0f2f1d,#1c5133 55%,#2b7a4b);color:#eafff2;border-radius:18px;padding:18px 20px;margin-bottom:16px;box-shadow:0 8px 24px rgba(15,47,29,.25)}
.fm-hero h1{margin:0;color:#fff;font-size:26px;letter-spacing:-.02em}
.fm-hero .fm-sub{color:#b9e8cd;font-size:13px;margin-top:4px;max-width:900px}
.fm-res{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:14px}
.fm-r{display:flex;gap:10px;align-items:center;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:12px;padding:9px 11px}
.fm-r .ic{font-size:24px;line-height:1}
.fm-r .l{font-size:11px;color:#a9dcc0;text-transform:uppercase;letter-spacing:.05em}
.fm-r .v{font-size:19px;font-weight:800;letter-spacing:-.02em}
.fm-r .v.good{color:#7cf0a8}.fm-r .v.bad{color:#ff9b8a}
.fm-r .s{font-size:11px;color:#9ccfb4}
.fm-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:16px;box-shadow:var(--sh)}
.fm-card h2{margin:0 0 10px;font-size:16px}
.fm-nets{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:9px}
.fm-net{border:1px solid var(--line);border-radius:11px;padding:9px 11px;background:var(--card2)}
.fm-net.on{border-color:#16a34a;background:linear-gradient(135deg,#eafff3,#fff)}
.fm-net.mid{border-color:#d97706;background:linear-gradient(135deg,#fff6e8,#fff)}
.fm-net.off{opacity:.62}
.fm-net .nm{font-weight:750}
.fm-net .st{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em}
.fm-net.on .st{color:#16a34a}
.fm-net .dt{font-size:11.5px;margin-top:3px}
.fm-net .dt.dim{color:var(--dim)}
.fm-loc{border-radius:18px;padding:14px;margin-bottom:16px;border:1px solid var(--line)}
.loc-tube{background:linear-gradient(160deg,#fdf1f6,#fbfdff)}
.loc-trk{background:linear-gradient(160deg,#eef4ff,#fbfdff)}
.loc-game{background:linear-gradient(160deg,#f3eeff,#fbfdff)}
.fm-loc-h{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.fm-loc-h h2{margin:0;font-size:19px;letter-spacing:-.02em}
.fm-loc-d{color:var(--dim);font-size:12.5px;max-width:640px}
.fm-loc-p{font-size:22px;font-weight:800;text-align:right;line-height:1.1}
.fm-loc-p span{display:block;font-size:10.5px;font-weight:600;color:var(--dim);text-transform:uppercase;letter-spacing:.05em}
.fm-loc-p.good{color:var(--pos)}.fm-loc-p.bad{color:var(--neg)}
.fm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(248px,1fr));gap:12px}
.fm-farm{background:#fff;border:2px solid var(--line);border-radius:15px;overflow:hidden;box-shadow:var(--sh);transition:transform .15s,box-shadow .15s}
.fm-farm:hover{transform:translateY(-3px);box-shadow:var(--sh-lg)}
.fm-farm.r5{border-color:#f59e0b;box-shadow:0 0 0 3px #f59e0b22,var(--sh)}
.fm-farm.r4{border-color:#7c3aed}
.fm-farm.r3{border-color:#2563eb}
.fm-farm.r1{border-color:#dc2626}
.fm-farm.parked{opacity:.6}
.fm-scene{position:relative;height:126px;overflow:hidden;border-bottom:1px solid rgba(0,0,0,.06)}
.fm-sky{position:absolute;inset:0;background:linear-gradient(180deg,#bfe3ff,#eaf6ff)}
.s-growing .fm-sky{background:linear-gradient(180deg,#9fd4ff,#ffeccb)}
.s-burning .fm-sky{background:linear-gradient(180deg,#7c4a3a,#f0b48c)}
.s-silent  .fm-sky{background:linear-gradient(180deg,#28364a,#6c7f96)}
.s-parked  .fm-sky{background:linear-gradient(180deg,#3a3f47,#8e949c)}
.s-idle    .fm-sky{background:linear-gradient(180deg,#a8c3d6,#e4eef5)}
.fm-sun{position:absolute;right:16px;top:13px;width:26px;height:26px;border-radius:50%;
  background:radial-gradient(circle,#fff3b0,#ffd84d 62%,rgba(255,216,77,0) 72%);animation:fmSun 5s ease-in-out infinite}
@keyframes fmSun{0%,100%{transform:scale(1);opacity:.92}50%{transform:scale(1.12);opacity:1}}
.fm-isl{position:absolute;left:50%;top:50%;width:132%;transform:translate(-50%,-50%);
  filter:drop-shadow(0 8px 14px rgba(0,0,0,.28));animation:fmBob 4.6s ease-in-out infinite;pointer-events:none}
@keyframes fmBob{0%,100%{transform:translate(-50%,-52%)}50%{transform:translate(-50%,-46%)}}
.s-burning .fm-isl{animation:fmShake .34s linear infinite,fmBob 4.6s ease-in-out infinite}
@keyframes fmShake{0%,100%{margin-left:0}25%{margin-left:-1.2px}75%{margin-left:1.2px}}
.s-silent .fm-isl,.s-parked .fm-isl{filter:grayscale(.75) brightness(.8) drop-shadow(0 6px 12px rgba(0,0,0,.3))}
.fm-farm:hover .fm-isl{width:140%;transition:width .35s ease}
.fm-coin2{position:absolute;bottom:26px;width:11px;height:11px;border-radius:50%;
  background:radial-gradient(circle at 34% 32%,#fff6c8,#ffd84d 58%,#e0a800);
  box-shadow:0 0 7px rgba(255,216,77,.85);animation:fmRise 2.6s linear infinite;pointer-events:none}
@keyframes fmRise{0%{transform:translateY(0) scale(.55);opacity:0}
  18%{opacity:1}70%{opacity:1}100%{transform:translateY(-84px) scale(1.05);opacity:0}}
.fm-puff{position:absolute;bottom:44px;width:20px;height:20px;border-radius:50%;
  background:radial-gradient(circle,rgba(120,120,120,.75),rgba(120,120,120,0) 70%);
  animation:fmPuff 2.2s linear infinite;pointer-events:none}
@keyframes fmPuff{0%{transform:translateY(0) scale(.4);opacity:0}
  20%{opacity:.85}100%{transform:translateY(-72px) scale(2.1);opacity:0}}
.fm-plotcount{position:absolute;left:9px;bottom:8px;background:rgba(6,20,30,.5);color:#fff;
  border-radius:8px;padding:1px 8px;font-size:11px;font-weight:700;backdrop-filter:blur(4px)}
.fm-plots{display:flex;flex-wrap:wrap;gap:2.5px;margin:7px 0 8px}
.fm-plots i{width:10px;height:10px;border-radius:2.5px;cursor:help;transition:transform .12s}
.fm-plots i:hover{transform:scale(1.75)}
.fm-plots i.g3{background:#16a34a}.fm-plots i.g2{background:#7ed99a}
.fm-plots i.g1{background:#cfd8e3}.fm-plots i.r1{background:#f0a08c}.fm-plots i.r2{background:#dc2626}
.fm-tick{display:flex;align-items:baseline;gap:6px;font-size:12px;margin:2px 0 4px;
  background:var(--card2);border:1px solid var(--line);border-radius:9px;padding:4px 8px}
.fm-tick b{font-variant-numeric:tabular-nums;font-size:13px;font-weight:800}
.fm-tick.good b{color:var(--pos)}.fm-tick.bad b{color:var(--neg)}
.fm-tick .u{color:var(--dim);font-size:10.5px;margin-left:auto}
.fm-farm[data-modal]{cursor:pointer}
.fm-hq{border-color:#cbd5e1}
.fm-hq-h{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.fm-hq-h h2{margin:0}
.fm-hq-sum{display:flex;gap:6px;flex-wrap:wrap}
.hq-b{font-size:11px;font-weight:800;letter-spacing:.04em;border-radius:999px;padding:3px 11px;border:1px solid}
.hq-b.f{background:#fdecec;border-color:#f0a8a8;color:#b91c1c}
.hq-b.w{background:#fff4e5;border-color:#f0cf9a;color:#9a5b00}
.hq-b.g{background:#e8f0ff;border-color:#bcd0ff;color:#1d4ed8}
.hq-b.o{background:#eafff3;border-color:#b6ecc8;color:#166534}
.hq-b.z{background:#f1f5f9;border-color:#cbd5e1;color:#64748b}
.fm-hq-price{background:#fdecec;border:1px solid #f3c7c7;border-radius:10px;padding:8px 12px;font-size:13px;color:#7a2e2e;margin-bottom:10px}
.fm-hq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(292px,1fr));gap:8px}
.hq-t{display:flex;gap:10px;align-items:center;border:1px solid var(--line);border-left-width:4px;border-radius:11px;
  padding:9px 11px;background:var(--card2);transition:transform .12s,box-shadow .12s}
.hq-t[data-modal]{cursor:pointer}
.hq-t[data-modal]:hover{transform:translateX(2px);box-shadow:var(--sh)}
.hq-t.f{border-left-color:#dc2626;background:linear-gradient(90deg,#fdecec,#fff 55%)}
.hq-t.w{border-left-color:#d97706;background:linear-gradient(90deg,#fff4e5,#fff 55%)}
.hq-t.g{border-left-color:#2563eb;background:linear-gradient(90deg,#eef3ff,#fff 55%)}
.hq-t.o{border-left-color:#16a34a}
.hq-t.z{border-left-color:#94a3b8;opacity:.62}
.hq-ico{font-size:24px;line-height:1}
.hq-mid{flex:1;min-width:0}
.hq-v{font-size:11px;font-weight:800;letter-spacing:.07em}
.hq-t.f .hq-v{color:#b91c1c}.hq-t.w .hq-v{color:#9a5b00}.hq-t.g .hq-v{color:#1d4ed8}.hq-t.o .hq-v{color:#166534}.hq-t.z .hq-v{color:#64748b}
.hq-c{font-weight:800;font-size:14px;margin:1px 0}
.hq-w{font-size:11.5px;color:var(--dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hq-lv{font-size:10.5px;color:var(--acc);margin-top:2px}
.hq-p{text-align:right;font-size:15px;font-weight:800;color:var(--neg);white-space:nowrap}
.hq-p span{display:block;font-size:9.5px;font-weight:600;color:var(--dim);text-transform:uppercase;letter-spacing:.04em}
.hq-p .ok{font-size:15px;color:var(--dim)}
.fm-started{display:flex;flex-wrap:wrap;gap:6px}
.st-i{font-size:12px;background:#eafff3;border:1px solid #b6ecc8;color:#166534;border-radius:9px;padding:3px 10px}
.fm-tasks{display:grid;gap:8px}
.fm-task{display:flex;gap:11px;align-items:flex-start;border:1px solid var(--line);border-radius:12px;padding:10px 12px;
  background:#fff;cursor:pointer;transition:transform .12s,box-shadow .12s}
.fm-task:hover{transform:translateY(-2px);box-shadow:var(--sh)}
.ft-lv{background:linear-gradient(135deg,#2563eb,#16a34a);color:#fff;font-size:11px;font-weight:800;
  border-radius:9px;padding:5px 9px;white-space:nowrap}
.ft-b{flex:1;min-width:0}
.ft-h{font-size:14px;font-weight:750}
.ft-s{font-size:11.5px;color:var(--dim);font-weight:500}
.ft-w{font-size:12px;color:var(--dim);margin-top:1px}
.ft-a{font-size:12px;margin-top:4px}
.ft-f{display:flex;flex-direction:column;gap:4px;align-items:flex-end;white-space:nowrap}
.ft-f .rw{background:#eafff3;border:1px solid #b6ecc8;color:#166534;border-radius:8px;padding:2px 9px;font-size:11.5px;font-weight:800}
.ft-f .rw.n{background:var(--card2);border-color:var(--line);color:var(--dim);font-weight:500}
.ft-f .tm{font-size:11px;color:var(--dim)}
.fm-lvl{border:1px solid var(--line);border-radius:11px;padding:9px 11px;margin-bottom:6px;background:var(--card2)}
.fm-lvl.done{opacity:.55}
.fm-lvl.now{border-color:#2563eb;background:linear-gradient(135deg,#eef3ff,#fff);box-shadow:0 0 0 2px #2563eb22}
.fm-lvl.lock{opacity:.42}
.fl-n{font-size:13.5px}
.fl-c{font-size:11.5px;color:var(--dim)}
.fl-w{font-size:12.5px;margin-top:5px;color:#9a3412}
.fl-a{font-size:12.5px;margin-top:3px}
.fl-f{display:flex;gap:7px;flex-wrap:wrap;margin-top:6px}
.fl-f .rw{background:#eafff3;border:1px solid #b6ecc8;color:#166534;border-radius:8px;padding:2px 9px;font-size:11.5px;font-weight:800}
.fl-f .tm{background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:8px;padding:2px 9px;font-size:11.5px}
.fm-plots i{cursor:pointer}
.fm-tip{position:fixed;z-index:70;pointer-events:none;opacity:0;transition:opacity .12s;
  background:rgba(12,24,36,.94);color:#eaf6ff;border:1px solid rgba(255,255,255,.16);border-radius:10px;
  padding:8px 11px;font-size:12px;line-height:1.5;box-shadow:0 8px 24px rgba(0,0,0,.3);max-width:260px}
.fm-tip.on{opacity:1}
.fm-tip b{display:block;font-size:13px;margin-bottom:3px}
.fm-tip span{display:block;color:#b9d3e6}
.fm-tip .go{color:#7cf0a8;margin-top:4px;font-size:11px}
.fm-body{padding:10px 11px 11px}
.fm-top{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:11px}
.fm-lv{font-weight:750;color:var(--acc)}
.fm-rar{font-size:10px;text-transform:uppercase;letter-spacing:.05em;padding:1px 7px;border-radius:9px;border:1px solid var(--line);color:var(--dim)}
.fm-rar.r5{background:#fff4d6;border-color:#f59e0b;color:#8a6a00}
.fm-rar.r4{background:#f2ebff;border-color:#7c3aed;color:#5b21b6}
.fm-rar.r3{background:#e8f0ff;border-color:#2563eb;color:#1d4ed8}
.fm-rar.r1{background:#fdecec;border-color:#dc2626;color:#b91c1c}
.fm-name{font-weight:800;font-size:15px;margin:3px 0 5px;display:flex;justify-content:space-between;align-items:baseline;gap:6px}
.fm-ip{font-weight:500;font-size:10.5px;color:var(--dim)}
.fm-bar{height:7px;border-radius:5px;background:#e8edf3;overflow:hidden}
.fm-bar>i{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,#2563eb,#3ddc84)}
.fm-lvsub{font-size:10.5px;color:var(--dim);margin:3px 0 7px}
.fm-stats{display:flex;justify-content:space-between;gap:6px;font-size:12px;font-weight:650;margin-bottom:3px}
.fm-stats.dim{font-size:11px;font-weight:500;color:var(--dim)}
.fm-stats .good{color:var(--pos)}.fm-stats .bad{color:var(--neg)}
.fm-spark{height:30px;margin:5px 0 7px}
.fm-btn{width:100%;border:0;border-radius:9px;padding:7px;font-size:12px;font-weight:750;cursor:pointer;color:#fff}
.fm-btn.fire{background:linear-gradient(135deg,#dc2626,#f97316);animation:fmPulse 1.8s ease-in-out infinite}
.fm-btn.up{background:linear-gradient(135deg,#2563eb,#16a34a)}
@keyframes fmPulse{0%,100%{box-shadow:0 0 0 0 #dc262666}50%{box-shadow:0 0 0 7px #dc262600}}
.fm-idle{font-size:11px;color:var(--dim);text-align:center;padding:6px 0 1px}
.fm-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:50;padding:20px;overflow:auto}
.fm-modal.open{display:flex;align-items:flex-start;justify-content:center}
.fm-modal-in{background:#fff;border-radius:16px;max-width:640px;width:100%;padding:16px;box-shadow:0 20px 50px rgba(0,0,0,.3)}
.fm-modal-h{display:flex;justify-content:space-between;align-items:center;font-size:16px;margin-bottom:10px}
.fm-x{border:0;background:#eef2f7;border-radius:8px;width:28px;height:28px;cursor:pointer;font-size:14px}
.fm-sec{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);margin:12px 0 6px}
.fm-tab{width:100%;border-collapse:collapse;font-size:12.5px}
.fm-tab th,.fm-tab td{text-align:left;padding:5px 7px;border-bottom:1px solid var(--line)}
.fm-tab th{color:var(--dim);font-weight:600;font-size:11px}
.fm-tab .bad{color:var(--neg);font-weight:700}
.fm-up{border:1px solid var(--line);border-radius:11px;padding:9px 11px;margin-bottom:7px;background:var(--card2)}
.fm-up-h{font-size:13.5px}
.fm-up-d{font-size:12px;color:var(--dim);margin:3px 0 6px}
.fm-up-f{display:flex;gap:8px;flex-wrap:wrap;font-size:11.5px}
.fm-up-f .rw{background:#eafff3;border:1px solid #b6ecc8;color:#166534;border-radius:8px;padding:2px 8px;font-weight:700}
.fm-up-f .tm{background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:8px;padding:2px 8px}
.fm-hint{font-size:12px;color:var(--dim);background:var(--card2);border-radius:9px;padding:9px 11px;margin-top:9px}
.fm-note{font-size:12px;color:var(--dim);margin-top:9px;line-height:1.5}
.fm-foot{margin-top:14px}
.fm-quest{background:linear-gradient(135deg,#fff6e5,#fffdf6);border-color:#f0d9a8}
.fm-ol{margin:8px 0 0;padding-left:20px;font-size:13px;line-height:1.7}
</style>
CSS; }

/* ---------- Конвейер: рост как производство, а не как кнопка ---------- */
/**
 * Здесь единственная незанудная мысль всего раздела: доход не «включается»,
 * а производится, и у каждой линии свой такт. Домен начинает платить через
 * дни, игра — через две недели модерации, и никакое усердие этот такт не
 * сжимает. Поэтому считаем не «сколько хочется», а «сколько успеет выйти».
 *
 * Все ставки ниже — из реальных данных центра. Настройки, которые данными не
 * подтверждаются (цена домена, срок модерации, размер очереди игр), вынесены
 * в константы и подписаны как допущения — чтобы их было видно и можно было
 * поправить, а не найти зашитыми в формуле.
 */
function fm_cfg(): array {
    return [
        'goal'      => 100.0,  // цель по профиту в день
        'domPrice'  => 1.97,   // 169 ₽ по факту закупки в reg.ru
        'domLead'   => 3,      // дней от покупки до первых денег
        'domRamp'   => 14,     // дней до выхода домена на свою полку
        'gameMod'   => 14,     // модерация Яндекс.Игр (и столько же на каждую правку)
        'gameQueue' => 86,     // игр готово в гите, со слов владельца
        'domsPerSrv'=> 35,     // столько доменов реально держит одна машина
    ];
}

function fm_line(string $ico, string $name, string $unit, float $perUnitDay, int $units,
                 float $price, int $lead, string $limiter, string $limDetail, array $extra = []): string {
    $K = fm_cfg();
    $now  = $perUnitDay * $units;
    $need = $perUnitDay > 0 ? (int)ceil($K['goal'] / $perUnitDay) : 0;
    $gap  = max(0, $need - $units);
    $cost = $gap * $price;

    $s  = '<div class="fm-line">';
    $s .= '<div class="fm-line-h"><span class="ic">'.$ico.'</span><div><b>'.h($name).'</b>'.
          '<div class="fm-line-s">'.h($unit).' · сейчас '.num($units).' шт · даёт $'.m2($now).' в день</div></div>'.
          '<div class="fm-line-p">'.money($perUnitDay).'<span>с юнита в день</span></div></div>';

    if ($perUnitDay > 0) {
        $pct = min(100, (int)round($units / max(1,$need) * 100));
        $s .= '<div class="fm-bar" style="margin:8px 0 4px"><i style="width:'.$pct.'%"></i></div>';
        $s .= '<div class="fm-line-s">до $'.m2($K['goal']).'/день нужно <b>'.num($need).'</b> шт'.
              ' — не хватает <b>'.num($gap).'</b> ('.$pct.'% пути)</div>';
    } else {
        $s .= '<div class="fm-line-s">линия пока в минусе, считать масштабирование рано — сперва вывести юнит в плюс</div>';
    }

    $s .= '<div class="fm-line-f">';
    if ($price > 0 && $gap > 0) $s .= '<span class="tag money">💵 закупка $'.m2($cost).'</span>';
    $s .= '<span class="tag time">⏱ '.$lead.' дн. до первых денег с юнита</span>';
    foreach ($extra as $e) $s .= '<span class="tag">'.h($e).'</span>';
    $s .= '</div>';
    $s .= '<div class="fm-lim"><b>Ограничитель:</b> '.h($limiter).' — '.h($limDetail).'</div>';
    $s .= '</div>';
    return $s;
}

function fm_production(array $srv, array $dom, array $servers, int $days): void {
    $K = fm_cfg();
    /* фактические ставки по локациям: профит в день на один юнит */
    $byKind = [];
    foreach ($servers as $s) {
        $a = $srv[(int)$s['id']] ?? null; if (!$a) continue;
        $k = (string)$s['kind'];
        if (!isset($byKind[$k])) $byKind[$k] = ['p'=>0.0,'units'=>0];
        $byKind[$k]['p'] += $a['rev'] - $a['cost'];
        $byKind[$k]['units'] += count($a['doms']);
    }
    $days = max(1, $days);
    $rate = function(string $k) use ($byKind, $days): array {
        $u = $byKind[$k]['units'] ?? 0;
        $p = ($byKind[$k]['p'] ?? 0.0) / $days;
        return [$u, $u > 0 ? $p / $u : 0.0];
    };
    [$uT, $rT] = $rate('tube');
    [$uK, $rK] = $rate('tracker');
    [$uG, $rG] = $rate('game');

    echo '<div class="fm-card fm-prod"><h2>⏳ Конвейер · рост это производство, а не кнопка</h2>';
    echo '<div class="fm-note" style="margin-top:0">У каждой линии свой такт, и он не сжимается усердием. '.
         'Ставка с юнита посчитана по факту за выбранный период, остальное — допущения, они подписаны.</div>';

    echo fm_line('🔞', 'Адалт-тюбы', 'домен', $rT, $uT, $K['domPrice'], $K['domLead'],
        'деньги и баны регистраторов',
        'домены отлетают пачками: сейчас из '.num(count($dom)).' грядок мёртвыми у реестра числятся 17. Закладывать надо с запасом и держать разных регистраторов',
        ['🏗 ещё ~'.(int)ceil(max(0, ($K['goal'] / max(0.0001,$rT)) - $uT)/$K['domsPerSrv']).' серверов', '📈 '.$K['domRamp'].' дн. до полки']);

    echo fm_line('🎯', 'Трекер-смарт', 'связка', $rK, $uK, 0.0, 2,
        'неизвестный потолок',
        'юнит здесь платит в '.($rT>0 ? number_format($rK/$rT,0) : '—').' раза больше, чем домен, и денег на вход не просит. Узкое место не в закупке, а в том, что никто не мерил, сколько связок эта схема держит',
        ['⚡ деньги на второй день']);

    echo fm_line('🕹', 'Игровая долина', 'игра', $rG, $uG, 0.0, $K['gameMod'],
        'модерация Яндекса',
        $K['gameMod'].' дней на новую игру и столько же на каждую правку. В гите лежит '.$K['gameQueue'].' готовых — очередь не в производстве, а на входе к Яндексу',
        ['📦 '.$K['gameQueue'].' игр в очереди', '🔁 правка = ещё '.$K['gameMod'].' дн.']);

    /* --- горизонт: что даст каждая линия, если начать сегодня --- */
    $wk = [
        ['🎯 Трекер-смарт', 2,  'проверить потолок: добавить вторую связку и посмотреть, не просядет ли первая'],
        ['🔞 Адалт-тюбы',   $K['domLead'], 'партия доменов начинает отдавать через '.$K['domLead'].' дня, на полку выходит к '.$K['domRamp'].'-му'],
        ['🕹 Игры',         $K['gameMod'], 'подать пачку игр сегодня — деньги не раньше чем через '.$K['gameMod'].' дней, зато очередь идёт параллельно'],
    ];
    echo '<div class="fm-sec">Если запустить сегодня</div><div class="fm-tl">';
    foreach ($wk as [$nm, $d, $txt]) {
        $when = (new DateTime('+'.$d.' days'))->format('d.m');
        echo '<div class="fm-tl-i"><div class="fm-tl-d">'.$when.'<span>+'.$d.' дн.</span></div>'.
             '<div class="fm-tl-t"><b>'.h($nm).'</b><div>'.h($txt).'</div></div></div>';
    }
    echo '</div>';

    /* --- честный вывод, ради которого всё считалось --- */
    $needDom = $rT > 0 ? (int)ceil($K['goal'] / $rT) : 0;
    $needTrk = $rK > 0 ? (int)ceil($K['goal'] / $rK) : 0;
    echo '<div class="fm-hint" style="margin-top:12px"><b>Что из этого следует.</b> '.
         'Чтобы взять $'.m2($K['goal']).' в день на тюбах, нужно '.num($needDom).' доменов, $'.
         m2(max(0,$needDom-$uT)*$K['domPrice']).' на закупку и месяцы прогрева — и всё это под баны регистраторов. '.
         'Та же сотня на трекере — это '.num($needTrk).' связок, вход бесплатный, деньги на второй день. '.
         'Разница не в усердии, а в такте линии: одна упирается в деньги и время, вторая — только в то, что её потолок никто не мерил. '.
         'Поэтому разумный ход — не «упарываться» в домены, а сперва потратить два дня и узнать, где у трекера край.</div>';
    echo '</div>';
}

/* ===================== 🌾 АГРОХОЛДИНГ · idle ===================== */
/**
 * Трёхмерная сцена сетки: каждый сервер — остров, каждый остров показывает
 * ровно то, что сервер делает на самом деле. Уровень острова — накопленный
 * профит, огонь — реальный убыток, монеты сыплются со скоростью настоящего
 * дохода, а не по таймеру.
 *
 * Idle-часть честная: счётчик капает ровно тем темпом, которым сетка
 * зарабатывает сейчас (профит за период / 86400 в секунду). Ничего не
 * «начисляется за просмотр» — сцена просто показывает то, что и так идёт.
 *
 * Спрайты нарисованы Grok-ом один раз и лежат в /art (php /root/farm-art.php).
 */
function fm_data(int $days): array {
    $db = db();
    $from = (new DateTime('-'.($days-1).' days'))->format('Y-m-d');
    $raw = q($db, "SELECT dd.d, dd.domain, dd.server_id, dd.cost, dd.revenue, dd.clicks, dd.visits,
                          dd.updated_at, s.is_aggregate
                     FROM daily dd JOIN servers s ON s.id = dd.server_id WHERE dd.d >= ?", [$from]);
    $pick = [];
    foreach ($raw as $r) {
        $k = $r['d'].'|'.$r['domain'];
        $c = $pick[$k] ?? null;
        if ($c === null) { $pick[$k] = $r; continue; }
        $cmp = (int)$r['is_aggregate'] <=> (int)$c['is_aggregate'];
        if ($cmp < 0 || ($cmp === 0 && $r['updated_at'] > $c['updated_at'])) $pick[$k] = $r;
    }
    $srv = $dom = [];
    foreach ($pick as $r) {
        $sid = (int)$r['server_id']; $c = (float)$r['cost']; $rv = (float)$r['revenue'];
        if (!isset($srv[$sid])) $srv[$sid] = ['cost'=>0.0,'rev'=>0.0,'visits'=>0,'doms'=>[]];
        $srv[$sid]['cost'] += $c; $srv[$sid]['rev'] += $rv; $srv[$sid]['visits'] += (int)$r['visits'];
        $srv[$sid]['doms'][$r['domain']] = 1;
        if (!isset($dom[$r['domain']])) $dom[$r['domain']] = ['cost'=>0.0,'rev'=>0.0,'visits'=>0,'srv'=>$sid];
        $dom[$r['domain']]['cost'] += $c; $dom[$r['domain']]['rev'] += $rv; $dom[$r['domain']]['visits'] += (int)$r['visits'];
    }
    $life = [];
    foreach (q($db, "SELECT server_id, ROUND(SUM(revenue)-SUM(cost),4) p FROM daily GROUP BY server_id") as $r)
        $life[(int)$r['server_id']] = (float)$r['p'];
    $servers = q($db, "SELECT id,code,ip,note,kind,is_enabled,last_seen_at FROM servers ORDER BY code");
    return ['srv'=>$srv, 'dom'=>$dom, 'life'=>$life, 'servers'=>$servers, 'days'=>$days];
}

function view_idle(int $days): void {
    $D = fm_data($days);
    $srv = $D['srv']; $dom = $D['dom']; $life = $D['life'];

    /* пожары по серверам */
    $firesBy = [];
    foreach ($dom as $d => $v) {
        $p = $v['rev'] - $v['cost'];
        if ($v['cost'] > 0.02 && $p < 0) $firesBy[$v['srv']][] = ['d'=>$d, 'loss'=>round(-$p,4), 'c'=>round($v['cost'],4), 'r'=>round($v['rev'],4)];
    }
    foreach ($firesBy as &$f) { usort($f, fn($a,$b)=>$b['loss']<=>$a['loss']); $f = array_slice($f,0,6); }
    unset($f);

    $KIND = ['tube'=>['Адалт-тюбы','#e0709a'], 'tracker'=>['Трекер-смарт','#5b8def'], 'game'=>['Игровая долина','#a374f0']];
    $farms = []; $tc = $tr = 0.0; $tv = 0;

    foreach ($D['servers'] as $s) {
        $sid = (int)$s['id'];
        $a = $srv[$sid] ?? ['cost'=>0.0,'rev'=>0.0,'visits'=>0,'doms'=>[]];
        $p = $a['rev'] - $a['cost'];
        $tc += $a['cost']; $tr += $a['rev']; $tv += $a['visits'];
        $lv = farm_level($life[$sid] ?? 0.0);
        $parked = (int)$s['is_enabled'] === 0;
        $silent = !$parked && is_stale($s['last_seen_at']);
        $burning = $p < 0 && $a['cost'] > 0.02;
        $state = $parked ? 'parked' : ($silent ? 'silent' : ($burning ? 'burning' : ($p > 0 ? 'growing' : 'idle')));
        /* спрайт: состояние важнее уровня — горящую ферму надо видеть сразу */
        $tier = $lv['i'] <= 2 ? 1 : ($lv['i'] <= 4 ? 2 : ($lv['i'] <= 6 ? 3 : ($lv['i'] <= 7 ? 4 : 5)));
        $sprite = ($state === 'burning') ? 'burn' : (($state === 'silent' || $state === 'parked') ? 'idle' : 'tier'.$tier);
        $kind = (string)$s['kind'];

        $farms[] = [
            'id'=>$sid, 'code'=>(string)$s['code'], 'ip'=>(string)$s['ip'], 'kind'=>$kind,
            'loc'=>$KIND[$kind][0] ?? $kind, 'sprite'=>$sprite, 'state'=>$state,
            'lv'=>$lv['i'], 'lvName'=>$lv['name'], 'lvIco'=>$lv['ico'], 'lvPct'=>$lv['pct'],
            'lvNext'=>$lv['next'], 'toNext'=>round(max(0,$lv['nextAt'] - ($life[$sid] ?? 0)),4),
            'rev'=>round($a['rev'],4), 'cost'=>round($a['cost'],4), 'profit'=>round($p,4),
            'roi'=>$a['cost']>0 ? round($a['rev']/$a['cost'],2) : null,
            'doms'=>count($a['doms']), 'visits'=>(int)$a['visits'],
            'life'=>round($life[$sid] ?? 0,4),
            'perSec'=>$p / max(1,$days) / 86400,
            'fires'=>$firesBy[$sid] ?? [],
            'unit'=>$kind==='game' ? 'игр' : ($kind==='tracker' ? 'связок' : 'доменов'),
            'note'=>(string)$s['note'],
        ];
    }
    $tp = $tr - $tc;
    $perSec = $tp / max(1,$days) / 86400;

    /* «знания внутри» — факты, посчитанные тут же, а не написанные руками */
    $facts = [];
    $rate = function(string $k) use ($farms, $days): array {
        $u = 0; $p = 0.0;
        foreach ($farms as $f) if ($f['kind'] === $k) { $u += $f['doms']; $p += $f['profit']; }
        return [$u, $u > 0 ? $p/$days/$u : 0.0];
    };
    [$uT,$rT] = $rate('tube'); [$uK,$rK] = $rate('tracker');
    if ($rT > 0 && $rK > 0)
        $facts[] = 'Связка трекера отбивает $'.number_format($rK,2).' в день, домен — $'.number_format($rT,3).
                   '. Разница в '.number_format($rK/$rT,0).' '.plural((int)round($rK/$rT),'раз','раза','раз').', и вход в трекер бесплатный.';
    if ($rT > 0)
        $facts[] = 'Чтобы взять $100 в день на тюбах, нужно '.num((int)ceil(100/$rT)).' доменов и месяцы прогрева.';
    $deadCnt = (int)(q(db(), "SELECT COUNT(*) n FROM domain_registrar WHERE verdict='dead'")[0]['n'] ?? 0);
    $okCnt   = (int)(q(db(), "SELECT COUNT(*) n FROM domain_registrar WHERE verdict='ok'")[0]['n'] ?? 0);
    if ($deadCnt) $facts[] = 'У регистратора '.plural($deadCnt,'мёртв','мертвы','мертвы').' '.$deadCnt.' '.plural($deadCnt,'домен','домена','доменов').' из '.($deadCnt+$okCnt).'. GoDaddy и Key-Systems положили их аккаунтами, а не по одному.';
    $allFires = [];
    foreach ($firesBy as $sid => $fs) foreach ($fs as $f) $allFires[] = $f;
    usort($allFires, fn($a,$b)=>$b['loss']<=>$a['loss']);
    if ($allFires) $facts[] = 'Сильнее всех горит '.$allFires[0]['d'].': топлива на $'.number_format($allFires[0]['c'],2).', урожая на $'.number_format($allFires[0]['r'],2).'.';
    $facts[] = 'Модерация Яндекс.Игр — 14 дней на игру и столько же на каждую правку. В гите 86 готовых, в проде одна.';
    $facts[] = 'С 00:00 до 09:00 МСК трафик не льётся: это ночной режим, а не поломка. Утренние цифры всегда выглядят плохо.';
    if ($tv > 0) $facts[] = 'За период сетка приняла '.num($tv).' визитов, и это дало $'.number_format($tr,2).' урожая.';

    $mskH = (int)(new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('G');
    $night = ($mskH < 9);

    echo farm_css(); echo idle_css();
    echo '<div class="id-wrap'.($night?' night':'').'">';
    echo '<canvas id="idScene"></canvas>';
    echo '<div class="id-fog"></div>';

    echo '<div class="id-hud id-tl">';
    echo   '<div class="id-gold"><span class="ic">🪙</span><span id="idGold">0.0000</span></div>';
    echo   '<div class="id-sub">намыто с открытия страницы · темп $'.number_format($perSec*3600,4).' в час</div>';
    echo   '<div class="id-sub2">профит за '.(int)$days.'д: <b class="'.($tp>=0?'g':'b').'">$'.number_format($tp,2).'</b> · урожай $'.number_format($tr,2).' · топливо $'.number_format($tc,2).'</div>';
    echo '</div>';

    echo '<div class="id-hud id-tr">';
    foreach ([['🌾', num(count($dom)), 'грядок'], ['🔥', num(count($allFires)), 'горит'], ['👣', num($tv), 'визитов'], ['📈', $tc>0?number_format($tr/$tc,2).'x':'—', 'ROI']] as [$i,$v,$l])
        echo '<div class="id-chip"><span>'.$i.'</span><b>'.$v.'</b><i>'.$l.'</i></div>';
    echo '</div>';

    if ($night) echo '<div class="id-night">🌙 Ночной режим · до 09:00 МСК трафик не льётся, острова спят по делу</div>';

    echo '<div class="id-labels" id="idLabels"></div>';
    echo '<div class="id-fact" id="idFact"></div>';
    echo '<div class="id-hint">колесо — приблизить · тяни мышью — осмотреться · клик по острову — что там на самом деле</div>';
    echo '</div>';

    /* панель фермы */
    echo '<div class="id-panel" id="idPanel"><button class="id-x" id="idClose">✕</button><div id="idPanelIn"></div></div>';

    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>';
    echo '<script>const FARMS='.json_encode($farms, JSON_UNESCAPED_UNICODE).';'
       . 'const PERSEC='.json_encode(round($perSec,10)).';'
       . 'const FACTS='.json_encode($facts, JSON_UNESCAPED_UNICODE).';'
       . 'const NIGHT='.($night?'true':'false').';</script>';
    echo idle_js();

    echo '<div class="fm-note" style="margin-top:14px">Всё, что видно на сцене, посчитано по базе центра: уровень острова — накопленный профит сервера, '
       . 'огонь — домены, которые за период потратили больше, чем принесли, монеты падают со скоростью реального дохода. '
       . 'Счётчик сверху капает ровно тем темпом, каким сетка зарабатывает сейчас, — он ничего не «начисляет за просмотр». '
       . 'Спрайты нарисованы Grok-ом один раз и лежат файлами: <code>php /root/farm-art.php</code> (повторный запуск бесплатен, перерисовать — <code>--force</code>).</div>';
}

function idle_css(): string { return <<<'CSS'
<style>
.id-wrap{position:relative;height:74vh;min-height:520px;border-radius:20px;overflow:hidden;
  background:linear-gradient(180deg,#0b1d2a,#123246 45%,#2a4f57);box-shadow:0 12px 40px rgba(10,25,35,.35)}
.id-wrap.night{background:linear-gradient(180deg,#050d16,#0b1c2b 50%,#14303a)}
#idScene{position:absolute;inset:0;width:100%;height:100%;display:block;cursor:grab}
#idScene:active{cursor:grabbing}
.id-fog{position:absolute;inset:0;pointer-events:none;background:radial-gradient(120% 80% at 50% 30%,transparent 40%,rgba(4,14,22,.55) 100%)}
.id-hud{position:absolute;z-index:3;color:#eaf6ff;text-shadow:0 1px 3px rgba(0,0,0,.55)}
.id-tl{left:18px;top:16px}
.id-gold{display:flex;align-items:baseline;gap:9px;font-size:34px;font-weight:800;letter-spacing:-.02em;color:#ffe08a}
.id-gold .ic{font-size:26px}
.id-sub{font-size:12px;color:#a9c9dd;margin-top:1px}
.id-sub2{font-size:12.5px;color:#cfe6f5;margin-top:5px}
.id-sub2 .g{color:#7cf0a8}.id-sub2 .b{color:#ff9b8a}
.id-tr{right:18px;top:16px;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;max-width:52%}
.id-chip{background:rgba(6,20,30,.5);border:1px solid rgba(255,255,255,.16);border-radius:11px;padding:6px 10px;text-align:center;backdrop-filter:blur(6px)}
.id-chip span{font-size:15px;display:block;line-height:1}
.id-chip b{font-size:15px;display:block;line-height:1.25}
.id-chip i{font-size:10px;color:#a9c9dd;font-style:normal;text-transform:uppercase;letter-spacing:.05em}
.id-night{position:absolute;left:50%;transform:translateX(-50%);top:14px;z-index:3;background:rgba(10,25,45,.62);
  border:1px solid rgba(160,190,255,.3);color:#cfe0ff;border-radius:999px;padding:5px 15px;font-size:12px;backdrop-filter:blur(6px)}
.id-labels{position:absolute;inset:0;pointer-events:none;z-index:2}
.id-lab{position:absolute;transform:translate(-50%,-100%);pointer-events:auto;cursor:pointer;text-align:center;
  background:rgba(6,20,30,.55);border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:3px 9px;
  color:#eaf6ff;font-size:11.5px;white-space:nowrap;backdrop-filter:blur(5px);transition:background .15s,border-color .15s}
.id-lab:hover{background:rgba(20,50,70,.85);border-color:#7cf0a8}
.id-lab b{display:block;font-size:12.5px}
.id-lab .p{font-weight:700}
.id-lab .p.g{color:#7cf0a8}.id-lab .p.b{color:#ff9b8a}
.id-lab .lv{color:#a9c9dd;font-size:10px}
.id-lab.fire{border-color:#ff7a4d;animation:idFire 1.6s ease-in-out infinite}
@keyframes idFire{0%,100%{box-shadow:0 0 0 0 rgba(255,122,77,.5)}50%{box-shadow:0 0 0 8px rgba(255,122,77,0)}}
.id-fact{position:absolute;left:18px;right:18px;bottom:38px;z-index:3;color:#dff0ff;font-size:13.5px;line-height:1.5;
  background:rgba(6,20,30,.5);border:1px solid rgba(255,255,255,.14);border-left:3px solid #7cf0a8;border-radius:12px;
  padding:9px 14px;backdrop-filter:blur(6px);max-width:760px;transition:opacity .5s}
.id-hint{position:absolute;left:0;right:0;bottom:12px;z-index:3;text-align:center;color:#8fb2c8;font-size:11px}
.id-panel{position:fixed;right:0;top:0;bottom:0;width:min(430px,94vw);background:#fff;z-index:60;
  box-shadow:-14px 0 44px rgba(0,0,0,.32);transform:translateX(102%);transition:transform .28s cubic-bezier(.3,.8,.3,1);
  overflow:auto;padding:18px}
.id-panel.open{transform:none}
.id-x{position:absolute;right:14px;top:14px;border:0;background:#eef2f7;border-radius:9px;width:30px;height:30px;cursor:pointer;font-size:14px}
.id-p-h{font-size:20px;font-weight:800;letter-spacing:-.02em;padding-right:38px}
.id-p-ip{color:var(--dim);font-size:12px;margin-bottom:10px}
.id-p-lv{display:flex;gap:10px;align-items:center;background:var(--card2);border:1px solid var(--line);border-radius:12px;padding:10px;margin-bottom:10px}
.id-p-lv .ic{font-size:30px}
.id-p-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.id-p-c{background:var(--card2);border:1px solid var(--line);border-radius:11px;padding:8px 10px}
.id-p-c .l{font-size:10.5px;color:var(--dim);text-transform:uppercase;letter-spacing:.05em}
.id-p-c .v{font-size:17px;font-weight:800}
.id-p-c .v.g{color:var(--pos)}.id-p-c .v.b{color:var(--neg)}
.id-p-s{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);margin:12px 0 6px}
</style>
CSS; }

function idle_js(): string { return <<<'JS'
<script>
(function(){
  const cv = document.getElementById('idScene');
  if (!cv || !window.THREE) return;
  const W = () => cv.clientWidth, H = () => cv.clientHeight;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(42, W()/H(), 0.1, 400);
  const renderer = new THREE.WebGLRenderer({canvas:cv, antialias:true, alpha:true});
  renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
  const loader = new THREE.TextureLoader();

  /* небо — дальний план, чуть параллаксит вместе с камерой */
  const skyMat = new THREE.MeshBasicMaterial({map: loader.load('art/sky.png'), depthWrite:false});
  const sky = new THREE.Mesh(new THREE.PlaneGeometry(260, 146), skyMat);
  sky.position.set(0, 12, -110); scene.add(sky);

  /* острова: билборды, всегда лицом к камере */
  const bands = {tube:0, tracker:1, game:2};
  const byBand = {0:[],1:[],2:[]};
  FARMS.forEach(f => byBand[bands[f.kind] ?? 0].push(f));

  const items = [];
  Object.keys(byBand).forEach(bk => {
    const band = byBand[bk], n = band.length, z = -18 * bk;
    band.forEach((f, i) => {
      const tex = loader.load('art/' + f.sprite + '.png');
      tex.minFilter = THREE.LinearFilter;
      const spr = new THREE.Sprite(new THREE.SpriteMaterial({map:tex, transparent:true, depthWrite:false}));
      /* размер острова растёт с уровнем — прогресс видно, не читая цифр */
      const s = 7 + f.lv * 1.15;
      spr.scale.set(s * 1.5, s, 1);
      const spread = Math.max(1, n - 1);
      const x = (i - spread/2) * (s * 1.32);
      spr.position.set(x, 0, z);
      spr.userData = f;
      scene.add(spr);
      items.push({spr, f, baseY: 0, phase: Math.random() * 6.28, x, z});
    });
  });

  /* монеты и дым — количество считаем от реального дохода/убытка */
  const pGeo = new THREE.BufferGeometry();
  const PMAX = 900;
  const pPos = new Float32Array(PMAX*3), pCol = new Float32Array(PMAX*3);
  const parts = [];
  pGeo.setAttribute('position', new THREE.BufferAttribute(pPos,3));
  pGeo.setAttribute('color', new THREE.BufferAttribute(pCol,3));
  const pts = new THREE.Points(pGeo, new THREE.PointsMaterial({size:0.85, vertexColors:true, transparent:true, opacity:.95, depthWrite:false}));
  scene.add(pts);

  function emit(it){
    if (parts.length >= PMAX) return;
    const gold = it.f.profit > 0;
    parts.push({x: it.x + (Math.random()-.5)*6, y: -1 + Math.random()*2, z: it.z + (Math.random()-.5)*3,
                vy: gold ? .10+Math.random()*.07 : .05+Math.random()*.04,
                life: 1, gold, drift:(Math.random()-.5)*.02});
  }

  let camA = 0, camTargetA = 0, camDist = 62, camTargetDist = 62, camY = 16;
  let drag = false, lastX = 0, autoRot = true, tPrev = performance.now();

  cv.addEventListener('pointerdown', e=>{drag=true; lastX=e.clientX; autoRot=false;});
  addEventListener('pointerup', ()=>{drag=false; setTimeout(()=>autoRot=true, 3500);});
  addEventListener('pointermove', e=>{ if(drag){ camTargetA -= (e.clientX-lastX)*0.005; lastX=e.clientX; } });
  cv.addEventListener('wheel', e=>{ e.preventDefault(); camTargetDist = Math.max(26, Math.min(110, camTargetDist + e.deltaY*0.06)); }, {passive:false});

  /* подписи островов рисуем HTML-ом: текст остаётся резким на любом зуме */
  const labWrap = document.getElementById('idLabels');
  const labs = items.map(it => {
    const d = document.createElement('div');
    d.className = 'id-lab' + (it.f.state === 'burning' ? ' fire' : '');
    const cls = it.f.profit >= 0 ? 'g' : 'b';
    d.innerHTML = '<b>' + it.f.code + '</b><span class="p ' + cls + '">' +
      (it.f.profit>=0?'+':'') + it.f.profit.toFixed(2) + '</span> <span class="lv">' +
      it.f.lvIco + ' ур.' + it.f.lv + ' · ' + it.f.doms + ' ' + it.f.unit + '</span>';
    d.addEventListener('click', ()=>openPanel(it.f));
    labWrap.appendChild(d);
    return d;
  });

  const v = new THREE.Vector3();
  function frame(now){
    const dt = Math.min(0.05, (now - tPrev)/1000); tPrev = now;
    if (autoRot) camTargetA += dt * 0.045;
    camA += (camTargetA - camA) * 0.07;
    camDist += (camTargetDist - camDist) * 0.07;
    camera.position.set(Math.sin(camA)*camDist, camY, Math.cos(camA)*camDist - 14);
    camera.lookAt(0, 1, -18);
    sky.position.set(Math.sin(camA)*8, 12, Math.cos(camA)*8 - 110);
    sky.lookAt(camera.position.x, 12, camera.position.z);

    items.forEach(it => {
      it.spr.position.y = it.baseY + Math.sin(now/1000 * 0.7 + it.phase) * 0.85;
      /* частота монет пропорциональна реальному доходу фермы */
      const rate = Math.min(0.5, Math.abs(it.f.profit) * 0.02 + 0.012);
      if (Math.random() < rate * dt * 30) emit(it);
    });

    let k = 0;
    for (let i = parts.length-1; i >= 0; i--){
      const p = parts[i];
      p.y += p.vy; p.x += p.drift; p.life -= dt * (p.gold ? 0.34 : 0.24);
      if (p.life <= 0 || p.y > 22) { parts.splice(i,1); continue; }
    }
    parts.forEach(p => {
      pPos[k*3]=p.x; pPos[k*3+1]=p.y; pPos[k*3+2]=p.z;
      if (p.gold){ pCol[k*3]=1; pCol[k*3+1]=.84; pCol[k*3+2]=.35; }
      else { pCol[k*3]=.45; pCol[k*3+1]=.45; pCol[k*3+2]=.47; }
      k++;
    });
    pGeo.setDrawRange(0, k);
    pGeo.attributes.position.needsUpdate = true;
    pGeo.attributes.color.needsUpdate = true;

    items.forEach((it, i) => {
      v.set(it.spr.position.x, it.spr.position.y + it.spr.scale.y*0.42, it.spr.position.z).project(camera);
      const vis = v.z < 1;
      labs[i].style.display = vis ? 'block' : 'none';
      if (vis){
        labs[i].style.left = ((v.x*0.5+0.5) * W()) + 'px';
        labs[i].style.top  = ((-v.y*0.5+0.5) * H()) + 'px';
      }
    });

    renderer.render(scene, camera);
    requestAnimationFrame(frame);
  }

  function resize(){ renderer.setSize(W(), H(), false); camera.aspect = W()/H(); camera.updateProjectionMatrix(); }
  addEventListener('resize', resize); resize();
  requestAnimationFrame(frame);

  /* клик по самому острову, а не только по подписи */
  const ray = new THREE.Raycaster();
  cv.addEventListener('click', e=>{
    const r = cv.getBoundingClientRect();
    ray.setFromCamera(new THREE.Vector2(((e.clientX-r.left)/r.width)*2-1, -((e.clientY-r.top)/r.height)*2+1), camera);
    const hit = ray.intersectObjects(items.map(i=>i.spr));
    if (hit.length) openPanel(hit[0].object.userData);
  });

  /* --- счётчик: капает ровно реальным темпом --- */
  const gold = document.getElementById('idGold');
  const t0 = performance.now();
  (function tick(){
    const acc = PERSEC * (performance.now()-t0)/1000;
    gold.textContent = (acc>=0?'':'−') + Math.abs(acc).toFixed(4);
    gold.style.color = acc>=0 ? '#ffe08a' : '#ff9b8a';
    requestAnimationFrame(tick);
  })();

  /* --- факты по кругу --- */
  const fact = document.getElementById('idFact');
  let fi = 0;
  function showFact(){
    if (!FACTS.length) return;
    fact.style.opacity = 0;
    setTimeout(()=>{ fact.textContent = FACTS[fi % FACTS.length]; fact.style.opacity = 1; fi++; }, 500);
  }
  showFact(); setInterval(showFact, 9000);

  /* --- панель фермы --- */
  const panel = document.getElementById('idPanel'), pin = document.getElementById('idPanelIn');
  document.getElementById('idClose').addEventListener('click', ()=>panel.classList.remove('open'));
  addEventListener('keydown', e=>{ if(e.key==='Escape') panel.classList.remove('open'); });
  function money(x){ return (x<0?'−$':'$') + Math.abs(x).toFixed(4); }
  function openPanel(f){
    let h = '<div class="id-p-h">' + f.lvIco + ' ' + f.code + '</div><div class="id-p-ip">' + f.ip + ' · ' + f.loc + '</div>';
    h += '<div class="id-p-lv"><div class="ic">' + f.lvIco + '</div><div style="flex:1"><b>Уровень ' + f.lv + ' · ' + f.lvName + '</b>' +
         '<div class="fm-bar" style="margin:5px 0 3px"><i style="width:' + f.lvPct + '%"></i></div>' +
         '<div style="font-size:11px;color:var(--dim)">намыто ' + money(f.life) + ' за всю историю' +
         (f.toNext>0 ? ' · до «' + f.lvNext + '» ещё ' + money(f.toNext) : ' · максимум') + '</div></div></div>';
    h += '<div class="id-p-grid">' +
      card('Профит за период', money(f.profit), f.profit>=0?'g':'b') +
      card('ROI', f.roi === null ? '—' : f.roi + 'x', '') +
      card('Урожай', money(f.rev), '') +
      card('Топливо', money(f.cost), '') +
      card(f.unit.charAt(0).toUpperCase()+f.unit.slice(1), String(f.doms), '') +
      card('Визиты', f.visits.toLocaleString('ru'), '') + '</div>';
    if (f.fires.length){
      h += '<div class="id-p-s">🔥 Горящие грядки</div><table class="fm-tab"><tr><th>Грядка</th><th>Топливо</th><th>Урожай</th><th>Убыток</th></tr>';
      f.fires.forEach(x => { h += '<tr><td>'+x.d+'</td><td>'+money(x.c)+'</td><td>'+money(x.r)+'</td><td class="bad">−'+x.loss.toFixed(4)+'</td></tr>'; });
      h += '</table><div class="fm-hint">Прежде чем резать — посмотри по дням: бывает, весь минус дал один перезалив, и лечится он лимитом объёма, а не ставкой.</div>';
    }
    if (f.note) h += '<div class="id-p-s">Заметка</div><div class="fm-hint">' + f.note + '</div>';
    h += '<div class="id-p-s">Куда дальше</div><div class="fm-hint">Разбор по доменам этой фермы — в разделе «Фермы», там же апгрейды со сроками.</div>';
    pin.innerHTML = h;
    panel.classList.add('open');
  }
  function card(l,v,c){ return '<div class="id-p-c"><div class="l">'+l+'</div><div class="v '+c+'">'+v+'</div></div>'; }
})();
</script>
JS; }

/** Русское склонение по числу: 1 домен, 2 домена, 5 доменов. */
function plural(int $n, string $one, string $few, string $many): string {
    $n = abs($n) % 100;
    if ($n >= 11 && $n <= 14) return $many;
    $n %= 10;
    if ($n === 1) return $one;
    if ($n >= 2 && $n <= 4) return $few;
    return $many;
}

/* ---------- Штаб: что тушить, где реагировать, что растить ---------- */
/**
 * Верхний экран должен читаться за пять секунд. Поэтому здесь не цифры,
 * а решения: у каждой фермы ровно один вердикт и одна цена бездействия —
 * сколько долларов в сутки стоит то, что с ней сейчас не так.
 *
 * Порядок вердиктов — это и есть порядок работы: сперва тушим то, что горит
 * прямо сейчас, потом чиним сломанное, и только потом растим здоровое.
 */

/** Пайплайн фермы. Уровни проверяемые, а не декоративные: каждый — запрос к данным. */
function fm_pipeline(array $doms, array $regs, array $meta, float $cost, float $rev,
                     bool $silent, bool $parked, float $netRev1k, array $recent = []): array {
    $dead = $noMoney = $burn = $overpay = $old = [];
    foreach ($doms as $d => $v) {
        $p = $v['rev'] - $v['cost'];
        if (isset($regs[$d]) && $regs[$d] === 'dead')                      $dead[] = $d;
        if ((int)$v['visits'] >= 50 && $v['rev'] <= 0.001)                 $noMoney[] = $d;
        if ($v['cost'] > 0.02 && $p < 0)                                   $burn[] = $d;
        if ((int)$v['clicks'] >= 500 && $v['cost'] > $v['rev'])            $overpay[] = $d;
        if ((int)$v['visits'] >= 100 && (int)($meta[$d] ?? 0) === 0)       $old[] = $d;
    }
    $roi = $cost > 0 ? $rev / $cost : 0.0;

    /* потерянный доход считаем по средней отдаче сетки, а не по мечте */
    $lostMon = 0.0;
    foreach ($noMoney as $d) $lostMon += ($doms[$d]['visits'] / 1000) * $netRev1k;
    $lossBurn = 0.0;
    foreach ($burn as $d) $lossBurn += $doms[$d]['cost'] - $doms[$d]['rev'];
    /* сколько потерянные домены жгут СЕЙЧАС: среднее за неделю врёт, если
       кампании по ним уже погасили — тогда чинить надо домен, а не расход */
    $deadCost = 0.0;
    foreach ($dead as $d) $deadCost += (float)($recent[$d] ?? 0.0);

    $L = [
        ['Жива',            'Сервер на связи и в учёте',                      !$silent && !$parked,
         $parked ? 'ферма законсервирована' : 'молчит больше часа — данные не приходят',
         'Проверить money-push и крон на сервере', 0.0, '10 мин'],
        ['Домены целы',     'Ни один домен не потерян у регистратора',        count($dead) === 0,
         count($dead).' '.plural(count($dead),'домен потерян','домена потеряно','доменов потеряно').' у реестра'.
             ($deadCost > 0.0005 ? ', и закуп по ним ещё идёт' : ', закуп по ним уже погашен'),
         $deadCost > 0.0005 ? 'Остановить кампании по ним, потом разбираться с регистратором'
                            : 'Вернуть у регистратора или перенести домены к другому',
         $deadCost, $deadCost > 0.0005 ? '5 мин на стоп' : 'от суток, зависит от регистратора'],
        ['Монетизирована',  'Нет доменов с трафиком и нулевым доходом',       count($noMoney) === 0,
         count($noMoney).' '.plural(count($noMoney),'домен','домена','доменов').' с трафиком не отдают рекламу',
         'Проверить ad_blocks: площадки заведены, а блок не прописан', $lostMon, '30–60 мин'],
        ['Не горит',        'Нет доменов, которые тратят больше, чем несут',  count($burn) === 0,
         count($burn).' '.plural(count($burn),'домен жжёт','домена жгут','доменов жгут').' бюджет',
         'Сперва посмотреть по дням: разовый перезалив лечится лимитом, а не ставкой', $lossBurn, '20 мин'],
        ['Ставка по отдаче','Не переплачиваем за клик ни на одном домене',    count($overpay) === 0,
         count($overpay).' '.plural(count($overpay),'домен','домена','доменов').' с ценой клика выше отдачи',
         'Привязать ставку к отдаче домена, а не держать плоскую по сетке', 0.0, '40 мин'],
        ['Модернизирована', 'Живые домены доведены по рецепту',              count($old) === 0,
         count($old).' '.plural(count($old),'домен','домена','доменов').' с трафиком ещё шаблонные',
         'Прогнать по RECIPE.md — обычно даёт x1.5–2 к доходу домена', 0.0, '2–3 часа на домен'],
        ['Масштабируется',  'ROI выше 2x — можно доливать объём',             $roi >= 2.0,
         'ROI '.($cost>0?number_format($roi,2).'x':'—').', доливать объём рано',
         'Сперва поднять отдачу, потом объём', 0.0, '—'],
    ];

    $levels = []; $cur = null;
    foreach ($L as $i => [$name, $crit, $ok, $why, $act, $price, $time]) {
        $st = $ok ? 'done' : ($cur === null ? 'now' : 'lock');
        if (!$ok && $cur === null) $cur = $i;
        $levels[] = ['n'=>$i+1, 'name'=>$name, 'crit'=>$crit, 'st'=>$st,
                     'why'=>$why, 'act'=>$act, 'price'=>$price, 'time'=>$time];
    }
    return ['levels'=>$levels, 'cur'=>$cur, 'passed'=>$cur === null ? count($L) : $cur,
            'total'=>count($L), 'dead'=>$dead, 'noMoney'=>$noMoney, 'burn'=>$burn,
            'overpay'=>$overpay, 'old'=>$old, 'lossBurn'=>$lossBurn, 'lostMon'=>$lostMon, 'deadCost'=>$deadCost];
}

/**
 * Один вердикт на ферму — и он всегда совпадает с текущим уровнем пайплайна.
 * Иначе плитка говорит «тушить пожар», а уровень — «сперва верни домены»,
 * и человек получает два разных указания на одну ферму.
 */
function fm_verdict(array $pl, bool $silent, bool $parked, float $profit, int $days): array {
    if ($parked) return ['СПИТ', 'z', '⏸', 'законсервирована, денег не просит и не носит', 0.0];
    if ($pl['cur'] === null)
        return ['ДЕРЖАТЬ', 'o', '👑', 'весь пайплайн пройден, узких мест не осталось', 0.0];

    $l = $pl['levels'][$pl['cur']];
    /* каждому уровню — свой характер действия: тушить, чинить или растить */
    $M = [
        0 => ['ЧИНИТЬ', 'w', '🔕'],   // не на связи
        1 => ['ТУШИТЬ', 'f', '🛂'],   // домены потеряны у реестра
        2 => ['ЧИНИТЬ', 'w', '🔌'],   // трафик есть, дохода нет
        3 => ['ТУШИТЬ', 'f', '🔥'],   // жжёт бюджет
        4 => ['ЧИНИТЬ', 'w', '💸'],   // переплата за клик
        5 => ['РАСТИТЬ', 'g', '🎨'],  // ждёт модернизации
        6 => ['ДЕРЖАТЬ', 'o', '✅'],  // всё цело, но объём доливать рано
    ];
    [$verd, $cls, $ico] = $M[$pl['cur']] ?? ['ЧИНИТЬ', 'w', '❓'];
    return [$verd, $cls, $ico, $l['why'], $l['price'] / max(1, $days)];
}

/* ===================== 🌳 ДЕРЕВО ===================== */
/**
 * Иерархия всего хозяйства: направление → тема → подтема → конкретный объект.
 *
 * Смысл не в красивой картинке, а в адресации. Когда «где-то падает доход»,
 * непонятно, куда идти. Когда есть дерево, у любой ситуации есть адрес:
 * Адалт → Домены → Баны регистраторов → GoDaddy. Дальше по адресу видно, что
 * с этим делать, и можно отметить, что ты этого коснулся.
 *
 * Дерево целиком отдаётся как JSON (?view=tree&format=json) — это подложка,
 * из которой потом собираются задачи: у каждого узла есть состояние и цена.
 *
 * Отметки «коснулся» лежат в money.tree_touch по дням: вечером видно, к скольким
 * ситуациям ты за день подошёл, а не только сколько денег принесла сетка.
 */

/** Узел дерева. Состояние: ok | warn | fire | idle. */
function tn(string $id, string $ico, string $title, string $desc = '', string $state = 'ok',
            string $metric = '', string $link = '', array $kids = []): array {
    return ['id'=>$id, 'ico'=>$ico, 'title'=>$title, 'desc'=>$desc, 'state'=>$state,
            'metric'=>$metric, 'link'=>$link, 'kids'=>$kids];
}

function tree_build(int $days): array {
    $db = db();
    $from = (new DateTime('-'.($days-1).' days'))->format('Y-m-d');

    /* --- сырьё --- */
    $rows = q($db, "SELECT dd.d, dd.domain, dd.server_id, dd.cost, dd.revenue, dd.clicks, dd.visits,
                           dd.updated_at, s.is_aggregate, s.kind, s.code, s.ip
                      FROM daily dd JOIN servers s ON s.id = dd.server_id WHERE dd.d >= ?", [$from]);
    $pick = [];
    foreach ($rows as $r) {
        $k = $r['d'].'|'.$r['domain'];
        $c = $pick[$k] ?? null;
        if ($c === null) { $pick[$k] = $r; continue; }
        $cmp = (int)$r['is_aggregate'] <=> (int)$c['is_aggregate'];
        if ($cmp < 0 || ($cmp === 0 && $r['updated_at'] > $c['updated_at'])) $pick[$k] = $r;
    }
    $dom = $srv = [];
    foreach ($pick as $r) {
        $d = $r['domain']; $sid = (int)$r['server_id'];
        if (!isset($dom[$d])) $dom[$d] = ['cost'=>0.0,'rev'=>0.0,'clicks'=>0,'visits'=>0,'srv'=>$sid,'kind'=>$r['kind'],'code'=>$r['code']];
        $dom[$d]['cost'] += (float)$r['cost']; $dom[$d]['rev'] += (float)$r['revenue'];
        $dom[$d]['clicks'] += (int)$r['clicks']; $dom[$d]['visits'] += (int)$r['visits'];
        if (!isset($srv[$sid])) $srv[$sid] = ['cost'=>0.0,'rev'=>0.0,'visits'=>0,'doms'=>[]];
        $srv[$sid]['cost'] += (float)$r['cost']; $srv[$sid]['rev'] += (float)$r['revenue'];
        $srv[$sid]['visits'] += (int)$r['visits']; $srv[$sid]['doms'][$d] = 1;
    }
    $servers = q($db, "SELECT id,code,ip,note,kind,is_enabled,last_seen_at FROM servers ORDER BY code");
    $reg = [];
    foreach (q($db, "SELECT domain, registrar, verdict, expires_at, reason FROM domain_registrar") as $r) $reg[$r['domain']] = $r;
    $meta = [];
    foreach (q($db, "SELECT domain, modernized, html_donor FROM domain_meta") as $r) $meta[$r['domain']] = $r;
    $nets = [];
    try { $nets = q($db, "SELECT code,title,status,payout_min,min_daily_traffic,approval_time,notes FROM fapacrm.crm_network_status ORDER BY FIELD(status,'connected','approved','pending','not-signed'), code"); } catch (Throwable $e) {}
    $conn = [];
    try { foreach (q($db, "SELECT connector, ROUND(SUM(amount),2) s FROM fapacrm.crm_finance_daily WHERE d>=? AND kind='revenue' GROUP BY connector", [$from]) as $r) $conn[$r['connector']] = (float)$r['s']; } catch (Throwable $e) {}

    $m = fn(float $v) => ($v < 0 ? '−$' : '$').number_format(abs($v), 2);
    $st = fn(float $p) => $p > 0 ? 'ok' : ($p < 0 ? 'fire' : 'idle');

    /* ---------- ветка по типу сервера ---------- */
    $branch = function(string $kind, string $ico, string $title, string $desc, string $unitOne, string $unitMany)
              use ($dom, $srv, $servers, $reg, $meta, $nets, $conn, $m, $st, $days, $db): array {

        $myDoms = array_filter($dom, fn($v) => $v['kind'] === $kind);
        $mySrv  = array_values(array_filter($servers, fn($s) => (string)$s['kind'] === $kind));
        $cost = $rev = 0.0; $vis = 0;
        foreach ($myDoms as $v) { $cost += $v['cost']; $rev += $v['rev']; $vis += $v['visits']; }
        $prof = $rev - $cost;
        $kids = [];

        /* --- источники трафика --- */
        $srcKids = [tn($kind.'/src/kadam', '🅺', 'Kadam',
            'Единственный источник закупа. Аккаунт 141381 общий на всю сетку, кампаниями управляет сервер домена.',
            $cost > 0 ? 'ok' : 'idle', 'расход '.$m($cost).' за '.$days.'д')];
        $kids[] = tn($kind.'/src', '📥', 'Источники', 'Откуда берётся трафик и сколько он стоит.',
            'ok', 'расход '.$m($cost), '', $srcKids);

        /* --- сетки монетизации: только тюбы --- */
        /* справочник и коннекторы в fapacrm описывают монетизация тюбов; показывать
           те же суммы под трекером и играми — значит трижды посчитать одни деньги */
        if ($nets && $kind === 'tube') {
            $on = $off = [];
            foreach ($nets as $n) {
                $c = (string)$n['connector'];
                $money = 0.0;
                foreach ($conn as $ck => $cv) if ($c !== '' && str_starts_with($ck, explode('_', $c)[0])) $money += $cv;
                $node = tn($kind.'/net/'.$n['code'], $n['status'] === 'connected' ? '🟢' : '⚪', (string)$n['title'],
                    trim((string)($n['notes'] ?? '')) ?: 'минималка: '.((string)$n['payout_min'] ?: 'не указана'),
                    $n['status'] === 'connected' ? ($money > 0 ? 'ok' : 'warn') : 'idle',
                    $n['status'] === 'connected'
                        ? ($money > 0 ? 'доход '.$m($money).' за '.$days.'д' : 'подключена, но денег не даёт')
                        : 'не подключена · '.((string)$n['min_daily_traffic'] ?: 'требования не указаны'));
                if ($n['status'] === 'connected') $on[] = $node; else $off[] = $node;
            }
            $netKids = [];
            if ($on)  $netKids[] = tn($kind.'/net/on', '🔌', 'Подключённые ('.count($on).')', 'Каналы сбыта, которые сейчас принимают трафик.', 'ok', $m(array_sum($conn)), '', $on);
            if ($off) $netKids[] = tn($kind.'/net/off', '🔒', 'Не подключены ('.count($off).')', 'Запертые склады: урожай есть, сдавать некуда. Каждая — потенциальный новый канал.', 'warn', count($off).' шт', '', $off);
            $kids[] = tn($kind.'/net', '💰', 'Сетки монетизации', 'Кому продаём показы и клики. Разные типы платят за разное.', count($off) ? 'warn' : 'ok', 'доход '.$m($rev), '', $netKids);
        }

        if ($kind === 'tracker')
            $kids[] = tn('tracker/pay', '💸', 'Выплаты', 'Смартлинка платит за лид. Деньги оборачиваются за двое суток — самый короткий цикл в хозяйстве.',
                $rev > 0 ? 'ok' : 'idle', 'доход '.$m($rev).' за '.$days.'д', '?view=trackers&days='.$days);
        if ($kind === 'game')
            $kids[] = tn('game/pay', '💸', 'Выплаты Яндекса', 'Консоль Игр платит в рублях, в центр приходит доллар по курсу '.number_format(RUB_USD, 0).
                '. Ограничитель не производство, а модерация: 14 дней на игру и столько же на каждую правку.',
                $rev > 0 ? 'ok' : 'idle', 'доход '.$m($rev).' за '.$days.'д', '?view=games&days='.$days);

        /* --- домены: активные по регистраторам + баны --- */
        if ($kind === 'tube') {
            $byReg = $banned = [];
            foreach ($myDoms as $d => $v) {
                $r = $reg[$d] ?? null;
                $rg = $r ? ((string)$r['registrar'] ?: '—') : 'не проверялся';
                $p = $v['rev'] - $v['cost'];
                $why = $p > 0.01 ? 'несёт стабильно' : ($p < -0.01 ? 'жжёт: топливо '.$m($v['cost']).' против урожая '.$m($v['rev']) : 'в нуле, оборот мал');
                if ($v['visits'] >= 50 && $v['rev'] <= 0.001) $why = 'трафик идёт ('.num($v['visits']).' виз.), а рекламы нет — блок не прописан';
                if ((int)($meta[$d]['modernized'] ?? 0) === 0 && $v['visits'] >= 100) $why .= '; сайт ещё шаблонный';
                $node = tn('tube/dom/'.$d, $p > 0 ? '🌿' : ($p < 0 ? '🔥' : '🌱'), $d, $why, $st($p),
                    $m($p).' за '.$days.'д', '?view=domains&dom='.rawurlencode($d).'&days='.$days);
                if ($r && $r['verdict'] === 'dead') $banned[$rg][] = $node; else $byReg[$rg][] = $node;
            }
            ksort($byReg); ksort($banned);
            $actKids = [];
            foreach ($byReg as $rg => $list) {
                $sum = 0.0;
                foreach ($list as $n) if (isset($myDoms[$n['title']])) $sum += $myDoms[$n['title']]['rev'] - $myDoms[$n['title']]['cost'];
                $actKids[] = tn('tube/dom/reg/'.$rg, '🏛', $rg, count($list).' '.plural(count($list),'домен','домена','доменов').' здесь, потерь нет',
                    $sum >= 0 ? 'ok' : 'fire', $m($sum).' за '.$days.'д', '', $list);
            }
            $banKids = [];
            foreach ($banned as $rg => $list)
                $banKids[] = tn('tube/dom/ban/'.$rg, '⛔', $rg, 'Аккаунт положен целиком: '.count($list).' '.plural(count($list),'домен','домена','доменов').' разом. Один домен так не падает.',
                    'fire', count($list).' шт', '?view=registrar&days='.$days, $list);
            $domKids = [];
            $nAct = 0; foreach ($byReg as $l) $nAct += count($l);
            $nBan = 0; foreach ($banned as $l) $nBan += count($l);
            $domKids[] = tn('tube/dom/active', '✅', 'Текущие активные ('.$nAct.')', 'Работают, у реестра вопросов нет.', 'ok', $m($prof), '', $actKids);
            if ($banKids) $domKids[] = tn('tube/dom/banned', '⛔', 'Баны регистраторов ('.$nBan.')', 'Потеряны на стороне реестра. Изнутри сервера это неотличимо от «домен перестал приносить».', 'fire', $nBan.' шт', '?view=registrar&days='.$days, $banKids);
            $kids[] = tn('tube/dom', '🌐', 'Домены ('.count($myDoms).')', 'Основной актив. Каждый домен — отдельная экономика: своя цена клика и своя отдача.', $nBan ? 'fire' : 'ok', $m($prof).' за '.$days.'д', '?view=domains&days='.$days, $domKids);

            /* --- контент --- */
            $modOn = $modOff = 0;
            foreach ($myDoms as $d => $v) { if ((int)($meta[$d]['modernized'] ?? 0) === 1) $modOn++; elseif ($v['visits'] >= 100) $modOff++; }
            $kids[] = tn('tube/content', '🎬', 'Контент', 'Сайт из шаблона и сайт, доведённый по рецепту, зарабатывают по-разному.',
                $modOff ? 'warn' : 'ok', $modOn.' доведено, '.$modOff.' ждут', '?view=templates&days='.$days);
        }

        /* --- серверы --- */
        $srvKids = [];
        foreach ($mySrv as $s) {
            $sid = (int)$s['id'];
            $a = $srv[$sid] ?? ['cost'=>0.0,'rev'=>0.0,'visits'=>0,'doms'=>[]];
            $p = $a['rev'] - $a['cost'];
            $parked = (int)$s['is_enabled'] === 0;
            $silent = !$parked && is_stale($s['last_seen_at']);
            $why = $parked ? 'законсервирован' : ($silent ? 'молчит больше часа — данные устарели'
                 : ($p > 0 ? 'работает в плюс' : ($p < 0 ? 'тратит больше, чем несёт' : 'оборота мало')));
            $dk = [];
            foreach (array_keys($a['doms']) as $d) {
                $v = $dom[$d]; $dp = $v['rev'] - $v['cost'];
                $dk[] = tn('srv/'.$sid.'/'.$d, $dp > 0 ? '🌿' : ($dp < 0 ? '🔥' : '🌱'), $d,
                    'урожай '.$m($v['rev']).', топливо '.$m($v['cost']).', визитов '.num($v['visits']),
                    $st($dp), $m($dp), '?view=domains&dom='.rawurlencode($d).'&days='.$days);
            }
            usort($dk, fn($x, $y) => strcmp($x['title'], $y['title']));
            $srvKids[] = tn('srv/'.$sid, $parked ? '⏸' : ($silent ? '🔕' : ($p >= 0 ? '🖥' : '🔥')),
                (string)$s['code'], (string)$s['ip'].' — '.$why,
                $parked ? 'idle' : ($silent ? 'warn' : $st($p)),
                $m($p).' · '.count($a['doms']).' '.($kind === 'game' ? plural(count($a['doms']),'игра','игры','игр')
                    : ($kind === 'tracker' ? plural(count($a['doms']),'связка','связки','связок')
                    : plural(count($a['doms']),'домен','домена','доменов'))),
                '?view=farms&days='.$days, $dk);
        }
        if ($srvKids) $kids[] = tn($kind.'/srv', '🖥', 'Серверы ('.count($mySrv).')',
            'Каждая машина держит свой кусок сетки и свою копию CRM.', 'ok', $m($prof), '?view=servers&days='.$days, $srvKids);

        $unitWord = $kind === 'tube'    ? plural(count($myDoms), 'домен', 'домена', 'доменов')
                  : ($kind === 'tracker' ? plural(count($myDoms), 'связка', 'связки', 'связок')
                  :                        plural(count($myDoms), 'игра', 'игры', 'игр'));
        return tn($kind, $ico, $title, $desc, $st($prof),
            $m($prof).' за '.$days.'д · '.count($myDoms).' '.$unitWord,
            '', $kids);
    };

    $tree = [
        $branch('tube', '🔞', 'Адалт-тюбы',
            'Покупаем клики в Kadam, показываем рекламу, живём с разницы. Основное направление.', 'домен', 'доменов'),
        $branch('tracker', '🎯', 'Смартлинк / Трекер',
            'Клик-трекер и смартлинка. Платят за лид, а не за показ, и деньги оборачиваются за двое суток.', 'связка', 'связок'),
        $branch('game', '🕹', 'Яндекс.Игры',
            'Рубли по курсу '.number_format(RUB_USD, 0).'. Ограничитель — модерация: 14 дней на игру и столько же на каждую правку.', 'игра', 'игр'),
    ];
    return $tree;
}

/** Плоский список узлов — для счётчика и поиска. */
function tree_flat(array $nodes, array &$out = []): array {
    foreach ($nodes as $n) { $out[] = $n; if ($n['kids']) tree_flat($n['kids'], $out); }
    return $out;
}




function tree_css(): string { return <<<'CSS'
<style>
.tr-tools{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 10px;align-items:center}
#trQ{flex:1;min-width:220px;padding:9px 13px;border:1px solid var(--line);border-radius:11px;font-size:13.5px;background:#fff}
#trQ:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px #2563eb1a}
.tr-b{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 13px;font-size:12.5px;cursor:pointer}
.tr-b:hover{background:var(--card2)}
.tr-b.on{background:var(--acc);color:#fff;border-color:var(--acc)}
.tr-root{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:10px 12px;box-shadow:var(--sh)}
.tr-n{margin:2px 0}
.tr-n.hide{display:none}
.tr-h{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:9px;transition:background .12s}
.tr-h:hover{background:var(--card2)}
.tr-x{border:0;background:transparent;cursor:pointer;font-size:12px;color:var(--dim);width:18px;height:18px;
  display:flex;align-items:center;justify-content:center;transition:transform .15s;flex:none}
.tr-n.open>.tr-h>.tr-x{transform:rotate(90deg)}
.tr-x0{width:18px;flex:none}
.tr-i{font-size:15px;line-height:1;flex:none}
.tr-t{font-weight:650;font-size:13.5px}
.tr-m{font-size:12px;color:var(--dim);margin-left:auto;white-space:nowrap;font-variant-numeric:tabular-nums}
.tr-l{text-decoration:none;color:var(--acc);font-size:13px;padding:0 3px;flex:none}
.tr-c{border:1px solid var(--line);background:#fff;border-radius:999px;padding:2px 10px;font-size:10.5px;
  color:var(--dim);cursor:pointer;white-space:nowrap;flex:none;opacity:0;transition:opacity .15s}
.tr-h:hover .tr-c,.tr-c.on{opacity:1}
.tr-c.on{background:#eafff3;border-color:#b6ecc8;color:#166534;font-weight:700}
.tr-d{font-size:12px;color:var(--dim);margin:0 0 3px 42px;line-height:1.45;max-width:820px}
.tr-k{margin-left:17px;padding-left:11px;border-left:1px dashed var(--line);display:none}
.tr-n.open>.tr-k{display:block}
.tr-n.s-fire>.tr-h{border-left:3px solid var(--neg);background:linear-gradient(90deg,#fdecec,transparent 45%)}
.tr-n.s-warn>.tr-h{border-left:3px solid var(--warn);background:linear-gradient(90deg,#fff4e5,transparent 45%)}
.tr-n.s-ok>.tr-h{border-left:3px solid transparent}
.tr-n.s-idle>.tr-h{opacity:.62}
.tr-n.d0>.tr-h .tr-t{font-size:17px;font-weight:800;letter-spacing:-.02em}
.tr-n.d0>.tr-h .tr-i{font-size:20px}
.tr-n.d0{margin:10px 0;border:1px solid var(--line);border-radius:13px;padding:6px;background:var(--card2)}
.tr-n.d1>.tr-h .tr-t{font-size:14.5px;font-weight:750}
.tr-n.touched>.tr-h .tr-t::after{content:' ✓';color:var(--pos);font-size:11px}
.tr-week{display:flex;gap:7px;flex-wrap:wrap}
.tw{background:var(--card2);border:1px solid var(--line);border-radius:11px;padding:7px 13px;text-align:center;min-width:58px}
.tw b{display:block;font-size:19px;font-weight:800}
.tw span{font-size:10.5px;color:var(--dim)}
.tr-act{display:flex;gap:2px;opacity:0;transition:opacity .15s;flex:none}
.tr-h:hover .tr-act{opacity:1}
.tr-e{border:1px solid var(--line);background:#fff;border-radius:7px;width:24px;height:24px;cursor:pointer;
  font-size:12px;line-height:1;color:var(--dim);padding:0}
.tr-e:hover{background:var(--acc);color:#fff;border-color:var(--acc)}
.tr-e.del:hover{background:var(--neg);border-color:var(--neg)}
.tr-bind{font-size:11px;color:var(--dim);margin:0 0 3px 42px}
.tr-bind code{background:var(--card2);border:1px solid var(--line);border-radius:5px;padding:0 5px;font-size:10.5px}
.tr-form{margin:0 0 4px 42px}
.tf{background:var(--card2);border:1px solid var(--acc);border-radius:12px;padding:11px;max-width:720px}
.tf-r{display:flex;gap:7px;margin-bottom:7px}
.tf input,.tf textarea{border:1px solid var(--line);border-radius:9px;padding:7px 10px;font-size:13px;
  font-family:inherit;width:100%;background:#fff}
.tf input:focus,.tf textarea:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px #2563eb1a}
.tf .tf-ico{width:62px;text-align:center;flex:none}
.tf textarea{margin-bottom:7px;resize:vertical}
.tf-b{display:flex;gap:7px}
.tr-helpbox{display:none;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:12px}
.tr-helpbox.on{display:block}
.tr-helpbox code{background:var(--card2);border:1px solid var(--line);border-radius:5px;padding:1px 6px;font-size:11.5px}
</style>
CSS; }



/* ---------- Дерево как редактируемая структура ---------- */
/**
 * Структуру дерева ведёт владелец, цифры подтягиваются сами.
 *
 * Узел хранит только то, что человек может осмысленно написать: иконку, название,
 * описание и ссылку. Живые данные приходят через `bind` — короткий адрес источника
 * (`kind:tube`, `srv:12`, `dom:tronosex.ru`, `reg:REGRU-RU`, `net:tacoloco`).
 * Поэтому переименование узла не ломает метрику, а правка описания не требует кода.
 *
 * Пустой bind — нормальная ситуация: узел-раздел, который просто группирует детей.
 */

/** Что умеет bind. Показывается в подсказке редактора. */
function bind_help(): array {
    return [
        'kind:tube'      => 'направление целиком: tube, tracker или game',
        'srv:12'         => 'сервер по id из таблицы servers',
        'dom:example.ru' => 'конкретный домен',
        'reg:REGRU-RU'   => 'домены этого регистратора в рабочем состоянии',
        'regban:GoDaddy.com, LLC' => 'домены этого регистратора, заблокированные на его стороне',
        'net:tacoloco'   => 'рекламная сетка по коду из crm_network_status',
        'src:tube'       => 'расход на закупку трафика по направлению',
        '(пусто)'        => 'узел-раздел, без метрики',
    ];
}

/** Считает метрику и состояние узла по его bind. Возвращает [state, metric]. */
function bind_resolve(string $bind, array $ctx): array {
    if ($bind === '') return ['ok', ''];
    $m = fn(float $v) => ($v < 0 ? '−$' : '$').number_format(abs($v), 2);
    $st = fn(float $p) => $p > 0 ? 'ok' : ($p < 0 ? 'fire' : 'idle');
    $d = (int)$ctx['days'];
    [$type, $arg] = array_pad(explode(':', $bind, 2), 2, '');

    switch ($type) {
        case 'kind': {
            $c = $r = 0.0; $n = 0;
            foreach ($ctx['dom'] as $v) if ($v['kind'] === $arg) { $c += $v['cost']; $r += $v['rev']; $n++; }
            $w = $arg === 'tube'    ? plural($n, 'домен', 'домена', 'доменов')
               : ($arg === 'tracker' ? plural($n, 'связка', 'связки', 'связок')
               :                       plural($n, 'игра', 'игры', 'игр'));
            return [$st($r - $c), $m($r - $c).' за '.$d.'д · '.$n.' '.$w];
        }
        case 'src': {
            $c = 0.0;
            foreach ($ctx['dom'] as $v) if ($arg === '' || $v['kind'] === $arg) $c += $v['cost'];
            return [$c > 0 ? 'ok' : 'idle', 'расход '.$m($c).' за '.$d.'д'];
        }
        case 'srv': {
            $sid = (int)$arg;
            $a = $ctx['srv'][$sid] ?? null;
            $s0 = null;
            foreach ($ctx['servers'] as $x) if ((int)$x['id'] === $sid) { $s0 = $x; break; }
            if (!$s0) return ['warn', 'сервера с таким id нет'];
            if ((int)$s0['is_enabled'] === 0) return ['idle', 'законсервирован'];
            if (is_stale($s0['last_seen_at'])) return ['warn', 'молчит больше часа'];
            if (!$a) return ['idle', 'данных за период нет'];
            $p = $a['rev'] - $a['cost'];
            return [$st($p), $m($p).' · '.count($a['doms']).' '.plural(count($a['doms']), 'домен', 'домена', 'доменов')];
        }
        case 'dom': {
            $v = $ctx['dom'][$arg] ?? null;
            if (!$v) return ['idle', 'за период не отчитывался'];
            $p = $v['rev'] - $v['cost'];
            $reg = $ctx['reg'][$arg] ?? null;
            if ($reg && $reg['verdict'] === 'dead') return ['fire', 'потерян у реестра · '.$m($p)];
            return [$st($p), $m($p).' · '.num((int)$v['visits']).' виз.'];
        }
        case 'reg': case 'regban': {
            $dead = $type === 'regban';
            $sum = 0.0; $n = 0;
            foreach ($ctx['reg'] as $dn => $rr) {
                if ((string)$rr['registrar'] !== $arg) continue;
                if ((($rr['verdict'] === 'dead')) !== $dead) continue;
                $n++;
                $v = $ctx['dom'][$dn] ?? null;
                if ($v) $sum += $v['rev'] - $v['cost'];
            }
            if ($n === 0) return ['idle', 'ни одного домена'];
            $w = plural($n, 'домен', 'домена', 'доменов');
            return $dead ? ['fire', $n.' '.$w.' потеряно'] : [$st($sum), $n.' '.$w.' · '.$m($sum)];
        }
        case 'net': {
            foreach ($ctx['nets'] as $nn) {
                if ((string)$nn['code'] !== $arg) continue;
                $on = $nn['status'] === 'connected';
                return [$on ? 'ok' : 'idle', $on ? 'принимает' : 'не подключена · '.((string)$nn['min_daily_traffic'] ?: 'требования не указаны')];
            }
            return ['warn', 'сетки с таким кодом нет'];
        }
    }
    return ['warn', 'непонятный bind: '.$bind];
}

/** Контекст с живыми данными — считается один раз на страницу. */
function tree_ctx(int $days): array {
    $db = db();
    $from = (new DateTime('-'.($days - 1).' days'))->format('Y-m-d');
    $rows = q($db, "SELECT dd.d, dd.domain, dd.server_id, dd.cost, dd.revenue, dd.visits,
                           dd.updated_at, s.is_aggregate, s.kind
                      FROM daily dd JOIN servers s ON s.id = dd.server_id WHERE dd.d >= ?", [$from]);
    $pick = [];
    foreach ($rows as $r) {
        $k = $r['d'].'|'.$r['domain'];
        $c = $pick[$k] ?? null;
        if ($c === null) { $pick[$k] = $r; continue; }
        $cmp = (int)$r['is_aggregate'] <=> (int)$c['is_aggregate'];
        if ($cmp < 0 || ($cmp === 0 && $r['updated_at'] > $c['updated_at'])) $pick[$k] = $r;
    }
    $dom = $srv = [];
    foreach ($pick as $r) {
        $d = $r['domain']; $sid = (int)$r['server_id'];
        if (!isset($dom[$d])) $dom[$d] = ['cost'=>0.0, 'rev'=>0.0, 'visits'=>0, 'kind'=>$r['kind']];
        $dom[$d]['cost'] += (float)$r['cost']; $dom[$d]['rev'] += (float)$r['revenue'];
        $dom[$d]['visits'] += (int)$r['visits'];
        if (!isset($srv[$sid])) $srv[$sid] = ['cost'=>0.0, 'rev'=>0.0, 'doms'=>[]];
        $srv[$sid]['cost'] += (float)$r['cost']; $srv[$sid]['rev'] += (float)$r['revenue'];
        $srv[$sid]['doms'][$d] = 1;
    }
    $reg = [];
    foreach (q($db, "SELECT domain, registrar, verdict FROM domain_registrar") as $r) $reg[$r['domain']] = $r;
    $nets = [];
    try { $nets = q($db, "SELECT code,title,status,min_daily_traffic FROM fapacrm.crm_network_status"); } catch (Throwable $e) {}
    return ['dom'=>$dom, 'srv'=>$srv, 'reg'=>$reg, 'nets'=>$nets,
            'servers'=>q($db, "SELECT id,code,ip,kind,is_enabled,last_seen_at FROM servers ORDER BY code"),
            'days'=>$days];
}

/** Узлы из базы, сгруппированные по родителю. */
function tree_rows(): array {
    $by = [];
    foreach (q(db(), "SELECT * FROM tree_node ORDER BY sort, id") as $r)
        $by[(int)$r['parent_id']][] = $r;
    return $by;
}

function tree_render_db(array $by, ?int $parent, array $ctx, array $touched, int $depth = 0): void {
    foreach ($by[(int)$parent] ?? [] as $n) {
        $id = (int)$n['id'];
        [$state, $metric] = bind_resolve((string)$n['bind'], $ctx);
        $has = !empty($by[$id]);
        $tc  = isset($touched['n'.$id]);
        echo '<div class="tr-n d'.min($depth, 4).' s-'.$state.($tc ? ' touched' : '').'" data-id="n'.$id.'" data-nid="'.$id.'">';
        echo   '<div class="tr-h">';
        echo     $has ? '<button class="tr-x" aria-label="раскрыть">▸</button>' : '<span class="tr-x0"></span>';
        echo     '<span class="tr-i">'.h((string)$n['ico']).'</span>';
        echo     '<span class="tr-t">'.h((string)$n['title']).'</span>';
        if ($metric !== '') echo '<span class="tr-m">'.h($metric).'</span>';
        if ((string)$n['link'] !== '') echo '<a class="tr-l" href="'.h((string)$n['link']).'" title="открыть раздел">↗</a>';
        echo     '<span class="tr-act">';
        echo       '<button class="tr-e" data-edit="'.$id.'" title="править узел">✎</button>';
        echo       '<button class="tr-e" data-add="'.$id.'" title="добавить дочерний">＋</button>';
        echo       '<button class="tr-e" data-move="'.$id.'" data-dir="up" title="выше">↑</button>';
        echo       '<button class="tr-e" data-move="'.$id.'" data-dir="down" title="ниже">↓</button>';
        echo       '<button class="tr-e del" data-del="'.$id.'" title="удалить вместе с детьми">✕</button>';
        echo     '</span>';
        echo     '<button class="tr-c'.($tc ? ' on' : '').'" data-touch="n'.$id.'" title="отметить как обработанный">'.($tc ? '✓ коснулся' : 'коснулся').'</button>';
        echo   '</div>';
        if ((string)$n['descr'] !== '') echo '<div class="tr-d">'.h((string)$n['descr']).'</div>';
        if ((string)$n['bind'] !== '') echo '<div class="tr-bind">данные: <code>'.h((string)$n['bind']).'</code></div>';
        echo   '<div class="tr-form" id="f'.$id.'"></div>';
        if ($has) { echo '<div class="tr-k">'; tree_render_db($by, $id, $ctx, $touched, $depth + 1); echo '</div>'; }
        echo '</div>';
    }
}

/** Первичное заполнение: переносим сгенерированное дерево в таблицу, дальше его правит владелец. */
function tree_seed(int $days): int {
    $db = db();
    $gen = tree_build($days);
    $n = 0;
    $ins = function(array $node, ?int $parent, int $sort) use (&$ins, $db, &$n): void {
        /* bind выводим из служебного id, который строил генератор */
        $id = (string)$node['id'];
        $bind = '';
        if (preg_match('~^(tube|tracker|game)$~', $id, $mm))                 $bind = 'kind:'.$mm[1];
        elseif (preg_match('~^(tube|tracker|game)/src/kadam$~', $id, $mm))   $bind = 'src:'.$mm[1];
        elseif (preg_match('~^(?:tube|tracker|game)/net/([a-z0-9_-]+)$~i', $id, $mm)) $bind = 'net:'.$mm[1];
        elseif (preg_match('~^tube/dom/reg/(.+)$~', $id, $mm))               $bind = 'reg:'.$mm[1];
        elseif (preg_match('~^tube/dom/ban/(.+)$~', $id, $mm))               $bind = 'regban:'.$mm[1];
        elseif (preg_match('~^tube/dom/([a-z0-9.-]+\.[a-z]{2,})$~i', $id, $mm)) $bind = 'dom:'.$mm[1];
        elseif (preg_match('~^srv/(\d+)$~', $id, $mm))                       $bind = 'srv:'.$mm[1];
        elseif (preg_match('~^srv/\d+/([a-z0-9.-]+\.[a-z]{2,})$~i', $id, $mm)) $bind = 'dom:'.$mm[1];

        $st = $db->prepare("INSERT INTO tree_node (parent_id, sort, ico, title, descr, bind, link) VALUES (?,?,?,?,?,?,?)");
        $ico = mb_substr((string)$node['ico'], 0, 8);
        $ti  = mb_substr((string)$node['title'], 0, 190);
        $de  = mb_substr((string)$node['desc'], 0, 600);
        $ln  = mb_substr((string)$node['link'], 0, 255);
        $st->bind_param('iisssss', $parent, $sort, $ico, $ti, $de, $bind, $ln);
        $st->execute();
        $mine = $db->insert_id;
        $st->close();
        $n++;
        $i = 0;
        foreach ($node['kids'] as $k) $ins($k, $mine, $i++);
    };
    $i = 0;
    foreach ($gen as $root) $ins($root, null, $i++);
    return $n;
}

function view_tree(int $days): void {
    $db = db();
    $have = (int)(q($db, "SELECT COUNT(*) c FROM tree_node")[0]['c'] ?? 0);

    echo tree_css();
    echo '<h1>🌳 Дерево <small>· структура хозяйства</small></h1>';

    if (!$have) {
        echo '<div class="card"><h2>Дерево пустое</h2>'
           . '<div class="note">Можно начать с пустого корня либо перенести структуру, собранную по данным, и дальше редактировать вручную. '
           . 'Второй вариант быстрее: каркас уже готов, требуется только правка формулировок.</div>'
           . '<form method="post" style="margin-top:12px"><input type="hidden" name="action" value="tree_seed">'
           . '<button class="tr-b on" type="submit">Заполнить из данных</button> '
           . '<button class="tr-b" type="submit" name="empty" value="1">Начать с пустого корня</button></form></div>';
        return;
    }

    $ctx = tree_ctx($days);
    $by  = tree_rows();
    $today = (new DateTime())->format('Y-m-d');
    $touched = [];
    foreach (q($db, "SELECT node FROM tree_touch WHERE d = ?", [$today]) as $r) $touched[$r['node']] = 1;

    $all = q($db, "SELECT id, bind FROM tree_node");
    $fires = 0; $warns = 0;
    foreach ($all as $r) { [$s0,] = bind_resolve((string)$r['bind'], $ctx); if ($s0==='fire') $fires++; elseif ($s0==='warn') $warns++; }
    $week = q($db, "SELECT d, COUNT(*) n FROM tree_touch WHERE d >= CURDATE() - INTERVAL 6 DAY GROUP BY d ORDER BY d");

    echo '<div class="sub">Редактирование: ✎ изменить, ＋ добавить дочерний, ↑↓ порядок, ✕ удалить. '
       . 'Метрики подставляются по полю <code>bind</code> и не зависят от названия узла. '
       . 'Дерево целиком: <a href="?view=tree&days='.(int)$days.'&format=json">JSON</a>.</div>';

    echo '<div class="kpis">';
    kpi('Узлов', num(count($all)));
    kpi('Проблемные', num($fires), $fires ? 'neg' : '');
    kpi('Требуют проверки', num($warns));
    kpi('Отмечено сегодня', '<span id="trCnt">'.num(count($touched)).'</span>', 'pos', 'из '.num(count($all)).' узлов');
    echo '</div>';

    if ($week) {
        echo '<div class="card"><h2>Отмеченные узлы по дням</h2><div class="tr-week">';
        foreach ($week as $w) echo '<div class="tw"><b>'.(int)$w['n'].'</b><span>'.h(substr((string)$w['d'], 5)).'</span></div>';
        echo '</div><div class="note">Счётчик обработанных узлов. Показывает объём проделанной работы отдельно от финансового результата, который приходит с задержкой.</div></div>';
    }

    echo '<div class="tr-tools"><input id="trQ" placeholder="поиск по дереву…">';
    echo '<button class="tr-b" id="trOpen">развернуть всё</button><button class="tr-b" id="trClose">свернуть</button>';
    echo '<button class="tr-b" id="trFire">только горящее</button>';
    echo '<button class="tr-b" id="trAddRoot">＋ корневой раздел</button>';
    echo '<button class="tr-b" id="trHelp">что такое bind</button></div>';

    echo '<div class="tr-helpbox" id="trHelpBox"><b>Поле bind — источник данных для метрики узла.</b><table class="fm-tab">';
    foreach (bind_help() as $k => $v) echo '<tr><td><code>'.h($k).'</code></td><td>'.h($v).'</td></tr>';
    echo '</table><div class="note">Для узла-раздела поле bind оставляется пустым: метрика не выводится, остаются название и описание.</div></div>';

    echo '<div class="tr-root">';
    tree_render_db($by, null, $ctx, $touched);
    echo '</div>';
    echo tree_js();
}

function tree_js(): string { return <<<'JS'
<script>
(function(){
  const root = document.querySelector('.tr-root');
  if (!root) return;

  root.querySelectorAll('.tr-n.d0, .tr-n.d1').forEach(n => n.classList.add('open'));

  root.addEventListener('click', e => {
    const b = e.target.closest('.tr-x');
    if (b) { b.closest('.tr-n').classList.toggle('open'); return; }
  });

  const $ = id => document.getElementById(id);
  $('trOpen').addEventListener('click', () => root.querySelectorAll('.tr-n').forEach(n => n.classList.add('open')));
  $('trClose').addEventListener('click', () => {
    root.querySelectorAll('.tr-n').forEach(n => n.classList.remove('open'));
    root.querySelectorAll('.tr-n.d0').forEach(n => n.classList.add('open'));
  });
  $('trHelp').addEventListener('click', () => $('trHelpBox').classList.toggle('on'));

  const fireBtn = $('trFire');
  fireBtn.addEventListener('click', () => {
    const on = fireBtn.classList.toggle('on');
    root.querySelectorAll('.tr-n').forEach(n => {
      if (!on) { n.classList.remove('hide'); return; }
      const self = n.classList.contains('s-fire'), kid = n.querySelector('.s-fire');
      n.classList.toggle('hide', !self && !kid);
      if (self || kid) n.classList.add('open');
    });
  });

  const q = $('trQ');
  q.addEventListener('input', () => {
    const v = q.value.trim().toLowerCase();
    root.querySelectorAll('.tr-n').forEach(n => {
      if (!v) { n.classList.remove('hide'); return; }
      const hay = [...n.querySelectorAll('.tr-t,.tr-d,.tr-bind')].map(x => x.textContent.toLowerCase()).join(' ');
      const hit = hay.includes(v);
      n.classList.toggle('hide', !hit);
      if (hit) { n.classList.add('open'); let p = n.parentElement.closest('.tr-n'); while (p) { p.classList.remove('hide'); p.classList.add('open'); p = p.parentElement.closest('.tr-n'); } }
    });
  });

  /* --- правка --- */
  function post(data){
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    return fetch(location.pathname, {method:'POST', body:fd}).then(r => r.json());
  }
  function esc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function closeForms(){ document.querySelectorAll('.tr-form').forEach(f => f.innerHTML = ''); }

  function form(node, mode){
    const nid  = node.dataset.nid;
    const box  = document.getElementById('f' + nid);
    if (box.innerHTML) { box.innerHTML = ''; return; }
    closeForms();
    const add  = mode === 'add';
    const ico  = add ? '•' : node.querySelector(':scope > .tr-h > .tr-i').textContent;
    const ttl  = add ? ''  : node.querySelector(':scope > .tr-h > .tr-t').textContent;
    const dsc  = add ? ''  : (node.querySelector(':scope > .tr-d')?.textContent || '');
    const bnd  = add ? ''  : (node.querySelector(':scope > .tr-bind > code')?.textContent || '');
    const lnk  = add ? ''  : (node.querySelector(':scope > .tr-h > .tr-l')?.getAttribute('href') || '');
    box.innerHTML =
      '<div class="tf"><div class="tf-r"><input class="tf-ico" maxlength="8" value="'+esc(ico)+'" placeholder="🌿">' +
      '<input class="tf-ttl" value="'+esc(ttl)+'" placeholder="название узла"></div>' +
      '<textarea class="tf-dsc" rows="2" placeholder="описание: зачем этот узел и что тут важно">'+esc(dsc)+'</textarea>' +
      '<div class="tf-r"><input class="tf-bnd" value="'+esc(bnd)+'" placeholder="bind, например dom:tronosex.ru — можно пусто">' +
      '<input class="tf-lnk" value="'+esc(lnk)+'" placeholder="ссылка, можно пусто"></div>' +
      '<div class="tf-b"><button class="tr-b on tf-save">' + (add ? 'Добавить' : 'Сохранить') + '</button>' +
      '<button class="tr-b tf-cancel">Отмена</button></div></div>';
    box.querySelector('.tf-cancel').addEventListener('click', () => box.innerHTML = '');
    box.querySelector('.tf-save').addEventListener('click', () => {
      const d = {
        action: add ? 'node_add' : 'node_save',
        ico:   box.querySelector('.tf-ico').value.trim() || '•',
        title: box.querySelector('.tf-ttl').value.trim(),
        descr: box.querySelector('.tf-dsc').value.trim(),
        bind:  box.querySelector('.tf-bnd').value.trim(),
        link:  box.querySelector('.tf-lnk').value.trim(),
      };
      if (!d.title) { box.querySelector('.tf-ttl').focus(); return; }
      if (add) d.parent = nid; else d.id = nid;
      post(d).then(() => location.reload());
    });
    box.querySelector('.tf-ttl').focus();
  }

  root.addEventListener('click', e => {
    const ed = e.target.closest('[data-edit]'), ad = e.target.closest('[data-add]');
    const mv = e.target.closest('[data-move]'), dl = e.target.closest('[data-del]');
    if (ed) { e.stopPropagation(); form(ed.closest('.tr-n'), 'edit'); return; }
    if (ad) { e.stopPropagation(); form(ad.closest('.tr-n'), 'add'); return; }
    if (mv) { e.stopPropagation(); post({action:'node_move', id:mv.dataset.move, dir:mv.dataset.dir}).then(() => location.reload()); return; }
    if (dl) {
      e.stopPropagation();
      const n = dl.closest('.tr-n');
      const kids = n.querySelectorAll('.tr-n').length;
      const name = n.querySelector(':scope > .tr-h > .tr-t').textContent;
      if (!confirm('Удалить «' + name + '»' + (kids ? ' и вложенные узлы (' + kids + ')' : '') + '?')) return;
      post({action:'node_del', id:dl.dataset.del}).then(() => location.reload());
    }
  });

  document.getElementById('trAddRoot').addEventListener('click', () => {
    const t = prompt('Название корневого раздела');
    if (!t) return;
    post({action:'node_add', parent:'', ico:'📁', title:t, descr:'', bind:'', link:''}).then(() => location.reload());
  });

  /* --- «коснулся» --- */
  const cnt = document.getElementById('trCnt');
  root.addEventListener('click', e => {
    const b = e.target.closest('.tr-c');
    if (!b) return;
    e.stopPropagation();
    const on = !b.classList.contains('on');
    b.disabled = true;
    post({action:'touch', node:b.dataset.touch, on: on ? '1' : '0'})
      .then(j => {
        b.classList.toggle('on', on);
        b.textContent = on ? '✓ коснулся' : 'коснулся';
        b.closest('.tr-n').classList.toggle('touched', on);
        if (j && typeof j.count === 'number') cnt.textContent = j.count.toLocaleString('ru');
      })
      .catch(() => {})
      .finally(() => { b.disabled = false; });
  });
})();
</script>
JS; }
