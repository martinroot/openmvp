<?php
declare(strict_types=1);
/**
 * Учёт вызовов моделей: по направлениям, по задачам и по отдельным записям.
 * Данные приходят от агентов через POST /api/ingest.php (type=turn / type=call).
 */
$projects = all("SELECT id, title, icon FROM projects WHERE kind='project' ORDER BY title");

$pid = req_int('project');
$days = req_int('days', 7);
if (!in_array($days, [1, 7, 30, 0], true)) {
    $days = 7;
}
$where = [];
$args = [];
if ($pid > 0) {
    $where[] = 'c.project_id = ?';
    $args[] = $pid;
}
if ($days > 0) {
    $where[] = 'c.ts >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $args[] = $days;
}
$w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$types = str_repeat('i', count($args));

// 1. итог по направлениям
$byProject = all("SELECT c.project_id, p.title, p.icon, c.agent_code, c.model,
                          SUM(c.call_no) calls, SUM(c.tok_in) tok_in, SUM(c.tok_cache) tok_cache,
                          SUM(c.tok_cache_write) tok_cache_w,
                          SUM(c.tok_out) tok_out, SUM(c.tok_think) tok_think,
                          SUM(c.cost_usd) cost, SUM(c.duration_ms) dur, COUNT(*) recs
                     FROM llm_calls c LEFT JOIN projects p ON p.id = c.project_id
                     $w GROUP BY c.project_id, c.agent_code, c.model
                     ORDER BY calls DESC", $types, $args);

// 2. по задачам
$byTask = all("SELECT c.task_id, t.title task_title, t.status, p.title proj, p.icon,
                      c.agent_code, c.model,
                      SUM(c.call_no) calls, SUM(c.tok_in) tok_in, SUM(c.tok_cache) tok_cache,
                      SUM(c.tok_cache_write) tok_cache_w,
                      SUM(c.tok_out) tok_out, SUM(c.tok_think) tok_think,
                      SUM(c.cost_usd) cost, SUM(c.duration_ms) dur, MIN(c.ts) first_ts, MAX(c.ts) last_ts
                 FROM llm_calls c
                 LEFT JOIN projects t ON t.id = c.task_id
                 LEFT JOIN projects p ON p.id = c.project_id
                 $w GROUP BY c.task_id, c.agent_code, c.model
                 ORDER BY calls DESC", $types, $args);

// 3. последние записи
$last = all("SELECT c.*, p.title proj, t.title task_title
             FROM llm_calls c
             LEFT JOIN projects p ON p.id = c.project_id
             LEFT JOIN projects t ON t.id = c.task_id
             $w ORDER BY c.id DESC LIMIT 60", $types, $args);

$totCalls = $totIn = $totCache = $totOut = $totThink = $totDur = 0;
$totCost = 0.0;
foreach ($byProject as $r) {
    $totCalls += (int)$r['calls'];
    $totIn    += (int)$r['tok_in'];
    $totCache += (int)$r['tok_cache'];
    $totOut   += (int)$r['tok_out'];
    $totThink += (int)$r['tok_think'];
    $totDur   += (int)$r['dur'];
    $totCost  += (float)$r['cost'];
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Учёт вызовов</h1>
    <div class="page-sub">Токены, модель, время и стоимость по каждому вызову и по каждой задаче</div>
  </div>
  <form method="get" class="d-flex gap-2 align-items-start">
    <input type="hidden" name="page" value="usage">
    <select class="form-select form-select-sm" name="project" style="max-width:220px">
      <option value="0">Все направления</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>>
          <?= (($p['icon'] ?? '') . ' ' . h($p['title'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" name="days" style="max-width:150px">
      <option value="1" <?= $days === 1 ? 'selected' : '' ?>>сегодня</option>
      <option value="7" <?= $days === 7 ? 'selected' : '' ?>>7 дней</option>
      <option value="30" <?= $days === 30 ? 'selected' : '' ?>>30 дней</option>
      <option value="0" <?= $days === 0 ? 'selected' : '' ?>>всё время</option>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Показать</button>
  </form>
</div>

<div class="row g-3 mb-3">
  <?php
  $cards = [
      ['Вызовы', number_format($totCalls), 'bi-activity'],
      ['Токены (in/out)', format_tokens($totIn) . ' / ' . format_tokens($totOut), 'bi-cpu'],
      ['Кэш (r/w) / размышления', format_tokens($totCache) . ' / ' . format_tokens($totThink), 'bi-layers'],
      ['Стоимость', '$' . number_format($totCost, 4), 'bi-cash-coin'],
      ['Время', format_dur($totDur), 'bi-clock'],
  ];
  foreach ($cards as [$t, $v, $ic]): ?>
    <div class="col-6 col-md">
      <div class="card h-100">
        <div class="card-body py-3">
          <div class="text-secondary small fw-semibold"><i class="bi <?= $ic ?> me-1"></i><?= $t ?></div>
          <div class="fs-5 fw-bold"><?= $v ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header">По направлениям</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Направление</th><th>Агент</th><th>Модель</th><th class="text-end">Вызовы</th>
          <th class="text-end">in</th><th class="text-end">cache</th><th class="text-end">out</th>
          <th class="text-end">think</th><th class="text-end">$</th><th class="text-end">время</th></tr>
      </thead>
      <tbody>
      <?php if (!$byProject): ?>
        <tr><td colspan="10" class="text-secondary py-4 text-center">
          Пока нет ни одного отчёта об вызовах. Агенты шлют их в трекер через POST /api/ingest.php
          (type=turn или type=call) с заголовком X-Ingest-Key.
        </td></tr>
      <?php endif; ?>
      <?php foreach ($byProject as $r): ?>
        <tr>
          <td><?= h((string)($r['icon'] ?? '')) ?> <?= h((string)($r['title'] ?? '—')) ?></td>
          <td class="small"><?= h((string)$r['agent_code']) ?></td>
          <td class="small"><?= h((string)($r['model'] ?? '—')) ?></td>
          <td class="text-end fw-semibold"><?= (int)$r['calls'] ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_in']) ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_cache']) ?><span class="text-secondary">/<?= format_tokens((int)($r['tok_cache_w'] ?? 0)) ?></span></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_out']) ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_think']) ?></td>
          <td class="text-end small">$<?= number_format((float)$r['cost'], 4) ?></td>
          <td class="text-end small"><?= format_dur((int)$r['dur']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">По задачам</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Задача</th><th>Направление</th><th>Модель</th><th class="text-end">Вызовы</th>
          <th class="text-end">in</th><th class="text-end">out</th>
          <th class="text-end">$</th><th class="text-end">время</th><th>когда</th></tr>
      </thead>
      <tbody>
      <?php if (!$byTask): ?>
        <tr><td colspan="9" class="text-secondary py-4 text-center">Задач с учётом пока нет.</td></tr>
      <?php endif; ?>
      <?php foreach ($byTask as $r): ?>
        <tr>
          <td>
            <?php if ($r['task_id']): ?>
              <a href="?page=node&id=<?= (int)$r['task_id'] ?>">[<?= (int)$r['task_id'] ?>]</a>
              <?= h((string)($r['task_title'] ?? '')) ?>
            <?php else: ?>
              <span class="text-secondary">без задачи</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= h((string)($r['icon'] ?? '')) ?> <?= h((string)($r['proj'] ?? '—')) ?></td>
          <td class="small"><?= h((string)($r['model'] ?? '—')) ?></td>
          <td class="text-end fw-semibold"><?= (int)$r['calls'] ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_in']) ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_out']) ?></td>
          <td class="text-end small">$<?= number_format((float)$r['cost'], 4) ?></td>
          <td class="text-end small"><?= format_dur((int)$r['dur']) ?></td>
          <td class="small text-secondary"><?= dt((string)$r['last_ts'], 'd.m H:i') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">Последние отчёты</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Время</th><th>Направление</th><th>Задача</th><th>Модель</th>
          <th class="text-end">вызовы</th><th class="text-end">in</th><th class="text-end">out</th>
          <th class="text-end">$</th><th class="text-end">время</th><th>агент</th></tr>
      </thead>
      <tbody>
      <?php if (!$last): ?>
        <tr><td colspan="10" class="text-secondary py-4 text-center">Пусто.</td></tr>
      <?php endif; ?>
      <?php foreach ($last as $r): ?>
        <tr>
          <td class="small text-secondary"><?= dt((string)$r['ts'], 'd.m.Y H:i:s') ?></td>
          <td class="small"><?= h((string)($r['proj'] ?? '—')) ?></td>
          <td class="small"><?= $r['task_id'] ? '[' . (int)$r['task_id'] . ']' : '—' ?></td>
          <td class="small"><?= h((string)($r['model'] ?? '—')) ?></td>
          <td class="text-end small"><?= (int)$r['call_no'] ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_in']) ?></td>
          <td class="text-end small"><?= format_tokens((int)$r['tok_out']) ?></td>
          <td class="text-end small">$<?= number_format((float)$r['cost_usd'], 4) ?></td>
          <td class="text-end small"><?= format_dur((int)$r['duration_ms']) ?></td>
          <td class="small text-secondary"><?= h((string)$r['agent_code']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
