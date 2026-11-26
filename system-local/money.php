<?php
/**
 * Деньги — живые данные velvetflux/money (JSON) → Bootstrap-карточки.
 * Оформление: утилиты Bootstrap + --bs-* токены (правила skill bootstrap-*).
 * Сервисы: переименование, добавление, вкл/выкл, привязка к дереву задач.
 */
$pg   = $_GET["page"] ?? "money_days";
$tab  = ($pg === "money_servers" || ($_GET["tab"] ?? "") === "servers") ? "servers" : "days";
$self = $tab === "servers" ? "money_servers" : "money_days";
$days = (int)($_GET["days"] ?? 1);
if (!in_array($days, [1,7,30,90], true)) $days = 1;

$remote = "https://velvetflux.click/money/?view=" . $tab . "&days=" . $days . "&format=json";
$cf = sys_get_temp_dir() . "/money_json_" . md5($remote) . ".json";
if (!is_file($cf) || time() - filemtime($cf) > 60) {
    $ctx = stream_context_create(["http" => ["timeout" => 12, "header" => "User-Agent: icrm-embed/1.0\r\n"]]);
    $raw = @file_get_contents($remote, false, $ctx);
    if ($raw !== false) file_put_contents($cf, $raw);
} else $raw = file_get_contents($cf);

$J = $raw ? json_decode($raw, true) : null;
$err = (!$J || empty($J["ok"])) ? "Не удалось получить данные с velvetflux.click" : null;
$T = $J["total"] ?? [];
$fmt = fn($v) => "$" . number_format((float)$v, 2);
$sfmt = fn($v) => ($v !== null ? number_format((float)$v, 2) . "x" : "—");
$tu = fn($v) => (float)$v > 0.00001 ? "text-success" : ((float)$v < -0.00001 ? "text-danger" : "text-body-secondary");

/* карта привязок и локальных имён */
$map = [];
foreach (all("SELECT server_id, tree_node_id, name_override FROM money_svc_map", '') as $m) {
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
<script src="/money/vendor/chart.umd.min.js" defer></script>
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
  m.querySelector('#meCode').value = d.code;
  m.querySelector('#meOverride').value = d.override || "";
  m.querySelector('#meNode').value = d.node || "";
  m.querySelector('#meEnabled').checked = d.enabled === "1";
  m.querySelector('[data-mrole=rename]').disabled = (d.override || "") === "" ;
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
</script>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 m-0"><?= $tab === "servers" ? "Деньги · IP / Сервисы" : "Деньги · по дням" ?></h1>
  <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Тип отчёта">
    <a class="btn<?= $tab === "days" ? "" : " outline-" ?>btn-primary" href="?page=money_days&days=<?= $days ?>">По дням</a>
    <a class="btn<?= $tab === "servers" ? "" : " outline-" ?>btn-primary" href="?page=money_servers&days=<?= $days ?>">IP / Сервисы</a>
  </div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Период">
    <?php foreach ($daysLabels as $d => $lbl): ?>
      <a class="btn<?= $d === $days ? "" : " outline-" ?>btn-secondary" href="?page=<?= $self ?>&days=<?= $d ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($tab === "servers"): ?>
    <button class="btn btn-success btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#moneyAddModal">+ Сервис</button>
  <?php endif; ?>
</div>

<?php if ($err): ?>
  <div class="alert alert-warning" role="alert"><?= h($err) ?></div>
<?php elseif ($tab === "servers"): ?>
  <div class="row g-2">
    <?php foreach (($J["servers"] ?? []) as $sv):
      $sid = (int)($sv["id"] ?? 0);
      $mp = $map[$sid] ?? null;
      $dispName = ($mp["name_override"] ?? "") !== "" && $mp["name_override"] !== null ? $mp["name_override"] : (string)($sv["code"] ?? "—");
      $st = empty($sv["last_seen"]) ? "off" : ((time() - strtotime($sv["last_seen"] . " UTC") > 3600) ? "stale" : "on");
      $parked = empty($sv["enabled"]);
      $kindLbl = $sv["kind"] === "tracker" ? "трекер" : ($sv["kind"] === "game" ? "игры" : "туб");
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
              <span class="fw-semibold text-truncate"><?= h($dispName) ?></span>
              <div class="d-flex gap-1">
                <?php if (!empty($mp["tree_node_id"])): ?>
                  <a href="?page=node&id=<?= (int)$mp["tree_node_id"] ?>" class="badge text-bg-light text-decoration-none" title="Задача: <?= h((string)$nodeName) ?>">⛓</a>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:.7rem" onclick="moneyEditTile(this.closest('.card'))"
                        data-id="<?= $sid ?>" data-code="<?= h((string)($sv["code"] ?? "")) ?>" data-name="<?= h($dispName) ?>"
                        data-override="<?= h((string)($mp["name_override"] ?? "")) ?>" data-node="<?= (int)($mp["tree_node_id"] ?? "") ?>"
                        data-enabled="<?= (int)!$parked ?>">✎</button>
              </div>
            </div>
            <div class="text-body-secondary" style="font-size:.72rem">
              <span class="d-inline-block rounded-circle <?= $st === "on" ? "bg-success" : ($st === "stale" ? "bg-warning" : "bg-secondary") ?>" style="width:.45rem;height:.45rem"></span>
              <code><?= h($sv["ip"] ?? "") ?></code> · <?= $kindLbl ?>
            </div>
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
  <div class="text-body-secondary small mt-2">Точка статуса: 🟢 на связи &lt;1ч · 🟡 молчит &gt;1ч · ⚪ нет отчётов. ✎ — название, привязка к задаче, вкл/выкл.</div>

  <!-- Модал: редактирование сервиса -->
  <div class="modal fade" id="moneyEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form onsubmit="event.preventDefault(); moneyPost(new FormData(this), this.querySelector('[type=submit]'))">
          <div class="modal-header py-2"><h5 class="modal-title fs-6" id="meTitle">Сервис</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" name="id">
            <input type="hidden" name="action" value="svc_link">
            <div class="mb-2"><label class="form-label small mb-1">Название (локальное, что вижу в CRM)</label><input class="form-control form-control-sm" name="name_override" id="meOverride" maxlength="128"></div>
            <div class="mb-2"><label class="form-label small mb-1">Название в мониторинге (147)</label><input class="form-control form-control-sm" id="meCode" maxlength="64"></div>
            <div class="mb-2"><label class="form-label small mb-1">Привязка к узлу дерева задач</label>
              <select class="form-select form-select-sm" name="tree_node_id" id="meNode">
                <option value="">— не привязан —</option>
                <?php foreach ($treeNodes as $tn): ?>
                  <option value="<?= (int)$tn["id"] ?>"><?= str_repeat("· ", max(0, ((int)$tn["parent_id"] > 0 ? 1 : 0))) . h((string)$tn["title"]) ?> [<?= $tn["kind"] ?> #<?= (int)$tn["id"] ?>]</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="meEnabled" <?= "" ?>>
              <label class="form-check-label small" for="meEnabled">В учёте (enabled)</label>
            </div>
          </div>
          <div class="modal-footer py-1">
            <button type="button" class="btn btn-sm btn-outline-danger me-auto" onclick="const f=this.closest('form'); const fd=new FormData(f); fd.set('action','svc_rename'); moneyPost(fd, this)">Переименовать на 147</button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="const f=this.closest('form'); const fd=new FormData(f); fd.set('action','svc_toggle'); fd.set('enabled', document.getElementById('meEnabled').checked ? '1':'0'); moneyPost(fd, this)">Сменить учёт</button>
            <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Модал: добавить сервис -->
  <div class="modal fade" id="moneyAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form onsubmit="event.preventDefault(); moneyPost(new FormData(this), this.querySelector('[type=submit]'))">
          <div class="modal-header py-2"><h5 class="modal-title fs-6">Новый сервис в мониторинг</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" name="action" value="svc_add">
            <div class="mb-2"><label class="form-label small mb-1">Название (код)</label><input class="form-control form-control-sm" name="code" maxlength="64" required></div>
            <div class="mb-2"><label class="form-label small mb-1">IP сервера</label><input class="form-control form-control-sm" name="ip" required></div>
            <div class="mb-2"><label class="form-label small mb-1">Тип</label>
              <select class="form-select form-select-sm" name="kind">
                <option value="tube">туб</option><option value="tracker">трекер</option><option value="game">игры</option><option value="win">win</option><option value="edge">edge</option>
              </select>
            </div>
            <div class="small text-body-secondary">После добавления сервис появится без отчётов — токен для агента вернёт ответ; передай его на сервер мониторинга.</div>
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
