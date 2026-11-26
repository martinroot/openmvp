<?php
/**
 * Деньги · Карточка сервиса — детали по ID (финансы, домены, пинг).
 * Данные напрямую из локальной БД money (тот же хост).
 */
$svcId = (int)($_GET["id"] ?? 0);
if ($svcId <= 0) { echo '<div class="alert alert-warning">Не указан ID сервиса</div>'; return; }

/* подключение к БД money (не через money/lib.php — там конфликтует db() с core) */
{
    $__lib = file_get_contents(dirname(__DIR__) . '/money/lib.php');
    preg_match("/DB_USER='([^']*)'/", $__lib, $mu); preg_match("/DB_PASS='CHANGE_ME']*)'/", $__lib, $mp);
    $mdb = @new mysqli('localhost', $mu[1] ?? 'money', $mp[1] ?? '', 'money');
    if ($mdb->connect_error) { echo '<div class="alert alert-danger">money DB недоступна</div>'; return; }
}

$days = (int)($_GET["days"] ?? 30);
if (!in_array($days, [1, 7, 30, 90], true)) $days = 30;
$since = date("Y-m-d", strtotime("-" . ($days - 1) . " day"));

$st = $mdb->prepare("SELECT * FROM servers WHERE id=?");
$st->bind_param('i', $svcId); $st->execute();
$S = $st->get_result()->fetch_assoc();
if (!$S) { echo '<div class="alert alert-warning">Сервис #' . $svcId . ' не найден</div>'; return; }

/* привязка/имя/цвет живут в БД system (icrm) */
$mpRow = null;
{
    $r = all("SELECT tree_node_id, name_override, color, admin_url FROM money_svc_map WHERE server_id=?", 'i', [$svcId]);
    $mpRow = $r[0] ?? null;
}

$dispName = ($mpRow["name_override"] ?? "") !== "" ? (string)$mpRow["name_override"] : (string)$S["code"];
$st_on = empty($S["last_seen_at"]) ? "off" : ((time() - strtotime($S["last_seen_at"]) > 3600) ? "stale" : "on");
if (isset($S["last_ping_ok"]) && $S["last_ping_ok"] === 0) $st_on = "fail";

/* финансы за период */
$q = $mdb->prepare("SELECT COALESCE(SUM(revenue),0) rev, COALESCE(SUM(cost),0) cost, COUNT(DISTINCT domain) doms
                    FROM daily WHERE server_id=? AND d>=?");
$q->bind_param('is', $svcId, $since); $q->execute();
$fin = $q->get_result()->fetch_assoc();

/* по доменам */
$q = $mdb->prepare("SELECT domain, SUM(revenue) rev, SUM(cost) cost, SUM(clicks) clicks, SUM(visits) visits, COUNT(*) days
                    FROM daily WHERE server_id=? AND d>=?
                    GROUP BY domain ORDER BY rev DESC");
$q->bind_param('is', $svcId, $since); $q->execute();
$doms = $q->get_result()->fetch_all(MYSQLI_ASSOC);

/* meta по доменам (регistrar и пр.) */
$domMeta = [];
foreach ($doms as $d) {
    $q = $mdb->prepare("SELECT * FROM domain_meta WHERE domain=? LIMIT 1");
    $q->bind_param('s', $d["domain"]); $q->execute();
    $domMeta[$d["domain"]] = $q->get_result()->fetch_assoc();
}

/* по дням (для мини-графика) */
$q = $mdb->prepare("SELECT d, SUM(revenue) rev, SUM(cost) cost FROM daily WHERE server_id=? AND d>=? GROUP BY d ORDER BY d");
$q->bind_param('is', $svcId, $since); $q->execute();
$byDay = $q->get_result()->fetch_all(MYSQLI_ASSOC);

/* пинг: журнал ingest */
$q = $mdb->prepare("SELECT ts, src_ip, http_code, rows_in, message FROM ingest_log WHERE server_id=? ORDER BY id DESC LIMIT 10");
$q->bind_param('i', $svcId); $q->execute();
$ingests = $q->get_result()->fetch_all(MYSQLI_ASSOC);

/* ops-снимок сервера */
$q = $mdb->prepare("SELECT * FROM crm_server_ops WHERE server_id=? ORDER BY last_check_at DESC LIMIT 1");
$q->bind_param('i', $svcId); $q->execute();
$ops = $q->get_result()->fetch_assoc();

$fmt = fn($v) => "$" . number_format((float)$v, 2);
$tu = fn($v) => (float)$v > 0.00001 ? "text-success" : ((float)$v < -0.00001 ? "text-danger" : "text-body-secondary");
$colors = ["primary","success","danger","warning","info","dark","secondary"];
$hexC = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($mpRow["color"] ?? "")) ? $mpRow["color"] : null;
$clsC = $hexC ? null : (!empty($mpRow["color"]) ? "text-bg-" . $mpRow["color"] : null);
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a class="btn btn-sm btn-outline-secondary" href="?page=money_servers">← Сервисы</a>
  <?php $admUrl = trim((string)($mpRow["admin_url"] ?? "")); ?>
  <?php if ($admUrl !== ""): ?><a class="btn btn-sm btn-outline-primary" href="<?= h($admUrl) ?>" target="_blank" rel="noopener">⚙ Админка ↗</a><?php else: ?><span class="badge text-bg-secondary-subtle text-dark-emphasis">админка не задана — задай через ✎</span><?php endif; ?>
  <h1 class="h5 m-0">Деньги · <?= h($dispName) ?></h1>
  <span class="badge fw-bold <?= $hexC ? "" : $clsC ?>" style="font-size:.72rem<?= $hexC ? ";background:" . $hexC . "!important;color:" . (((0.2126*hexdec(substr($hexC,1,2))+0.7152*hexdec(substr($hexC,3,2))+0.0722*hexdec(substr($hexC,5,2)))) > 140 ? "#000!important" : "#fff!important") . "" : "" ?>"><?= h($dispName) ?></span>
  <div class="btn-group btn-group-sm" role="group">
    <?php foreach ([1=>"1 д",7=>"7 д",30=>"30 д",90=>"90 д"] as $d => $lbl): ?>
      <a class="btn<?= $d === $days ? "" : " outline-" ?>btn-secondary" href="?page=money_svc&id=<?= $svcId ?>&days=<?= $d ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php $prof = (float)$fin["rev"] - (float)$fin["cost"]; ?>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Доход</div><div class="fs-5 fw-bold text-success"><?= $fmt($fin["rev"]) ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Расход</div><div class="fs-5 fw-bold text-danger"><?= $fmt($fin["cost"]) ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Профит</div><div class="fs-5 fw-bold <?= $tu($prof) ?>"><?= $fmt($prof) ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">ROI</div><div class="fs-5 fw-bold"><?= (float)$fin["cost"] > 0 ? number_format((float)$fin["rev"] / max(0.0001, (float)$fin["cost"]), 2) . "x" : "—" ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Домены</div><div class="fs-5 fw-bold"><?= (int)$fin["doms"] ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Статус</div>
    <div class="fs-5"><?= $st_on === "on" ? '<span class="badge text-bg-success">на связи</span>' : ($st_on === "stale" ? '<span class="badge text-bg-warning">молчит &gt;1ч</span>' : ($st_on === "fail" ? '<span class="badge text-bg-danger">endpoint не отвечает</span>' : '<span class="badge text-bg-secondary">нет отчётов</span>')) ?></div>
  </div></div></div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-8">
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Финансы по доменам (<?= $days ?> д)</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-body-secondary"><th>Домен</th><th class="text-end">Доход</th><th class="text-end">Расход</th><th class="text-end">Профит</th><th class="text-end">ROI</th><th class="text-end">Клики</th><th class="text-end">Визиты</th><th class="text-end">Дней</th></tr></thead>
          <tbody>
          <?php foreach ($doms as $d): $p = (float)$d["rev"] - (float)$d["cost"]; ?>
            <tr>
              <td class="fw-semibold"><?= $d["domain"] !== "" ? "<code>" . h($d["domain"]) . "</code>" : "РК / кампании" ?>
                <?php $dm = $domMeta[$d["domain"]] ?? null; if ($dm && !empty($dm["registrar"])): ?><span class="badge text-bg-light text-body-secondary" style="font-size:.65rem"><?= h(mb_strimwidth((string)$dm["registrar"], 0, 16)) ?></span><?php endif; ?>
              </td>
              <td class="text-end text-success fw-semibold"><?= $fmt($d["rev"]) ?></td>
              <td class="text-end text-danger"><?= $fmt($d["cost"]) ?></td>
              <td class="text-end fw-bold <?= $tu($p) ?>"><?= $fmt($p) ?></td>
              <td class="text-end text-body-secondary"><?= (float)$d["cost"] > 0 ? number_format((float)$d["rev"] / max(0.0001, (float)$d["cost"]), 2) . "x" : "—" ?></td>
              <td class="text-end"><?= number_format((float)$d["clicks"]) ?></td>
              <td class="text-end"><?= number_format((float)$d["visits"]) ?></td>
              <td class="text-end text-body-secondary"><?= (int)$d["days"] ?></td>
            </tr>
          <?php endforeach; if (!$doms): ?><tr><td colspan="8" class="text-body-secondary text-center py-3">Нет данных за период</td></tr><?php endif; ?>
          </tbody>
          <tfoot class="table-light"><tr>
            <td class="fw-bold">Итого</td>
            <td class="text-end fw-bold text-success"><?= $fmt($fin["rev"]) ?></td>
            <td class="text-end fw-bold text-danger"><?= $fmt($fin["cost"]) ?></td>
            <td class="text-end fw-bold <?= $tu($prof) ?>"><?= $fmt($prof) ?></td>
            <td colspan="4"></td>
          </tr></tfoot>
        </table>
      </div>
    </div>


    <?php /* РК — кампании (campaigns_daily) */ ?>
    <?php
      $cq = $mdb->prepare("SELECT campaign_id, MAX(campaign) campaign, '' domain, SUM(cost) cost, SUM(clicks) clicks, SUM(revenue) revenue, SUM(leads) leads, MAX(d) last_d
                          FROM campaigns_daily WHERE server_id=? AND d>=DATE_SUB(CURDATE(), INTERVAL ? DAY)
                          GROUP BY campaign_id HAVING SUM(cost)>0 OR SUM(clicks)>0 OR SUM(revenue)>0 ORDER BY cost DESC");
      $campRows = [];
      if ($cq) { $cq->bind_param('ii', $svcId, $days); $cq->execute(); $campRes = $cq->get_result();
        while ($cr = $campRes->fetch_assoc()) $campRows[] = $cr; }
    ?>
    <?php if ($campRows): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">РК — кампании (<?= $days ?> д)</div>
      <div class="table-responsive" style="max-height:420px">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-body-secondary"><th>Кампания</th><th>Домен</th><th class="text-end">Доход</th><th class="text-end">Расход</th><th class="text-end">Профит</th><th class="text-end">Клики</th><th class="text-end">CPC</th><th class="text-end">Лиды</th><th class="text-end">Последний день</th></tr></thead>
          <tbody>
          <?php $campTotal = 0; foreach ($campRows as $cr):
            $cst = (float)$cr['cost']; $campTotal += $cst;
            $cpc = $cst > 0 && (int)$cr['clicks'] > 0 ? $cst / (int)$cr['clicks'] : 0; ?>
          <tr>
            <td class="fw-semibold"><?= h($cr["campaign"]) ?></td>
            <td class="small text-body-secondary"><?= h($cr["domain"]) ?></td>
            <td class="text-end <?= $tu((float)$cr["revenue"] - $cst) ?>"><?= number_format((float)$cr["revenue"], 2) ?></td>
            <td class="text-end"><?= number_format($cst, 2) ?></td>
            <td class="text-end fw-bold <?= $tu((float)$cr["revenue"] - $cst) ?>"><?= number_format((float)$cr["revenue"] - $cst, 2) ?></td>
            <td class="text-end"><?= number_format((int)$cr["clicks"]) ?></td>
            <td class="text-end"><?= $cpc > 0 ? sprintf("%.6f", $cpc) : "—" ?></td>
            <td class="text-end"><?= number_format((int)($cr["leads"] ?? 0)) ?></td>
            <td class="text-end small text-body-secondary"><?= h($cr["last_d"]) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="table-light fw-bold"><td>Итого</td><td></td><td class="text-end"><?= number_format(array_sum(array_column($campRows, "revenue")), 2) ?></td><td class="text-end"><?= number_format($campTotal, 2) ?></td><td class="text-end <?= $tu(array_sum(array_column($campRows, "revenue")) - $campTotal) ?>"><?= number_format(array_sum(array_column($campRows, "revenue")) - $campTotal, 2) ?></td><td class="text-end"><?= number_format(array_sum(array_column($campRows, "clicks"))) ?></td><td colspan="3"></td></tr></tfoot>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php /* по дням */ ?>
    <div class="card shadow-sm">
      <div class="card-header py-2 small fw-bold">По дням (<?= $days ?> д)</div>
      <div class="table-responsive" style="max-height:340px">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-body-secondary"><th>Дата</th><th class="text-end">Доход</th><th class="text-end">Расход</th><th class="text-end">Профит</th></tr></thead>
          <tbody>
          <?php foreach ($byDay as $r): $p = (float)$r["rev"] - (float)$r["cost"]; $isT = $r["d"] === date("Y-m-d"); ?>
            <tr<?= $isT ? ' class="table-success"' : '' ?>>
              <td class="fw-semibold"><?= h($r["d"]) ?></td>
              <td class="text-end text-success"><?= $fmt($r["rev"]) ?></td>
              <td class="text-end text-danger"><?= $fmt($r["cost"]) ?></td>
              <td class="text-end fw-bold <?= $tu($p) ?>"><?= $fmt($p) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <?php /* сервер */ ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Сервер</div>
      <div class="card-body py-2" style="font-size:.85rem">
        <div class="d-flex justify-content-between"><span class="text-body-secondary">IP</span><code class="fw-semibold"><?= h($S["ip"]) ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Код (X-Server)</span><code><?= h($S["code"]) ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Last seen</span><span><?= $S["last_seen_at"] ? h($S["last_seen_at"]) . " UTC" : "—" ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Пинг</span><span class="<?= ($S["last_ping_ok"] ?? 1) === 0 ? "text-danger fw-bold" : "text-success" ?>"><?= isset($S["last_ping_ok"]) ? ($S["last_ping_ok"] == 1 ? "OK" : "FAIL") : "—" ?><?= !empty($S["last_ping_at"]) ? " · " . h(substr((string)$S["last_ping_at"], 5, 11)) : "" ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Last IP</span><code><?= h($S["last_ip"] ?: "—") ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Endpoint</span><span class="text-truncate ms-2" style="max-width:60%"><?= $S["check_url"] ? '<code class="small">' . h($S["check_url"]) . '</code>' : '<span class="text-body-secondary">—</span>' ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">В учёте</span><span><?= $S["is_enabled"] ? "да" : "нет" ?></span></div>
      </div>
    </div>

    <?php if ($ops): /* ops-снимок */ ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Состояние сервера <span class="text-body-secondary fw-normal">(<?= h($ops["last_check_at"] ?? "") ?>)</span></div>
      <div class="card-body py-2" style="font-size:.85rem">
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Hostname</span><span><?= h((string)$ops["hostname"]) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">OS</span><span class="text-truncate ms-2"><?= h(mb_strimwidth((string)$ops["os"], 0, 30)) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Uptime</span><span><?= h((string)$ops["uptime"]) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Load</span><code><?= h((string)$ops["load_avg"]) ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">RAM</span><code><?= h((string)$ops["mem"]) ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Disk</span><code><?= h((string)$ops["disk"]) ?></code></div>
        <div class="d-flex justify-content-between"><span class="text-body-secondary">Sites</span><span><?= (int)$ops["sites_n"] ?></span></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-header py-2 small fw-bold">Пинг / отчёты (последние 10)</div>
      <div class="table-responsive" style="max-height:320px">
        <table class="table table-sm align-middle mb-0" style="font-size:.8rem">
          <thead><tr class="small text-body-secondary"><th>Время</th><th>Source</th><th>HTTP</th><th class="text-end">Rows</th><th>Msg</th></tr></thead>
          <tbody>
          <?php foreach ($ingests as $ig): ?>
            <tr>
              <td class="text-body-secondary"><?= h(mb_strimwidth((string)$ig["ts"], 5, 14, "")) ?></td>
              <td><code><?= h($ig["src_ip"]) ?></code></td>
              <td><span class="badge <?= (int)$ig["http_code"] === 200 ? "text-bg-success" : "text-bg-danger" ?>" style="font-size:.65rem"><?= (int)$ig["http_code"] ?></span></td>
              <td class="text-end"><?= (int)$ig["rows_in"] ?></td>
              <td class="text-body-secondary text-truncate" style="max-width:140px" title="<?= h((string)$ig["message"]) ?>"><?= h(mb_strimwidth((string)$ig["message"], 0, 30, "…")) ?></td>
            </tr>
          <?php endforeach; if (!$ingests): ?><tr><td colspan="5" class="text-body-secondary text-center py-3">Пока нет отчётов</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
