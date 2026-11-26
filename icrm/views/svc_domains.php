<?php
/**
 * Сервисы — все домены по типам серверов (kind), с переходом внутрь сервиса.
 * Источник: БД money (daily + servers), напрямую mysqli.
 */
$__lib = file_get_contents(dirname(__DIR__) . '/money/lib.php');
preg_match("/DB_USER='([^']*)'/", $__lib, $mu); preg_match("/DB_PASS='CHANGE_ME']*)'/", $__lib, $mp); preg_match("/DB_NAME='([^']*)'/", $__lib, $mn);
$mdb = @new mysqli('localhost', $mu[1] ?? 'money', $mp[1] ?? '', $mn[1] ?? 'money');
if ($mdb->connect_error) { echo '<div class="alert alert-danger">money DB недоступна</div>'; return; }
$mdb->set_charset('utf8mb4');

$days = max(1, min(90, (int)($_GET['days'] ?? 30)));
$st = $mdb->prepare("SELECT s.id sid, s.code, s.kind, s.ip, s.is_enabled, s.last_ping_ok,
        d.domain, SUM(d.revenue) rev, SUM(d.cost) cost, SUM(d.clicks) clicks, SUM(d.visits) visits, MAX(d.d) last_d
    FROM daily d
    JOIN servers s ON s.id = d.server_id AND s.is_enabled = 1 AND s.kind = 'tube'
    WHERE d.d >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND d.domain <> ''
    GROUP BY s.id, d.domain
    ORDER BY cost DESC, d.domain");
$st->bind_param('i', $days); $st->execute();
$res = $st->get_result();

$secTitles = [
    'tube'    => 'ADULT',
    'tracker' => 'ТРЕКЕР',
    'game'    => 'ИГРЫ',
    'win'     => 'WIN',
    'edge'    => 'EDGE',
];
$groups = [];
$totRev = 0.0; $totCost = 0.0; $totDom = 0;
while ($r = $res->fetch_assoc()) {
    $kind = $r['kind'] ?: 'tube';
    $groups[$kind][] = $r;
    $totRev += (float)$r['rev']; $totCost += (float)$r['cost']; $totDom++;
}
// ROI% и мягкая градиентная подложка строки: -80% красный -> 0% белый -> +100% зелёный
$roiPct = fn(float $rev, float $cost): ?float => $cost > 0 ? ($rev - $cost) / $cost * 100 : null;
$roiBg  = function(?float $pct): string {
    if ($pct === null) return '';
    if ($pct < 0) { $f = min(1.0, abs($pct) / 80.0); $r = 225; $g = $b = (int)round(255 - $f * 120); }
    else          { $f = min(1.0, $pct / 100.0); $g = 255; $r = (int)round(255 - $f * 115); $b = (int)round(255 - $f * 100); }
    return sprintf('background:rgba(%d,%d,%d,.28)', $r, $g, $b);
};
$fmt = fn(float $v): string => '$' . number_format($v, 2);
$tu  = fn(float $v): string => $v > 0 ? 'text-success' : ($v < 0 ? 'text-danger' : 'text-body-secondary');
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 m-0">Сервисы · Домены ADULT</h1>
  <span class="badge text-bg-secondary-subtle text-dark-emphasis">домены за <?= $days ?> д</span>
  <div class="btn-group btn-group-sm ms-auto">
    <a class="btn btn-outline-secondary <?= $days === 1 ? 'active' : '' ?>" href="?page=svc_domains&days=1">1д</a>
    <a class="btn btn-outline-secondary <?= $days === 7 ? 'active' : '' ?>" href="?page=svc_domains&days=7">7д</a>
    <a class="btn btn-outline-secondary <?= $days === 30 ? 'active' : '' ?>" href="?page=svc_domains&days=30">30д</a>
    <a class="btn btn-outline-secondary <?= $days === 90 ? 'active' : '' ?>" href="?page=svc_domains&days=90">90д</a>
  </div>
</div>

<div class="row g-2 mb-3">
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Домены</div><div class="fs-5 fw-bold"><?= $totDom ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Доход</div><div class="fs-5 fw-bold text-success"><?= $fmt($totRev) ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Расход</div><div class="fs-5 fw-bold text-danger"><?= $fmt($totCost) ?></div></div></div></div>
  <div class="col"><div class="card shadow-sm"><div class="card-body py-2"><div class="text-body-secondary small">Профит</div><div class="fs-5 fw-bold <?= $tu($totRev - $totCost) ?>"><?= $fmt($totRev - $totCost) ?></div></div></div></div>
</div>

<?php foreach ($secTitles as $kind => $title): if (empty($groups[$kind])) continue;
    $rows = $groups[$kind];
    $kv = 0.0; $kc = 0.0; $kd = 0;
    foreach ($rows as $r) { $kv += (float)$r['rev']; $kc += (float)$r['cost']; $kd++; }
    $kp = array_unique(array_map(fn($r) => (int)$r['sid'], $rows));
?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 small fw-bold d-flex align-items-center">
    <span class="badge text-bg-dark me-2"><?= count($kp) ?> сервиса</span>
    <?= $title ?>
    <span class="ms-auto text-body-secondary fw-normal"><?= $kd ?> доменов · <?= $fmt($kv) ?> / <?= $fmt($kc) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead><tr class="small text-body-secondary">
        <th>Домен</th><th>Сервис</th><th>IP</th><th class="text-end">Доход</th><th class="text-end">Расход</th>
        <th class="text-end">Профит</th><th class="text-end">ROI %</th><th class="text-end">Клики</th><th class="text-end">Визиты</th><th class="text-end">Последний день</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $rev = (float)$r['rev']; $cost = (float)$r['cost'];
        $pingOk = ((int)($r['last_ping_ok'] ?? 1) === 1);
      ?>
      <?php $rp = $roiPct($rev, $cost); ?>
      <tr style="<?= $roiBg($rp) ?>">
        <td class="fw-semibold"><?= h($r["domain"]) ?></td>
        <td><a class="badge fw-bold text-decoration-none <?= $pingOk ? 'text-bg-primary' : 'text-bg-secondary' ?>"
               href="?page=money_svc&id=<?= (int)$r["sid"] ?>" title="<?= $pingOk ? '' : 'endpoint не отвечает' ?>"><?= h($r["code"]) ?> ↗</a></td>
        <td class="small text-body-secondary"><?= h($r["ip"]) ?></td>
        <td class="text-end text-success"><?= $fmt($rev) ?></td>
        <td class="text-end text-danger"><?= $fmt($cost) ?></td>
        <td class="text-end fw-bold <?= $tu($rev - $cost) ?>"><?= $fmt($rev - $cost) ?></td>
        <td class="text-end fw-semibold"><?= $rp !== null ? (($rp > 0 ? '+' : '') . round($rp) . '%') : '—' ?></td>
        <td class="text-end"><?= number_format((int)$r["clicks"]) ?></td>
        <td class="text-end"><?= number_format((int)$r["visits"]) ?></td>
        <td class="text-end small text-body-secondary"><?= h($r["last_d"]) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="alert alert-info">Нет данных за период</div><?php endif; ?>
