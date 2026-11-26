<?php
/**
 * Деньги — живые данные из локального money-центра (/icrm/money, БД money) → Bootstrap-карточки.
 * Сервисы идентифицируются по IP; переименование/добавление/привязка к дереву — через админ-API.
 */
$pg   = $_GET["page"] ?? "money_days";
$tblMode = ($pg === "money_table");
$tab  = ($pg === "money_servers" || $tblMode || ($_GET["tab"] ?? "") === "servers") ? "servers" : "days";
$self = $tblMode ? "money_table" : ($tab === "servers" ? "money_servers" : "money_days");
$days = (int)($_GET["days"] ?? 1);
if (!in_array($days, [1,7,30,90], true)) $days = 1;

$MONEY = "https://wifepornhub.ru/icrm/money/";
$remote = $MONEY . "?view=" . $tab . "&days=" . $days . "&format=json";
$cf = sys_get_temp_dir() . "/money_json_" . md5($remote) . ".json";
if (!is_file($cf) || time() - filemtime($cf) > 60) {
    $ctx = stream_context_create(["http" => ["timeout" => 12, "header" => "User-Agent: icrm-embed/1.0\r\n"]]);
    $raw = @file_get_contents($remote, false, $ctx);
    if ($raw !== false) file_put_contents($cf, $raw);
} else $raw = file_get_contents($cf);

$J = $raw ? json_decode($raw, true) : null;
$err = (!$J || empty($J["ok"])) ? "Не удалось получить данные из money-центра" : null;
$T = $J["total"] ?? [];
$fmt = fn($v) => "$" . number_format((float)$v, 2);
$sfmt = fn($v) => ($v !== null ? number_format((float)$v, 2) . "x" : "—");
$tu = fn($v) => (float)$v > 0.00001 ? "text-success" : ((float)$v < -0.00001 ? "text-danger" : "text-body-secondary");

/* карта привязок и локальных имён */
$map = [];
foreach (all("SELECT server_id, tree_node_id, name_override, color FROM money_svc_map", '') as $m) {
    $map[(int)$m["server_id"]] = $m;
}
/* узлы дерева для привязки */
$treeNodes = all("SELECT id, title, parent_id, kind FROM projects ORDER BY COALESCE(parent_id,0), id", '');

/* спарклайн SVG */
$spark = function(array $series, int $w = 200, int $h = 28): string {
    if (count($series) < 2) return "";
    $min = min($series); $max = max($series); $rng = ($max - $min) ?: 1;
    $pts = []; $n = count($series);
    foreach ($series as $i => $v) $pts[] = round($i / ($n - 1) * $w, 1) . "," . round($h - ($v - $min) / $rng * ($h - 4) - 2, 1);
    $zeroY = $h - (0 - $min) / $rng * ($h - 4) - 2;
    return "<svg viewBox=\"0 0 $w $h\" preserveAspectRatio=\"none\" style=\"width:100%;height:1.75rem;display:block\">"
         . ($zeroY >= 0 && $zeroY <= $h ? "<line x1=\"0\" y1=\"".round($zeroY,1)."\" x2=\"$w\" y2=\"".round($zeroY,1)."\" stroke=\"var(--bs-secondary)\" stroke-width=\"1\" stroke-dasharray=\"3,3\" opacity=\".4\"/>" : "")
         . "<polyline points=\"" . implode(" ", $pts) . "\" fill=\"none\" stroke=\"var(--bs-primary)\" stroke-width=\"2\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/></svg>";
};

$daysLabels = [1=>"1 д",7=>"7 д",30=>"30 д",90=>"90 д"];
?>
<script src="/icrm/money/vendor/chart.umd.min.js" defer></script>
<script>
function moneyPost(fd, btn) {
  btn.disabled = true;
  fetch("?page=<?= $self ?>", {method: "POST", body: fd})
    .then(r => r.json()).then(j => {
      if (j && j.ok) { location.reload(); return; }
      alert("Ошибка: " + (j.error || "неизвестно"));
      btn.disabled = false;
    }).catch(() => { alert("Сеть: запрос не прошёл"); btn.disabled = false; });
}
function moneyEditTile(el) {
  const d = el.dataset;
  const m = document.getElementById('moneyEditModal');
  m.querySelector('[name=id]').value = d.id;
  m.querySelector('#meTitle').textContent = d.name;
  m.querySelector('#meOverride').value = d.override || d.code;
  m.querySelector('#meOldCode').value = d.code;
  m.querySelector('#meNode').value = d.node || "";
  m.querySelector('#meEnabled').checked = d.enabled === "1";
  m.querySelector('#meCheck').value = d.check || "";
  m.querySelector('#meIp').value = d.ip || "";
  m.querySelector('#meColor').value = d.color || "";
  m.querySelector('#meAdmin').value = d.admin || "";
  const isHex = /^#[0-9a-fA-F]{6}$/.test(d.color || "");
  m.querySelectorAll('#meColors button').forEach(b => {
    b.classList.toggle('border-4', !isHex && b.dataset.color === (d.color || ""));
    b.classList.toggle('border-white', !isHex && b.dataset.color === (d.color || ""));
  });
  if (isHex) { m.querySelector('#meColorHex').value = d.color; m.querySelector('#meColorHexLbl').textContent = d.color; }
  else { m.querySelector('#meColorHexLbl').textContent = ""; }
  new bootstrap.Modal(m).show();
}
window.moneyChartInit = function(){
  const cv = document.getElementById('moneyDaysChart');
  const dataEl = document.getElementById('moneyDaysData');
  if (!cv || !dataEl || typeof Chart === 'undefined') return;
  const rows = JSON.parse(dataEl.textContent);
  new Chart(cv, {
    type: 'line',
    data: {
      labels: rows.map(r => r.d),
      datasets: [
        {label: 'Доход', data: rows.map(r => r.rev), borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.08)', fill: true, tension: .3, borderWidth: 2, pointRadius: 2},
        {label: 'Расход', data: rows.map(r => r.cost), borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.06)', fill: true, tension: .3, borderWidth: 2, pointRadius: 2},
        {label: 'Профит', data: rows.map(r => r.profit), borderColor: '#0d6efd', tension: .3, borderWidth: 2, pointRadius: 2},
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: {intersect: false, mode: 'index'},
      plugins: {legend: {position: 'bottom'}},
      scales: {y: {ticks: {callback: v => '$' + v}}}
    }
  });
};
document.addEventListener('DOMContentLoaded', moneyChartInit);
document.addEventListener('DOMContentLoaded', function(){
  const tbl = document.getElementById('moneySvcTable');
  if (!tbl) return;
  const num = v => { const n = parseFloat(String(v).replace(/[$\s]/g, '')); return isNaN(n) ? -1e15 : n; };
  tbl.querySelectorAll('thead th[data-sort]').forEach(th => {
    th.addEventListener('click', () => {
      const key = th.dataset.sort;
      const tb = tbl.querySelector('tbody');
      const rows = [...tb.rows];
      const asc = th.dataset.dir !== 'asc';
      tbl.querySelectorAll('thead th[data-sort]').forEach(o => o.removeAttribute('data-dir'));
      th.dataset.dir = asc ? 'asc' : 'desc';
      rows.sort((a, b) => {
        const ia = [...th.parentNode.children].indexOf(th);
        const av = a.children[ia].innerText.trim(), bv = b.children[ia].innerText.trim();
        const c = (key === 'code' || key === 'ip' || key === 'last_seen') ? av.localeCompare(bv) : (num(av) - num(bv));
        return asc ? c : -c;
      });
      rows.forEach(r => tb.appendChild(r));
    });
  });
});
</script>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 m-0"><?= $tblMode ? "Деньги · Сервисы-таблица" : ($tab === "servers" ? "Деньги · IP / Сервисы" : "Деньги · по дням") ?></h1>
  <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Тип отчёта">
    <a class="btn<?= $tab === "days" ? "" : " outline-" ?>btn-primary" href="?page=money_days&days=<?= $days ?>">По дням</a>
    <a class="btn<?= $tab === "servers" ? "" : " outline-" ?>btn-primary" href="?page=money_servers&days=<?= $days ?>">IP / Сервисы</a>
    <a class="btn<?= $tblMode ? "" : " outline-" ?>btn-primary" href="?page=money_table&days=<?= $days ?>">Таблица</a>
  </div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Период">
    <?php foreach ($daysLabels as $d => $lbl): ?>
      <a class="btn<?= $d === $days ? "" : " outline-" ?>btn-secondary" href="?page=<?= $self ?>&days=<?= $d ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($tab === "servers"): ?>
    <button class="btn btn-outline-primary btn-sm ms-auto" type="submit" form="moneyPingForm">↻ Опросить</button>
    <form id="moneyPingForm" class="d-none" onsubmit="event.preventDefault(); moneyPost(new FormData(this), this.querySelector('[type=submit]'))"><input type="hidden" name="action" value="svc_ping"></form>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#moneyAddModal">+ Сервис</button>
  <?php endif; ?>
</div>

<?php if ($err): ?>
  <div class="alert alert-warning" role="alert"><?= h($err) ?></div>
<?php elseif ($tab === "servers"): ?>
  <?php if ($tblMode): /* сводная таблица сервисов */ ?>
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" id="moneySvcTable">
        <thead><tr class="small text-body-secondary">
          <th data-sort="code" style="cursor:pointer">Сервис</th>
          <th data-sort="ip" style="cursor:pointer">IP</th>
          <th>Тип</th>
          <th>Статус</th>
          <th class="text-end" data-sort="rev" style="cursor:pointer">Доход</th>
          <th class="text-end" data-sort="cost" style="cursor:pointer">Расход</th>
          <th class="text-end" data-sort="profit" style="cursor:pointer">Профит</th>
          <th class="text-end" data-sort="roi" style="cursor:pointer">ROI</th>
          <th class="text-end" data-sort="domains" style="cursor:pointer">Домены</th>
          <th class="text-end" data-sort="visits" style="cursor:pointer">Визиты</th>
          <th class="text-end" data-sort="clicks" style="cursor:pointer">Клики</th>
          <th>Endpoint</th>
          <th>Задача</th>
          <th data-sort="last_seen" style="cursor:pointer">Отчёт</th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach (($J["servers"] ?? []) as $sv):
          $sid = (int)($sv["id"] ?? 0);
          $mp = $map[$sid] ?? null;
          $dispName = ($mp["name_override"] ?? "") !== "" && $mp["name_override"] !== null ? $mp["name_override"] : (string)($sv["code"] ?? "—");
          $st = empty($sv["last_seen"]) ? "off" : ((time() - strtotime($sv["last_seen"] . " UTC") > 3600) ? "stale" : "on");
if (($sv["st"] ?? "") === "fail") $st = "fail";
          $parked = empty($sv["enabled"]);
          $nodeName = null;
          if (!empty($mp["tree_node_id"])) {
              $nd = one("SELECT title FROM projects WHERE id=?", 'i', [(int)$mp["tree_node_id"]]);
              $nodeName = $nd["title"] ?? null;
          }
          $tagPalette = ["tracker" => "text-bg-danger", "трекер" => "text-bg-danger", "fabra" => "text-bg-primary", "cpa" => "text-bg-primary", "game" => "text-bg-success", "игры" => "text-bg-success", "adult1" => "text-bg-warning", "adult2" => "text-bg-info", "adult3" => "text-bg-dark", "wintask" => "text-bg-secondary"];
          $hexT = $mp["color"] ?? ""; $isHexT = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$hexT);
          if ($isHexT) { $rT=hexdec(substr($hexT,1,2)); $gT=hexdec(substr($hexT,3,2)); $bT=hexdec(substr($hexT,5,2)); $txtT = (0.2126*$rT+0.7152*$gT+0.0722*$bT) > 140 ? "text-dark" : "text-white"; $badgeCls = 'badge fw-bold ' . $txtT; $badgeStyle = 'background:' . $hexT . ' !important'; }
          elseif (!empty($hexT)) { $badgeCls = 'badge fw-bold text-bg-' . $hexT; $badgeStyle = ''; }
          else { $bgT = $tagPalette[mb_strtolower(trim((string)$dispName))] ?? null; if ($bgT === null) { $palT = ["text-bg-primary","text-bg-success","text-bg-danger","text-bg-warning","text-bg-info","text-bg-dark","text-bg-secondary"]; $bgT = $palT[abs(crc32(mb_strtolower((string)$dispName))) % count($palT)]; } $badgeCls = 'badge fw-bold ' . $bgT; $badgeStyle = ''; }
        ?>
          <tr<?= $parked ? ' class="opacity-50"' : '' ?>>
            <td><a class="<?= $badgeCls ?> text-decoration-none"<?= $badgeStyle !== "" ? " style=\"" . $badgeStyle . "\"" : "" ?> href="?page=money_svc&id=<?= $sid ?>"><?= h($dispName) ?></a></td>
            <td><code class="fw-semibold"><?= h($sv["ip"] ?? "") ?></code><span class="d-inline-block rounded-circle <?= $st === "on" ? "bg-success" : ($st === "fail" ? "bg-danger" : ($st === "stale" ? "bg-warning" : "bg-secondary")) ?>" style="width:.5rem;height:.5rem;margin:0 0 .08rem .3rem"></span></td>
            <td class="small text-body-secondary"><?= $sv["kind"] === "tracker" ? "трекер" : ($sv["kind"] === "game" ? "игры" : ($sv["kind"] === "win" ? "win" : ($sv["kind"] === "edge" ? "edge" : "туб"))) ?></td>
            <td><?= $st === "on" ? '<span class="badge text-bg-success">на связи</span>' : ($st === "fail" ? '<span class="badge text-bg-danger">недоступен</span>' : ($st === "stale" ? '<span class="badge text-bg-warning">молчит</span>' : '<span class="badge text-bg-secondary">нет</span>')) ?></td>
            <td class="text-end text-success fw-semibold" style="font-variant-numeric:tabular-nums"><?= $parked ? "—" : $fmt($sv["rev"]) ?></td>
            <td class="text-end text-danger fw-semibold" style="font-variant-numeric:tabular-nums"><?= $parked ? "—" : $fmt($sv["cost"]) ?></td>
            <td class="text-end fw-bold <?= $parked ? "text-body-secondary" : $tu($sv["profit"]) ?>" style="font-variant-numeric:tabular-nums"><?= $parked ? "—" : $fmt($sv["profit"]) ?></td>
            <td class="text-end text-body-secondary"><?= $parked ? "—" : $sfmt($sv["roi"]) ?></td>
            <td class="text-end"><?= (int)($sv["domains"] ?? 0) ?></td>
            <td class="text-end"><?= number_format((float)($sv["visits"] ?? 0)) ?></td>
            <td class="text-end"><?= number_format((float)($sv["clicks"] ?? 0)) ?></td>
            <td class="small"><?= empty($sv["check_url"]) ? '<span class="text-body-secondary">—</span>' : '<code class="small">' . h(mb_strimwidth((string)$sv["check_url"], 0, 28, "…")) . '</code>' ?></td>
            <td class="small"><?= !empty($mp["tree_node_id"]) ? '<a href="?page=node&id=' . (int)$mp["tree_node_id"] . '">' . h((string)$nodeName) . '</a>' : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="small text-body-secondary"><?= !empty($sv["last_seen"]) ? h(mb_strimwidth((string)$sv["last_seen"], 5, 11, "")) : "—" ?></td>
            <td><button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.7rem" onclick="moneyEditTile(this)"
                        data-id="<?= $sid ?>" data-code="<?= h((string)($sv["code"] ?? "")) ?>" data-name="<?= h($dispName) ?>"
                        data-override="<?= h((string)($mp["name_override"] ?? "")) ?>" data-node="<?= (int)($mp["tree_node_id"] ?? "") ?>"
                        data-check="<?= h((string)($sv["check_url"] ?? "")) ?>"
                        data-kind="<?= h((string)($sv["kind"] ?? "tube")) ?>"
                        data-ip="<?= h((string)($sv["ip"] ?? "")) ?>"
                        data-color="<?= h((string)($mp["color"] ?? "")) ?>"
                        data-admin="<?= h((string)($mp["admin_url"] ?? "")) ?>"
                        data-enabled="<?= (int)!$parked ?>">✎</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="fw-bold">Итого (<?= count((array)($J["servers"] ?? [])) ?>)</td><td></td><td></td><td></td>
            <td class="text-end fw-bold text-success"><?= $fmt($T["rev"] ?? 0) ?></td>
            <td class="text-end fw-bold text-danger"><?= $fmt($T["cost"] ?? 0) ?></td>
            <td class="text-end fw-bold <?= $tu($T["profit"] ?? 0) ?>"><?= $fmt($T["profit"] ?? 0) ?></td>
            <td class="text-end text-body-secondary"><?= (($T["cost"] ?? 0) > 0) ? number_format((float)($T["rev"] ?? 0) / max(0.0001, (float)$T["cost"]), 2) . "x" : "—" ?></td>
            <td colspan="7"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <div class="text-body-secondary small mt-2">Клик по заголовку — сортировка. ✎ — редактирование сервиса (название, цвет, IP, endpoint, учёт, привязка).</div>
  <?php else: ?>
  <div class="row g-2">
    <?php foreach (($J["servers"] ?? []) as $sv):
      $sid = (int)($sv["id"] ?? 0);
      $mp = $map[$sid] ?? null;
      $dispName = ($mp["name_override"] ?? "") !== "" && $mp["name_override"] !== null ? $mp["name_override"] : (string)($sv["code"] ?? "—");
      $st = empty($sv["last_seen"]) ? "off" : ((time() - strtotime($sv["last_seen"] . " UTC") > 3600) ? "stale" : "on");
if (($sv["st"] ?? "") === "fail") $st = "fail";
      $parked = empty($sv["enabled"]);
      $kindLbl = $sv["kind"] === "tracker" ? "трекер" : ($sv["kind"] === "game" ? "игры" : ($sv["kind"] === "win" ? "win" : ($sv["kind"] === "edge" ? "edge" : "туб")));
      $nodeName = null;
      if (!empty($mp["tree_node_id"])) {
          $nd = one("SELECT title FROM projects WHERE id=?", 'i', [(int)$mp["tree_node_id"]]);
          $nodeName = $nd["title"] ?? null;
      }
    ?>
      <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
        <div class="card h-100 shadow-sm<?= $parked ? " opacity-50" : "" ?> position-relative" style="font-size:.85rem">
          <div class="card-body p-2 d-flex flex-column gap-1">
            <div class="d-flex justify-content-between align-items-center">
              <code class="fw-semibold"><?= h($sv["ip"] ?? "") ?><span class="d-inline-block rounded-circle <?= $st === "on" ? "bg-success" : ($st === "fail" ? "bg-danger" : ($st === "stale" ? "bg-warning" : "bg-secondary")) ?>" style="width:.5rem;height:.5rem;margin:0 0 .08rem .3rem"></span></code>
              <div class="d-flex gap-1">
                <?php if (!empty($mp["tree_node_id"])): ?>
                  <a href="?page=node&id=<?= (int)$mp["tree_node_id"] ?>" class="badge text-bg-light text-decoration-none" title="Задача: <?= h((string)$nodeName) ?>">⛓</a>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.7rem" onclick="moneyEditTile(this)"
                        data-id="<?= $sid ?>" data-code="<?= h((string)($sv["code"] ?? "")) ?>" data-name="<?= h($dispName) ?>"
                        data-override="<?= h((string)($mp["name_override"] ?? "")) ?>" data-node="<?= (int)($mp["tree_node_id"] ?? "") ?>"
                        data-check="<?= h((string)($sv["check_url"] ?? "")) ?>"
                        data-kind="<?= h((string)($sv["kind"] ?? "tube")) ?>"
                        data-color="<?= h((string)($mp["color"] ?? "")) ?>"
                        data-ip="<?= h((string)($sv["ip"] ?? "")) ?>"
                        data-admin="<?= h((string)($mp["admin_url"] ?? "")) ?>"
                        data-enabled="<?= (int)!$parked ?>">✎</button>
              </div>
            </div>
            <?php
            /* Тег = название сервиса (то, что введено в редактировании). Цвет — стабильный по имени. */
            $tagLbl = $dispName;
            $tagPalette = [
                "tracker" => "text-bg-danger", "трекер" => "text-bg-danger",
                "fabra" => "text-bg-primary", "cpa" => "text-bg-primary",
                "game" => "text-bg-success", "игры" => "text-bg-success",
                "adult1" => "text-bg-warning", "adult2" => "text-bg-info", "adult3" => "text-bg-dark",
                "wintask" => "text-bg-secondary",
            ];
            $hexpick = null;
            if (!empty($mp["color"]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$mp["color"])) {
                $hexpick = (string)$mp["color"];
                $r = hexdec(substr($hexpick, 1, 2)); $g = hexdec(substr($hexpick, 3, 2)); $b = hexdec(substr($hexpick, 5, 2));
                $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
                $hexTxt = $lum > 140 ? "#000" : "#fff";
            } elseif (!empty($mp["color"])) {
                $bg = "text-bg-" . $mp["color"];
            }
            if ($bg === null) $bg = $tagPalette[mb_strtolower(trim($tagLbl))] ?? null;
            if ($bg === null) {
                $pal = ["text-bg-primary", "text-bg-success", "text-bg-danger", "text-bg-warning", "text-bg-info", "text-bg-dark", "text-bg-secondary"];
                $bg = $pal[abs(crc32(mb_strtolower($tagLbl))) % count($pal)];
            }
            ?>
            <?php if ($hexpick): ?><a class="badge fw-bold align-self-start text-decoration-none" style="font-size:.72rem;background:<?= $hexpick ?> !important;color:<?= $hexTxt ?> !important" href="?page=money_svc&id=<?= $sid ?>"><?= h($tagLbl) ?></a>
            <?php else: ?><a class="badge fw-bold <?= $bg ?> align-self-start text-decoration-none" style="font-size:.72rem" href="?page=money_svc&id=<?= $sid ?>"><?= h($tagLbl) ?></a><?php endif; ?>
            <div class="fs-5 fw-bold <?= $parked ? "text-body-secondary" : $tu($sv["profit"]) ?>" style="font-variant-numeric:tabular-nums"><?= $parked ? "—" : $fmt($sv["profit"]) ?></div>
            <?php if (!$parked): ?><div><?= $spark(array_values((array)$sv["series"])) ?></div><?php endif; ?>
            <div class="d-flex justify-content-between" style="font-size:.75rem">
              <span>дох <span class="fw-semibold"><?= $fmt($sv["rev"]) ?></span></span>
              <span>рас <span class="fw-semibold text-danger"><?= $fmt($sv["cost"]) ?></span></span>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="text-body-secondary small mt-2">Тег = название сервиса, цвет — по названию (каждому свой). Индикатор у IP: 🟢 на связи &lt;1ч · 🟡 молчит &gt;1ч · ⚪ нет отчётов. ✎ — редактирование.</div>

  <!-- Модал: редактирование сервиса -->
  <div class="modal fade" id="moneyEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form onsubmit="event.preventDefault(); moneySaveTile(this)">
          <div class="modal-header py-2"><h5 class="modal-title fs-6" id="meTitle">Сервис</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" name="id">
            <input type="hidden" name="action" value="svc_link">
            <input type="hidden" id="meOldCode">
            <div class="mb-2"><label class="form-label small mb-1">Название (= тип, цвет меняется по названию)</label><input class="form-control form-control-sm" name="name_override" id="meOverride" maxlength="128"></div>
            <div class="mb-2"><label class="form-label small mb-1">Привязка к узлу дерева задач</label>
              <select class="form-select form-select-sm" name="tree_node_id" id="meNode">
                <option value="">— не привязан —</option>
                <?php foreach ($treeNodes as $tn): ?>
                  <option value="<?= (int)$tn["id"] ?>"><?= h((string)$tn["title"]) ?> [<?= $tn["kind"] ?> #<?= (int)$tn["id"] ?>]</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="meEnabled">
              <label class="form-check-label small" for="meEnabled">В учёте (enabled)</label>
            </div>
            <hr class="my-2">
            <div class="mb-2"><label class="form-label small mb-1">Цвет тега</label>
              <div class="d-flex gap-1 flex-wrap" id="meColors">
                <input type="hidden" id="meColor" value="">
                <?php foreach (["primary" => "синий", "success" => "зелёный", "danger" => "красный", "warning" => "жёлтый", "info" => "голубой", "dark" => "тёмный", "secondary" => "серый"] as $c => $lbl): ?>
                  <button type="button" class="btn btn-sm text-bg-<?= $c ?>" style="width:2rem;height:1.6rem" data-color="<?= $c ?>" title="<?= $lbl ?>" onclick="document.getElementById('meColor').value='<?= $c ?>'; this.closest('#meColors').querySelectorAll('button').forEach(b=>b.classList.remove('border-4','border-white'))"></button>
                <?php endforeach; ?>
              </div>
              <div class="form-text small mb-0"><button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="height:1.6rem" title="сбросить (цвет по названию)" onclick="document.getElementById('meColor').value=''; document.getElementById('meColorHexLbl').textContent=''; this.closest('#meColors').querySelectorAll('button').forEach(b=>b.classList.remove('border-4','border-white'))">✕</button>
              <input type="color" class="form-control form-control-color form-control-sm" id="meColorHex" value="#6c757d" title="свой цвет" oninput="document.getElementById('meColor').value=this.value; document.getElementById('meColorHexLbl').textContent=this.value; this.closest('#meColors').querySelectorAll('button').forEach(b=>b.classList.remove('border-4','border-white'))">
              <span class="align-self-center small text-body-secondary" id="meColorHexLbl"></span>
              <div class="form-text small mb-0 w-100">Не выбран — цвет по названию.</div></div>
            </div>
            <div class="mb-2"><label class="form-label small mb-1">IP сервера</label>
              <input class="form-control form-control-sm" id="meIp" maxlength="45" placeholder="185.0.0.1">
            </div>
            <label class="form-label small mb-1">Endpoint для опроса (CRM сам дёргает URL, 2xx = на связи)</label>
            <input class="form-control form-control-sm" id="meCheck" placeholder="https://домен/health.php" maxlength="255">
            <label class="form-label small mb-1 mt-2">URL админки (fapacrm и т.п.)</label>
            <input class="form-control form-control-sm" id="meAdmin" placeholder="https://домен/fapacrm/" maxlength="255">
          </div>
          <div class="modal-footer py-1">
            <button type="button" class="btn btn-sm btn-outline-danger me-auto" onclick="if(!confirm('Удалить сервис из мониторинга?'))return; const f=this.closest('form'); const fd=new FormData(f); fd.set('action','svc_delete'); moneyPost(fd, this)">🗑 Удалить</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="const f=this.closest('form'); const fd=new FormData(f); fd.set('action','svc_ip'); fd.set('ip', document.getElementById('meIp').value); moneyPost(fd, this)">Сменить IP</button>
            <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <script>
  function moneySaveTile(form) {
    const fd = new FormData(form);
    const id = fd.get('id');
    const name = document.getElementById('meOverride').value.trim();
    const saveBtn = form.querySelector('[type=submit]');
    saveBtn.disabled = true;
    fd.set('name_override', name);
    fetch("?page=<?= $self ?>", {method: "POST", body: fd})
      .then(r => r.json())
      .then(j => {
        if (!j || !j.ok) throw new Error(j.error || "ошибка сохранения");
        // endpoint
        const fdC = new FormData();
        fdC.set('action', 'svc_check'); fdC.set('id', id); fdC.set('check_url', document.getElementById('meCheck').value);
        // учёт
        const fdE = new FormData();
        fdE.set('action', 'svc_toggle'); fdE.set('id', id); fdE.set('enabled', document.getElementById('meEnabled').checked ? '1' : '0');
        // админка
        const fdA = new FormData();
        fdA.set('action', 'svc_admin'); fdA.set('id', id); fdA.set('admin_url', document.getElementById('meAdmin').value);
        // цвет
        const fdK = new FormData();
        fdK.set('action', 'svc_color'); fdK.set('id', id); fdK.set('color', document.getElementById('meColor').value);
        return fetch("?page=<?= $self ?>", {method: "POST", body: fdC}).then(r => r.json())
          .then(() => fetch("?page=<?= $self ?>", {method: "POST", body: fdE}).then(r => r.json()))
          .then(() => fetch("?page=<?= $self ?>", {method: "POST", body: fdA}).then(r => r.json()))
          .then(() => fetch("?page=<?= $self ?>", {method: "POST", body: fdK}).then(r => r.json()));
      })
      .then(j => {
        if (!j || !j.ok) throw new Error((j && j.error) || "ошибка endpoint/учёта");
        // переименовать в мониторинге, если имя изменилось
        const oldCode = document.getElementById('meOldCode').value;
        if (name && name !== oldCode) {
          const fd2 = new FormData();
          fd2.set('action', 'svc_rename'); fd2.set('id', id); fd2.set('code', name);
          return fetch("?page=<?= $self ?>", {method: "POST", body: fd2}).then(r => r.json());
        }
        return {ok: true};
      })
      .then(j => {
        if (j && j.ok) { location.reload(); return; }
        throw new Error((j && j.error) || "ошибка переименования");
      })
      .catch(e => { alert("Ошибка: " + e.message); saveBtn.disabled = false; });
  }
  </script>

  <!-- Модал: добавить сервис -->
  <div class="modal fade" id="moneyAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form onsubmit="event.preventDefault(); moneyPost(new FormData(this), this.querySelector('[type=submit]'))">
          <div class="modal-header py-2"><h5 class="modal-title fs-6">Новый сервис в мониторинг</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" name="action" value="svc_add">
            <div class="mb-2"><label class="form-label small mb-1">Название (код, X-Server для агента)</label><input class="form-control form-control-sm" name="code" maxlength="64" required></div>
            <div class="mb-2"><label class="form-label small mb-1">IP сервера</label><input class="form-control form-control-sm" name="ip" required></div>
            <div class="mb-2"><label class="form-label small mb-1">Тип</label>
              <select class="form-select form-select-sm" name="kind">
                <option value="tube">туб</option><option value="tracker">трекер</option><option value="game">игры</option><option value="win">win</option><option value="edge">edge</option>
              </select>
            </div>
            <div class="small text-body-secondary">Сервис появится без отчётов — токен для агента вернёт ответ после добавления.</div>
          </div>
          <div class="modal-footer py-1"><button type="submit" class="btn btn-sm btn-primary">Добавить</button></div>
        </form>
      </div>
    </div>
  </div>

<?php else: /* view=days: график + таблица */ ?>
  <script id="moneyDaysData" type="application/json"><?= json_encode(array_values((array)($J["days"] ?? [])), JSON_UNESCAPED_UNICODE) ?></script>
  <div class="card shadow-sm mb-3">
    <div class="card-body" style="height:280px"><canvas id="moneyDaysChart"></canvas></div>
  </div>
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr class="small text-body-secondary"><th>Дата</th><th class="text-end">Доход</th><th class="text-end">Расход</th><th class="text-end">Профит</th><th class="text-end">ROI</th></tr></thead>
        <tbody>
        <?php foreach (($J["days"] ?? []) as $row): $isToday = $row["d"] === ($J["today"] ?? ""); $roi = (float)$row["cost"] > 0 ? (float)$row["rev"] / (float)$row["cost"] : null; ?>
          <tr<?= $isToday ? ' class="table-success"' : '' ?>>
            <td class="fw-semibold"><?= h($row["d"]) ?><?= $isToday ? ' <span class="badge text-bg-success" style="font-size:.65rem">сегодня</span>' : '' ?></td>
            <td class="text-end text-success fw-semibold" style="font-variant-numeric:tabular-nums"><?= $fmt($row["rev"]) ?></td>
            <td class="text-end text-danger fw-semibold" style="font-variant-numeric:tabular-nums"><?= $fmt($row["cost"]) ?></td>
            <td class="text-end fw-bold <?= $tu($row["profit"]) ?>" style="font-variant-numeric:tabular-nums"><?= $fmt($row["profit"]) ?></td>
            <td class="text-end text-body-secondary"><?= $roi !== null ? number_format($roi, 2) . "x" : "—" ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <td class="fw-bold">Итого</td>
            <td class="text-end fw-bold text-success"><?= $fmt($T["rev"] ?? 0) ?></td>
            <td class="text-end fw-bold text-danger"><?= $fmt($T["cost"] ?? 0) ?></td>
            <td class="text-end fw-bold <?= $tu($T["profit"] ?? 0) ?>"><?= $fmt($T["profit"] ?? 0) ?></td>
            <td class="text-end text-body-secondary"><?= (($T["cost"] ?? 0) > 0) ? number_format((float)($T["rev"] ?? 0) / max(0.0001, (float)$T["cost"]), 2) . "x" : "—" ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
<?php endif; ?>
