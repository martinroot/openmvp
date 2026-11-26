<?php
/* Гант за сегодня: все проекты сети, полосы 00:00 → сейчас, без скролла, резиновый. */
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
      WHERE r.ts >= CURDATE()
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
$systemKey = 'sys';
foreach ($runs as $r) {
    $pid = (int)($r['project_id'] ?: ($codeToProj[$r['agent_code']] ?? 0));
    if ($pid && isset($rows[$pid])) {
        $rows[$pid]['runs'][] = $r;
    } else {
        $rows[$systemKey] = $rows[$systemKey] ?? ['label' => 'Система · ' . $r['agent_code'], 'icon' => '🤖', 'runs' => []];
        $rows[$systemKey]['runs'][] = $r;
    }
}

$dayStart = strtotime(date('Y-m-d 00:00:00'));
$nowTs    = time();
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
    <h1 class="page-title">Гант · сегодня</h1>
    <div class="page-sub">запуски агентов <?= date('d.m.Y') ?>, 00:00 — <?= date('H:i') ?> · <?= count($runs) ?> запусков</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-light border btn-sm" href="?page=charts">Графики</a>
    <a class="btn btn-light border btn-sm" href="?page=tree">К списку</a>
  </div>
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
.gantt-now::after{content:"сейчас";position:absolute;top:1px;left:6px;font-size:9px;font-weight:700;color:#e5484d;white-space:nowrap}
.gantt-empty{padding:14px 10px;font-size:11px;color:#9aa1ad}
</style>

<?php if (!$rows): ?>
  <div class="gantt"><div class="gantt-empty">Сегодня запусков не было</div></div>
<?php else: ?>
<div class="gantt">
  <div class="gantt-head">
    <b>Хронология запусков</b>
    <span>00:00 → <?= date('H:i') ?> · наведи на полосу</span>
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
                     . ($dur >= 60000 ? round($dur / 60000, 1) . ' мин' : round($dur / 1000) . ' с')
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
