<?php
/**
 * Дашборд — просто.
 * Проекты с прогрессом, цели, живой лог агента.
 */
$dayKey = date('Y-m-d');
$goals = goals_for($dayKey);
$gGlobal = $goals['global'] ?? null;
$gDay = $goals['day'] ?? null;

$spendDay = spend_since($dayKey . ' 00:00:00');
$spendMonth = spend_since(date('Y-m-01') . ' 00:00:00');

$projects = all("SELECT * FROM projects WHERE kind='project' ORDER BY pos, id");
$recent = all("SELECT e.*, p.title AS ptitle
   FROM events e LEFT JOIN projects p ON p.id = e.project_id
   ORDER BY e.id DESC LIMIT 40");
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Дашборд</h1>
    <div class="page-sub"><?= date('d.m.Y') ?></div>
  </div>
  <a class="btn btn-primary btn-sm" href="?page=node_new"><i class="bi bi-plus-lg me-1"></i>Задача</a>
</div>

<?php if ($gGlobal || $gDay): ?>
<div class="row g-3 mb-3">
  <?php foreach ([['Цель дня', $gDay, $spendDay], ['Цель на месяц', $gGlobal, $spendMonth]] as [$lbl, $g, $fact]): ?>
    <?php if (!$g) { continue; } ?>
    <?php
    $target = (float)$g['target_usd'];
    $pct = $target > 0 ? min(100, $fact / $target * 100) : 0;
    ?>
    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="kpi-label"><?= h($lbl) ?></div>
            <div class="fs-5 fw-bold mt-1"><?= format_usd($target) ?></div>
          </div>
          <div class="text-end">
            <div class="kpi-value" style="font-size:1.2rem"><?= format_usd($fact) ?></div>
            <div class="kpi-sub"><?= round($pct, 1) ?>%</div>
          </div>
        </div>
        <div class="goal-track mt-2"><div class="goal-fill" style="width:<?= round($pct, 1) ?>%"></div></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <?php if (!$projects): ?>
      <div class="card"><div class="card-body">
        <div class="empty-state"><i class="bi bi-folder2-open"></i>Проектов нет</div>
      </div></div>
    <?php else: foreach ($projects as $p): ?>
      <div class="card mb-3">
        <?php
        $tasks = all("SELECT id, title, status, pos, prio FROM projects WHERE parent_id = ? AND kind='task' ORDER BY prio, pos, id",
                     'i', [(int)$p['id']]);
        $done = 0;
        foreach ($tasks as $t) {
            if ($t['status'] === 'done') {
                $done++;
            }
        }
        ?>
        <div class="card-header d-flex justify-content-between align-items-center">
          <a class="text-reset" href="?page=node&id=<?= (int)$p['id'] ?>"><?= h($p['title']) ?></a>
          <span class="kpi-sub"><?= $done ?> из <?= count($tasks) ?> готово</span>
        </div>
        <div class="card-body p-2">
          <?php foreach ($tasks as $t): ?>
            <div class="trow <?= $t['status'] === 'done' ? 'is-done' : '' ?>">
              <a class="tcheck <?= $t['status'] === 'done' ? 'on' : '' ?>"
                 href="?page=node&id=<?= (int)$t['id'] ?>&act=toggle" title="переключить"></a>
              <a class="ttext text-reset" href="?page=node&id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
              <?php if ($t['status'] === 'done'): ?>
                <span class="tstate tstate-done">готово</span>
              <?php else: ?>
                <span class="tstate">не готово</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$tasks): ?>
            <div class="empty-state py-3"><i class="bi bi-list-check"></i>Задач нет</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header">Лог агента</div>
      <div class="card-body">
        <div class="timeline">
          <?php if (!$recent): ?>
            <div class="empty-state py-4"><i class="bi bi-inbox"></i>Пока пусто</div>
          <?php else: foreach ($recent as $ev): ?>
            <div class="tl-item">
              <span class="tl-dot <?= h($ev['level']) ?>"></span>
              <div class="tl-time"><?= h(dt($ev['ts'], 'd.m H:i')) ?></div>
              <?php if ($ev['project_id']): ?>
                <div class="tree-meta"><?= h($ev['ptitle'] ?: '') ?></div>
              <?php endif; ?>
              <div class="tl-text"><?= h($ev['text'] ?: '') ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
