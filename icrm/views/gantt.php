<?php
/* Гант за выбранный день (по умолчанию сегодня): все проекты сети, полосы 00:00 → конец дня, без скролла. */
$date = (string)($_GET['date'] ?? '');
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) { $date = date('Y-m-d'); }
$dateQ = addslashes($date);
$isToday = ($date === date('Y-m-d'));
$dayEndQ = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
$projects = all(
    "SELECT id, title, icon
       FROM projects
      WHERE parent_id IS NULL AND kind = 'project'
      ORDER BY pos, id"
);
$runs = all(
    "SELECT r.ts, r.duration_ms, r.agent_code, r.tok_in, r.tok_cache, r.tok_out, r.cost_usd, r.error,
            r.project_id
       FROM agent_runs r
      WHERE r.ts >= '$dateQ 00:00:00' AND r.ts < '$dayEndQ'
      ORDER BY r.ts"
);

// agent_code → project_id (для запусков без проставленного project_id)
$codeToProj = [];
foreach (all("SELECT id, agent_code FROM projects WHERE agent_code IS NOT NULL AND agent_code <> ''") as $p) {
    $codeToProj[$p['agent_code']] = (int)$p['id'];
}

// строки: ВСЕ корневые проекты + «система» для unmapped запусков
$rows = [];
foreach ($projects as $p) {
    $rows[(int)$p['id']] = ['label' => $p['title'], 'icon' => $p['icon'], 'runs' => []];
}
$embed = (string)($_GET['embed'] ?? '') === '1';
$systemName = $embed ? 'System' : 'Система';
$systemKey = 'sys';
foreach ($runs as $r) {
    $pid = (int)($r['project_id'] ?: ($codeToProj[$r['agent_code']] ?? 0));
    if ($pid && isset($rows[$pid])) {
        $rows[$pid]['runs'][] = $r;
    } else {
        $rows[$systemKey] = $rows[$systemKey] ?? ['label' => $systemName . ' · ' . $r['agent_code'], 'icon' => '🤖', 'runs' => []];
        $rows[$systemKey]['runs'][] = $r;
    }
}

$dayStart = strtotime($date . ' 00:00:00');
$nowTs    = $isToday ? time() : strtotime($date . ' 23:59:30');
$spanSec  = max(3600, $nowTs - $dayStart + 120); // 00:00 → сейчас (+2 мин запас)
$pct = function (int $ts) use ($dayStart, $spanSec): float {
    return max(0.0, min(100.0, ($ts - $dayStart) / $spanSec * 100));
};
$h = function (?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); };

// дедупликация: сторож-хук иногда пишёт один ход несколько раз с одинаковой
// кумулятивной длительностью (промежуточные штампы) — на ганте это длинные
// наложенные полосы. Оставляем первый штамп, дубли пропускаем.
foreach ($rows as &$row) {
    $seen = [];
    $row['runs'] = array_values(array_filter($row['runs'], function ($r) use (&$seen) {
        $k = $r['agent_code'] . '|' . (int)$r['duration_ms'] . '|' . (int)$r['tok_in'] . '|' . (int)$r['tok_out'];
        if (isset($seen[$k])) return false;
        $seen[$k] = true;
        return true;
    }));
}
unset($row);

// палитра: мягкие пастельные теги [фон, текст, полоса]
$palette = [
    ['#eef1fe', '#4f6ef7', '#6480f5'],
    ['#f4eefe', '#8b5cf6', '#a07bf8'],
    ['#fdeef5', '#ec4899', '#ef6aa9'],
    ['#fdf5e7', '#c07a08', '#e09b2d'],
    ['#e9f9f1', '#0f8f5f', '#27b178'],
    ['#e9f6fa', '#0e7490', '#2296ad'],
    ['#fdecec', '#d43c3c', '#e56363'],
    ['#f2f8e9', '#5f8f10', '#7cae2b'],
    ['#f5edfd', '#a855f7', '#bd78f8'],
    ['#fdf0e7', '#d2691e', '#e68a4a'],
];
$pal = function (string $key) use ($palette): array {
    return $palette[abs(crc32($key)) % count($palette)];
};

// ось: 30-минутные деления, подписи каждый час
$lastHalf = (int)floor(($nowTs - $dayStart) / 1800);
// сетка дорожек = те же позиции, что и деления оси (гарантия совпадения)
$gridTicks = [];
for ($i = 0; $i <= $lastHalf; $i++) {
    $l = $pct($dayStart + $i * 1800);
    if ($l > 99.5) break;
    $gridTicks[(string)round($l, 3)] = ($i % 2 === 0);
}
?>
<div class="page-head">
  <div>
    <div class="page-sub"><?= $embed
      ? 'agent runs ' . date('d.m.Y', strtotime($date)) . ($isToday ? ', 00:00 — ' . date('H:i') : '') . ' · ' . count($runs) . ' runs'
      : 'запуски агентов ' . date('d.m.Y', strtotime($date)) . ($isToday ? ', 00:00 — ' . date('H:i') : '') . ' · ' . count($runs) . ' запусков' ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <input type="date" id="gantt-date" value="<?= $h($date) ?>" onchange="var u=new URL(location.href);u.searchParams.set('date',this.value);location.href=u"
           style="font-size:11.5px;padding:3px 8px;border:1px solid #d9dde6;border-radius:7px;background:#fff;color:#33415e">
  </div>
  <?php if (!$embed): ?>
  <div class="d-flex gap-2">
    <a class="btn btn-light border btn-sm" href="?page=charts">Графики</a>
    <a class="btn btn-light border btn-sm" href="?page=tree">К списку</a>
  </div>
  <?php endif; ?>
</div>

<style>
.gantt{border:1px solid var(--sys-border,#e3e6ee);border-radius:10px;background:var(--sys-surface,#fff);overflow:hidden;width:100%}
.gantt-head{display:flex;justify-content:space-between;align-items:baseline;padding:6px 10px 4px;border-bottom:1px solid rgba(0,0,0,.06)}
.gantt-head b{font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#6b7280}
.gantt-head span{font-size:10px;color:#9aa1ad}
.gantt-body{display:grid;grid-template-columns:minmax(150px,190px) 1fr}
.gantt-body .gantt-tags{display:flex;flex-direction:column}
.gantt-body .gantt-lanes{display:flex;flex-direction:column;min-width:0}
.gantt-row{height:17px}
.gantt-row+.gantt-row{border-top:1px solid rgba(15,23,42,.06)}
.gantt-tag{display:inline-block;max-width:calc(100% - 8px);margin:0 4px;padding:1.5px 9px 1.5px 6px;border-radius:7px;
    font-size:10.5px;line-height:14px;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
.gantt-tag i{font-style:normal;margin-right:4px}
.gantt-lane{position:relative;height:17px}
.gantt-lane i{position:absolute;top:0;bottom:0;width:2px;background:rgba(15,23,42,.16);pointer-events:none}
.gantt-lane i.hr{width:2px;background:rgba(15,23,42,.34)}
.gantt-lane .bar{position:absolute;top:4px;height:9px;border-radius:4px;min-width:4px;
    box-shadow:0 0 0 .5px rgba(15,23,42,.18) inset;cursor:default}
.gantt-lane .bar:hover{filter:brightness(1.1)}
.gantt-axis{position:relative;height:22px;border-top:2px solid rgba(15,23,42,.30)}
.gantt-axis i{position:absolute;top:3px;width:2px;height:5px;background:rgba(15,23,42,.38)}
.gantt-axis i.hr{height:9px;background:rgba(15,23,42,.55)}
.gantt-axis b{position:absolute;top:10px;font-size:10px;font-weight:700;color:#475069;transform:translateX(-50%);letter-spacing:.03em}
.gantt-now{position:absolute;top:0;bottom:0;width:3px;background:#e5484d;opacity:.95;pointer-events:none;border-radius:1px}
.gantt-now::after{content:"<?= $embed ? 'now' : 'сейчас' ?>";position:absolute;top:1px;left:6px;font-size:9px;font-weight:700;color:#e5484d;white-space:nowrap}
.gantt-empty{padding:14px 10px;font-size:11px;color:#9aa1ad}
</style>

<?php if (!$rows): ?>
  <div class="gantt"><div class="gantt-empty"><?= $embed ? ('No runs on ' . $date) : ('На ' . date('d.m.Y', strtotime($date)) . ' запусков не было') ?></div></div>
<?php else: ?>
<div class="gantt">
  <div class="gantt-head">
    <b><?= $embed ? 'Run timeline' : 'Хронология запусков' ?></b>
    <span>00:00 → <?= date('H:i') ?> · <?= $embed ? 'hover a bar for details' : 'наведи на полосу' ?></span>
  </div>
  <div class="gantt-body">
    <div class="gantt-tags">
      <?php foreach ($rows as $key => $row): [$bg, $fg, $bar] = $pal($row['label']); ?>
        <div class="gantt-row" title="<?= $h($row['label']) ?>">
          <span class="gantt-tag" style="background:<?= $bg ?>;color:<?= $fg ?>"><i><?= $h($row['icon'] ?: '📁') ?></i><?= $h($row['label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="gantt-lanes">
      <?php foreach ($rows as $row): [$bg, $fg, $bar] = $pal($row['label']); ?>
        <div class="gantt-row">
          <div class="gantt-lane">
            <?php foreach (array_keys($gridTicks) as $gt): ?>
              <i class="<?= $gridTicks[$gt] ? 'hr' : '' ?>" style="left:<?= $gt ?>%"></i>
            <?php endforeach;
            foreach ($row['runs'] as $r):
                $st = $pct(strtotime($r['ts']));
                $w  = max(0.25, (int)$r['duration_ms'] / ($spanSec * 1000) * 100);
                $dur = (int)$r['duration_ms'];
                $tip = date('H:i:s', strtotime($r['ts'])) . ' · '
                     . ($dur >= 60000 ? round($dur / 60000, 1) . ($embed ? ' min' : ' мин') : round($dur / 1000) . ($embed ? ' s' : ' с'))
                     . ' · ' . $r['agent_code'];
                if ((int)$r['tok_in'] || (int)$r['tok_out']) {
                    $tip .= ' · ' . number_format((int)$r['tok_in'] + (int)$r['tok_cache']) . '→' . number_format((int)$r['tok_out']);
                }
            ?>
              <span class="bar<?= $r['error'] ? ' err' : '' ?>"
                    style="left:<?= round($st, 3) ?>%;width:<?= round($w, 3) ?>%;background:<?= $bar ?>"
                    title="<?= $h($tip) ?>"></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="gantt-axis">
        <span class="gantt-now" style="left:<?= round($pct($nowTs), 3) ?>%"></span>
        <?php for ($i = 0; $i <= $lastHalf; $i++):
            $l = $pct($dayStart + $i * 1800);
            if ($l > 99.5) break;
            $hour = $i % 2 === 0;
        ?>
            <i class="<?= $hour ? 'hr' : '' ?>" style="left:<?= round($l, 3) ?>%"></i>
            <?php if ($hour): ?><b style="left:<?= round($l, 3) ?>%"><?= str_pad((string)($i / 2), 2, '0', STR_PAD_LEFT) ?></b><?php endif; ?>
        <?php endfor; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// почасовой учёт: задачи = agent_runs, спенд = llm_calls (точные суммы)
$hTasks = [];
foreach (all(
    "SELECT HOUR(ts) h, COUNT(*) n FROM agent_runs WHERE ts >= '$dateQ 00:00:00' AND ts < '$dayEndQ' GROUP BY HOUR(ts)"
) as $r) { $hTasks[(int)$r['h']] = (int)$r['n']; }
$hCost = [];
$hTokIn = []; $hTokOut = [];
foreach (all(
    "SELECT HOUR(ts) h, SUM(cost_usd) c, SUM(tok_in + tok_cache) ti, SUM(tok_out) to_
       FROM llm_calls WHERE ts >= '$dateQ 00:00:00' AND ts < '$dayEndQ' GROUP BY HOUR(ts)"
) as $r) {
    $hCost[(int)$r['h']] = (float)$r['c'];
    $hTokIn[(int)$r['h']] = (int)$r['ti'];
    $hTokOut[(int)$r['h']] = (int)$r['to_'];
}
$curHour = $isToday ? (int)date('G') : 23;
$firstHour = 0;
for ($i = 0; $i <= $curHour; $i++) { if (isset($hTasks[$i]) || isset($hCost[$i])) { $firstHour = $i; break; } }
$dayTasks = array_sum($hTasks);
$dayCost  = array_sum($hCost);
$dayTin   = array_sum($hTokIn);
$dayTout  = array_sum($hTokOut);
$maxHourCost = 0.0;
for ($i = $firstHour; $i <= $curHour; $i++) {
    $maxHourCost = max($maxHourCost, $hCost[$i] ?? 0.0);
}
if ($maxHourCost <= 0) $maxHourCost = 1.0;
$hLab = function (string $en, string $ru) use ($embed): string { return $embed ? $en : $ru; };
?>
<div class="gantt hourly" style="margin-top:10px">
  <div class="gantt-head">
    <b><?= $hLab('Hourly accounting · ' . date('d.m', strtotime($date)), 'Учёт по часам · ' . date('d.m', strtotime($date))) ?></b>
    <span><?= $hLab('tasks · spend · avg per task', 'задачи · спенд · в среднем на задачу') ?></span>
  </div>
  <div style="padding:8px 10px 10px">
    <?php if (!$dayTasks && !$dayCost): ?>
      <div class="gantt-empty" style="padding:4px 0"><?= $hLab('No activity on this day', 'В этот день активности нет') ?></div>
    <?php else: ?>
      <?php for ($i = $firstHour; $i <= $curHour; $i++):
          $n = $hTasks[$i] ?? 0;
          $c = $hCost[$i] ?? 0.0;
          if (!$n && $c <= 0) continue;
          $avg = $n > 0 ? $c / $n : 0.0;
          $w = round($c / $maxHourCost * 100, 2);
          $fmt = function (float $v): string {
              return '$' . ($v >= 1 ? number_format($v, 2) : number_format($v, 4, '.', ''));
          };
      ?>
      <div class="gantt-hour">
        <b><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?>:00</b>
        <div class="gantt-hour-track"><span style="width:<?= max(1.5, $w) ?>%"></span></div>
        <span class="gantt-hour-num">
          <?= $n ?>&nbsp;<?= $hLab('tasks', 'задач') ?> · <?= $fmt($c) ?>
          <?php if ($n > 0): ?> · <?= $hLab('avg', 'сред.') ?> <?= $fmt($avg) ?><?php endif; ?>
          <?php if (!empty($hTokIn[$i]) || !empty($hTokOut[$i])): ?> · <?= number_format((int)$hTokIn[$i]) ?>→<?= number_format((int)$hTokOut[$i]) ?><?php endif; ?>
        </span>
      </div>
      <?php endfor; ?>
      <div class="gantt-hour" style="border-top:1px solid rgba(15,23,42,.10);margin-top:4px;padding-top:5px">
        <b><?= $hLab('Day', 'День') ?></b>
        <div></div>
        <span class="gantt-hour-num">
          <?= $dayTasks ?>&nbsp;<?= $hLab('tasks', 'задач') ?> · <?= $fmt($dayCost) ?>
          <?php if ($dayTasks > 0): ?> · <?= $hLab('avg', 'сред.') ?> <?= $fmt($dayCost / $dayTasks) ?><?php endif; ?>
          · <?= number_format((int)$dayTin) ?>→<?= number_format((int)$dayTout) ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>
.gantt-hour{display:flex;align-items:center;gap:10px;padding:2.5px 0}
.gantt-hour>b{width:44px;font-size:10.5px;font-weight:700;color:#475069;flex:none}
.gantt-hour-track{flex:1;min-width:60px;height:10px;background:rgba(15,23,42,.06);border-radius:5px;overflow:hidden}
.gantt-hour-track span{display:block;height:100%;background:linear-gradient(90deg,#6480f5,#a07bf8);border-radius:5px}
.gantt-hour-num{flex:none;width:min(46%,320px);font-size:10.5px;color:#475069;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:right}
</style>

<?php
// токены и ЦЕНА по часам: IN / cache / OUT — $/1M из каталога models (p_in, p_cache_read, p_out)
$hCall = [];
$hIn = []; $hOut = []; $hCache = []; $hMaxIn = [];
$hCostIn = []; $hCostCache = []; $hCostOut = [];
foreach (all(
    "SELECT HOUR(c.ts) h, COUNT(*) n, SUM(c.tok_in) ti, SUM(c.tok_out) to_, SUM(c.tok_cache) tc, MAX(c.tok_in) mx,
            SUM(c.tok_in * COALESCE(m.p_in,0)) cin,
            SUM(c.tok_cache * COALESCE(m.p_cache_read,0)) cinc,
            SUM(c.tok_out * COALESCE(m.p_out,0)) cout
       FROM llm_calls c LEFT JOIN models m ON m.slug = c.model
      WHERE c.ts >= '$dateQ 00:00:00' AND c.ts < '$dayEndQ' GROUP BY HOUR(c.ts)"
) as $r) {
    $h = (int)$r['h'];
    $hCall[$h] = (int)$r['n'];
    $hIn[$h] = (int)$r['ti'];
    $hOut[$h] = (int)$r['to_'];
    $hCache[$h] = (int)$r['tc'];
    $hMaxIn[$h] = (int)$r['mx'];
    $hCostIn[$h] = (float)$r['cin'];
    $hCostCache[$h] = (float)$r['cinc'];
    $hCostOut[$h] = (float)$r['cout'];
}
$curHour2 = $isToday ? (int)date('G') : 23;
$sumIn = array_sum($hIn); $sumOut = array_sum($hOut); $sumCache = array_sum($hCache); $nCalls = array_sum($hCall);
$sumCIn = array_sum($hCostIn); $sumCCache = array_sum($hCostCache); $sumCOut = array_sum($hCostOut);
$maxHIn = 0.0;
foreach ($hIn as $v) { $maxHIn = max($maxHIn, (float)$v); }
if ($maxHIn <= 0) $maxHIn = 1.0;
$hLab2 = function (string $en, string $ru) use ($embed): string { return $embed ? $en : $ru; };
$fmtI = function ($v): string { return number_format((int)$v); };
// цена за 1M токенов: $x/M; при нулевом объёме — прочерк
$fmtP = function (float $cost, int $tok): string {
    if ($tok <= 0) return '—';
    $per = $cost / ($tok / 1000000);
    if ($per >= 1) return '$' . number_format($per, 2) . '/M';
    return '$' . number_format($per, 4) . '/M';
};
$fmtD = function (float $v): string {
    return '$' . ($v >= 1 ? number_format($v, 2) : number_format($v, 4, '.', ''));
};
?>
<div class="gantt hourly-inout" style="margin-top:10px">
  <div class="gantt-head">
    <b><?= $hLab2('Tokens by hour: IN / OUT · ' . date('d.m', strtotime($date)), 'Токены по часам: IN / OUT · ' . date('d.m', strtotime($date))) ?></b>
    <span><?= $hLab2('per call · price $/1M', 'на вызов · цена $/1M') ?></span>
  </div>
  <div style="padding:8px 10px 10px">
    <?php if (!$nCalls): ?>
      <div class="gantt-empty" style="padding:4px 0"><?= $hLab2('No calls on this day', 'В этот день вызовов нет') ?></div>
    <?php else: ?>
      <?php foreach ($hIn as $h => $ti):
          if ($h > $curHour2) continue;
          $n = $hCall[$h] ?: 1;
          $to = $hOut[$h] ?? 0;
          $tc = $hCache[$h] ?? 0;
          $mx = $hMaxIn[$h] ?? 0;
          $avgIn = round($ti / $n);
          $avgOut = round($to / $n);
          $avgCache = round($tc / $n);
          $wIn = round($ti / $maxHIn * 100, 2);
          $wOut = $ti > 0 ? round($to / $ti * 100, 2) : 0;
      ?>
      <div class="gantt-hour">
        <b><?= str_pad((string)$h, 2, '0', STR_PAD_LEFT) ?>:00</b>
        <div class="gantt-hour-track gantt-io-track">
          <span class="io-in" style="width:<?= max(1.5, $wIn) ?>%"></span>
          <span class="io-out" style="width:<?= min(49.5, max(0.8, $wOut / 2)) ?>%"></span>
        </div>
        <span class="gantt-hour-num" title="IN <?= $fmtI($ti) ?> · cache <?= $fmtI($tc) ?> · OUT <?= $fmtI($to) ?> · max in <?= $fmtI($mx) ?> · cost in <?= $fmtD($hCostIn[$h] ?? 0) ?> + cache <?= $fmtD($hCostCache[$h] ?? 0) ?> + out <?= $fmtD($hCostOut[$h] ?? 0) ?>">
          <?= $n ?>&nbsp;<?= $hLab2('calls', 'вызовов') ?> · IN <?= $fmtI($avgIn) ?> <?= $fmtP($hCostIn[$h] ?? 0, $ti) ?> · cache <?= $fmtI($avgCache) ?> <?= $fmtP($hCostCache[$h] ?? 0, $tc) ?> · OUT <?= $fmtI($avgOut) ?> <?= $fmtP($hCostOut[$h] ?? 0, $to) ?>
        </span>
      </div>
      <?php endforeach; ?>
      <div class="gantt-hour" style="border-top:1px solid rgba(15,23,42,.10);margin-top:4px;padding-top:5px">
        <b><?= $hLab2('Day', 'День') ?></b>
        <div></div>
        <span class="gantt-hour-num">
          <?= $fmtI($nCalls) ?>&nbsp;<?= $hLab2('calls', 'вызовов') ?> · IN <?= $fmtI($sumIn) ?> <?= $fmtP($sumCIn, $sumIn) ?> · cache <?= $fmtI($sumCache) ?> <?= $fmtP($sumCCache, $sumCache) ?> · OUT <?= $fmtI($sumOut) ?> <?= $fmtP($sumCOut, $sumOut) ?> · <?= $hLab2('cost', 'цена') ?> <?= $fmtD($sumCIn + $sumCCache) ?> + <?= $fmtD($sumCOut) ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>
.gantt-io-track{display:flex;gap:2px;background:rgba(15,23,42,.05)}
.gantt-io-track .io-in{display:block;height:100%;background:linear-gradient(90deg,#6480f5,#7c8cf8);border-radius:5px 0 0 5px}
.gantt-io-track .io-out{display:block;height:100%;background:linear-gradient(90deg,#1a9e6c,#27c284);border-radius:0 5px 5px 0}
</style>
