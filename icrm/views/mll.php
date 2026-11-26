<?php
declare(strict_types=1);
/**
 * MLL-советчик: список новых задач от модели.
 * Запускается только по кнопке — никакого автоматического запуска.
 */
require_once __DIR__ . '/../lib/mll.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$act = req_str('act');

$targets = mll_targets();
$projects = [];
$subs = [];
foreach ($targets as $t) {
    if ($t['kind'] === 'project') {
        $projects[] = $t;
    } else {
        $subs[] = $t;
    }
}

// выбранное направление и подпроект
$projectId = req_int('project');
if ($projectId <= 0 && $projects) {
    $projectId = (int)$projects[0]['id'];
}
$subId = req_int('sub');
$project = $targets[$projectId] ?? null;
$sub = (isset($targets[$subId]) && $targets[$subId]['parent_id'] === $projectId) ? $targets[$subId] : null;
$targetId = $sub ? (int)$sub['id'] : $projectId;
$targetName = $sub ? $sub['title'] : (string)($project['title'] ?? '');
$scope = $sub ? 'подпроект направления ' . (string)($project['title'] ?? '') : 'направление';

$model = req_str('model');
if ($model === '' || mll_model($model) === null) {
    $model = 'z-ai/glm-5.3-flash';
}

// действия (mll_generate / mll_add / mll_clear) обработаны в контроллере:
// из вида редирект уже не уходит — макет к этому моменту уже напечатал HTML.

$steps = $_SESSION['mll_steps'] ?? [];
$usage = $_SESSION['mll_usage'] ?? null;
$models = mll_models();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">MLL — советник</h1>
    <div class="page-sub">Скармливает модели текущие задачи и журнал, предлагает новые.
      Запускается только по кнопке.</div>
  </div>
  <a class="btn btn-light border" href="?page=tree">К дереву</a>
</div>

<div class="card mb-3">
  <div class="card-header">Запуск</div>
  <div class="card-body">
    <form method="post" action="?page=mll" class="d-flex gap-2 align-items-end flex-wrap">
      <input type="hidden" name="act" value="mll_generate">
      <div>
        <label class="form-label small fw-semibold mb-1">Модель</label>
        <select class="form-select form-select-sm" name="model" style="min-width:300px">
          <?php foreach ($models as $m): ?>
            <option value="<?= h($m['id']) ?>" <?= $model === $m['id'] ? 'selected' : '' ?>>
              <?= h($m['label']) ?> ($<?= number_format($m['in'], 4) ?>/$<?= number_format($m['out'], 4) ?> за 1M)<?php
                 if (!empty($m['score'])) { echo ' ★' . number_format((float)$m['score'], 2); }
                 if (!empty($m['free'])) { echo ' free'; } ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">Проект</label>
        <select class="form-select form-select-sm" name="project" style="min-width:200px">
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">Подпроект</label>
        <select class="form-select form-select-sm" name="sub" style="min-width:200px">
          <option value="0">— без подпроекта —</option>
          <?php foreach ($subs as $s): ?>
            <?php if ((int)$s['parent_id'] !== $projectId) { continue; } ?>
            <option value="<?= (int)$s['id'] ?>" <?= $sub && (int)$sub['id'] === (int)$s['id'] ? 'selected' : '' ?>>
              <?= h($s['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-100">
        <label class="form-label small fw-semibold mb-1">Куда развивать (опционально)</label>
        <input class="form-control form-control-sm" name="hint" maxlength="300"
               value="<?= h((string)($_SESSION['mll_hint'] ?? '')) ?>"
               placeholder="например: выжать деньги с уже прогнанной ленты, докрутить монетизацию fapacrm">
      </div>
      <button class="btn btn-primary btn-sm" type="submit">
        <i class="bi bi-stars me-1"></i>Добавить задачу
      </button>
      <?php if ($steps): ?>
        <a class="btn btn-light border btn-sm" href="?page=mll&act=mll_clear&project=<?= $projectId ?>">Очистить</a>
      <?php endif; ?>
      <div class="form-text w-100">Цена в скобках — за 1M токенов (вход/выход). Повторное нажатие
        «Добавить задачу» даёт новый список шагов по выбранному направлению.
        Пожелание «куда развивать» необязательно — если заполнено, модель строит шаги в эту сторону.</div>
    </form>
  </div>
</div>

<?php if ($usage): ?>
  <div class="card mb-3">
    <div class="card-header">Ответ модели</div>
    <div class="card-body">
      <div class="row g-2 small">
        <div class="col-auto">модель: <b><?= h((string)$usage['model']) ?></b></div>
        <div class="col-auto">in: <b><?= format_tokens((int)$usage['in']) ?></b></div>
        <div class="col-auto">out: <b><?= format_tokens((int)$usage['out']) ?></b></div>
        <?php if (!empty($usage['cache'])): ?>
          <div class="col-auto">кэш: <b><?= format_tokens((int)$usage['cache']) ?></b></div>
        <?php endif; ?>
        <div class="col-auto">цена: <b>$<?= number_format((float)$usage['cost'], 6) ?></b></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($steps): ?>
  <div class="card mb-3">
    <div class="card-header">Шаги по направлению <?= h((string)($usage['target_name'] ?? $targetName)) ?>
      — правь и добавляй по одному</div>
    <form method="post" action="?page=mll">
      <input type="hidden" name="act" value="mll_add_many">
      <input type="hidden" name="project" value="<?= (int)$projectId ?>">
      <input type="hidden" name="sub" value="<?= $sub ? (int)$sub['id'] : 0 ?>">
      <input type="hidden" name="model" value="<?= h($model) ?>">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th style="width:40px"></th><th style="width:50px">#</th><th>Шаг</th></tr>
          </thead>
          <tbody>
          <?php foreach ($steps as $i => $step): ?>
            <tr>
              <td><input class="form-check-input" type="checkbox" name="pick[]" value="<?= $i ?>"
                         id="pick<?= $i ?>"></td>
              <td class="text-secondary"><?= $i + 1 ?></td>
              <td>
                <input class="form-control form-control-sm" name="step<?= $i ?>" style="width:100%; min-width:520px"
                       value="<?= h((string)$step) ?>">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex gap-2 align-items-center">
        <button class="btn btn-primary btn-sm" type="submit">
          <i class="bi bi-plus-lg me-1"></i>Добавить отмеченные
        </button>
        <span class="small text-secondary">Добавленные уйдут из списка — останутся остальные.
          Цель: <?= h((string)$targetName) ?>.</span>
      </div>
    </form>
  </div>
<?php elseif ($act === 'mll_generate'): ?>
  <div class="card mb-3">
    <div class="card-body text-secondary">Модель не предложила шагов.</div>
  </div>
<?php endif; ?>

<?php if (!empty($usage['raw'])): ?>
  <div class="card">
    <div class="card-header">Сырой ответ</div>
    <div class="card-body"><pre class="mb-0 small" style="white-space:pre-wrap"><?= h((string)$usage['raw']) ?></pre></div>
  </div>
<?php endif; ?>
