<?php
declare(strict_types=1);
/** Оркестратор — страница проекта. ?page=orch&id=<project_id> */
$projectId = (int)($_REQUEST['id'] ?? 0);
require_once __DIR__ . '/../lib/orch.php';

$p = null;
try { $p = one("SELECT * FROM projects WHERE id=?", 'i', [$projectId]); } catch (Throwable $e) {}
if (!$p) { echo '<div class="alert alert-danger">Проект не найден</div>'; return; }

/* сохранить настройки */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'orch_save') {
    $goal = trim((string)($_POST['goal'] ?? ''));
    $model = trim((string)($_POST['model'] ?? '')) ?: 'google/gemini-2.5-flash';
    $interval = max(5, (int)($_POST['interval_min'] ?? 30));
    $maxIter = max(0, (int)($_POST['max_iter'] ?? 0));
    $enabled = (int)!empty($_POST['enabled']);
    orch_save($projectId, $goal, $model, $interval, $maxIter, $enabled);
    $o = orch_get($projectId);
    $goal = (string)($o['goal'] ?? ''); $model = (string)($o['model'] ?? '');
    $interval = (int)($o['interval_min'] ?? 30); $maxIter = (int)($o['max_iter'] ?? 0);
    $enabled = (int)($o['enabled'] ?? 0);
 $iters = (int)($o['iter_count'] ?? 0);
    $orchMsg = 'Настройки сохранены';
}
/* ручной тик */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'orch_tick') {
    $lid = orch_tick($projectId);
    $o = orch_get($projectId);
    $iters = (int)($o['iter_count'] ?? 0);
    $orchMsg = $lid ? 'Итерация выполнена (лог #' . $lid . ')' : 'Тик пропущен — не пришло время или лимит';
    $tot = null;
    try { $tot = one("SELECT COUNT(*) n, COALESCE(SUM(cost_usd),0) c, COALESCE(SUM(tok_in),0) ti, COALESCE(SUM(tok_out),0) to_ FROM orch_log WHERE project_id=?", 'i', [$projectId]); } catch (Throwable $e) {}
    $log = null;
    try { $log = all("SELECT * FROM orch_log WHERE project_id=? ORDER BY id DESC LIMIT 20", 'i', [$projectId]); } catch (Throwable $e) {}
    $answers = orch_agent_answers($projectId, 4);

}

$o = orch_get($projectId) ?: [];
$goal = (string)($o['goal'] ?? '');
$model = (string)($o['model'] ?? 'google/gemini-2.5-flash');
$interval = (int)($o['interval_min'] ?? 30);
$maxIter = (int)($o['max_iter'] ?? 0);
$enabled = (int)($o['enabled'] ?? 0);
// следующий запуск по логике: last_run_at + interval (UTC), таймер до него
$orchNextAt = null;
if ($enabled && !empty($o['last_run_at'])) {
    $orchNextAt = strtotime($o['last_run_at'] . ' UTC') + max(5, $interval) * 60;
}

$iters = (int)($o['iter_count'] ?? 0);

$tot = null;
try { $tot = one("SELECT COUNT(*) n, COALESCE(SUM(cost_usd),0) c, COALESCE(SUM(tok_in),0) ti, COALESCE(SUM(tok_out),0) to_
                    FROM orch_log WHERE project_id=?", 'i', [$projectId]); } catch (Throwable $e) {}
$log = null;
try { $log = all("SELECT * FROM orch_log WHERE project_id=? ORDER BY id DESC LIMIT 20", 'i', [$projectId]); } catch (Throwable $e) {}
$answers = orch_agent_answers($projectId, 4);
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a class="btn btn-sm btn-outline-secondary" href="?page=node&id=<?= $projectId ?>">← Проект</a>
  <h1 class="h5 m-0">Оркестрация — <?= h($p['title']) ?></h1>
  <span class="badge <?= $enabled ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $enabled ? 'включён' : 'выключен' ?></span>
  <span class="muted small ms-auto">итераций: <?= $iters ?></span>
</div>

<?php if (!empty($orchMsg)): ?><div class="alert alert-success py-2"><?= h($orchMsg) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Настройки</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="act" value="orch_save">
          <div class="mb-2">
            <label class="form-label small mb-1">Цель</label>
            <textarea class="form-control" name="goal" rows="4" placeholder="например: держать профит >/= $3/сутки, следить за метриками и спендом"><?= h($goal) ?></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small mb-1">Периодичность, мин</label>
              <input type="number" min="5" class="form-control form-control-sm" name="interval_min" value="<?= $interval ?>">
            </div>
            <div class="col-6">
              <label class="form-label small mb-1">Лимит итераций (0 = ∞)</label>
              <input type="number" min="0" class="form-control form-control-sm" name="max_iter" value="<?= $maxIter ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Модель</label>
            <input type="text" class="form-control form-control-sm" name="model" value="<?= h($model) ?>">
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="orchEn" value="1" <?= $enabled ? 'checked' : '' ?>>
            <label class="form-check-label small" for="orchEn">Запускать по расписанию</label>
          </div>
          <button class="btn btn-sm btn-primary">Сохранить</button>
        </form>
        <form method="post" class="mt-2">
          <input type="hidden" name="act" value="orch_tick">
          <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-play-fill"></i>Запустить итерацию сейчас</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Учёт вызовов</div>
      <div class="card-body py-2 small">
        <div class="d-flex justify-content-between"><span>Итераций</span><b><?= $tot ? (int)$tot['n'] : 0 ?></b></div>
        <div class="d-flex justify-content-between"><span>Токены (вход/выход)</span><b><?= $tot ? number_format((float)$tot['ti']) : 0 ?> / <?= $tot ? number_format((float)$tot['to_']) : 0 ?></b></div>
        <div class="d-flex justify-content-between"><span>Стоимость</span><b>$<?= $tot ? number_format((float)$tot['c'], 4) : '0.0000' ?></b></div>
        <div class="d-flex justify-content-between"><span>Последний запуск</span><b><?= !empty($o['last_run_at']) ? h($o['last_run_at']) : '—' ?></b></div>
        <div class="d-flex justify-content-between"><span>Следующий запуск</span><b id="orchTimer" data-target="<?= $orchNextAt ? (int)$orchNextAt : 0 ?>"><?= $enabled ? ($orchNextAt ? '—' : 'ожидает крона') : 'цикл выключен' ?></b></div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Лог работы (последние 20)</div>
      <div class="table-responsive" style="max-height:340px">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-body-secondary"><th>#</th><th>Время</th><th class="text-end">Токены</th><th class="text-end">$</th><th>Решение</th></tr></thead>
          <tbody>
          <?php foreach ((array)($log ?? []) as $l): ?>
            <tr class="<?= (int)$l['ok'] ? '' : 'table-danger' ?>">
              <td><?= (int)$l['iter'] ?></td>
              <td class="small text-body-secondary"><?= h(mb_strimwidth((string)$l['ts'], 5, 11, '')) ?></td>
              <td class="text-end small"><?= (int)$l['tok_in'] ?>/<?= (int)$l['tok_out'] ?></td>
              <td class="text-end small"><?= number_format((float)$l['cost_usd'], 4) ?></td>
              <td class="small" style="max-width:420px">
                <?= h(mb_strimwidth((string)$l['decision'], 0, 160, '…')) ?>
                <?php if (trim((string)$l['triggers']) !== ''): ?><div class="muted" style="font-size:11px">⚡ <?= h(mb_strimwidth((string)$l['triggers'], 0, 90, '…')) ?></div><?php endif; ?>
                <?php if ((int)$l['ok'] === 0): ?><div class="text-danger" style="font-size:11px"><?= h(mb_strimwidth((string)($l['err'] ?? ''), 0, 80)) ?></div><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$log): ?><tr><td colspan="5" class="text-center text-body-secondary py-3">Итераций ещё не было</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Результат взаимодействия — последние ответы агента</div>
      <div class="card-body py-2">
        <?php foreach ($answers as $a): ?>
          <div class="border-start border-3 ps-2 mb-2 small" style="border-color:#adb5bd">
            <div class="muted" style="font-size:11px"><?= h($a['ts']) ?></div>
            <?= nl2br(h(mb_strimwidth((string)$a['text'], 0, 700, '…'))) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$answers): ?><div class="muted small">Агент ещё не отчитывался</div><?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header py-2 small fw-bold">Актуальная дорожная карта (память оркестратора)</div>
      <div class="card-body py-2 small" style="white-space:pre-wrap"><?= h((string)($o['state'] ?? '—')) ?></div>
    </div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('orchTimer');
  if (!el) return;
  var target = parseInt(el.getAttribute('data-target'), 10) || 0;
  if (!target) return;
  function pad(n){ return (n<10?'0':'')+n; }
  function tick(){
    var diff = target - Math.floor(Date.now()/1000);
    if (diff > 0) {
      var m = Math.floor(diff/60), s = diff%60;
      el.textContent = 'до запуска ' + pad(m) + ':' + pad(s);
    } else {
      el.textContent = 'запуск в очереди крона (до 5 мин)';
    }
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
