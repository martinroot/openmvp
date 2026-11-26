<?php
declare(strict_types=1);
/**
 * RoadMap-конструктор: чат с MLL по направлению, версии плана, закрепление.
 */
require_once __DIR__ . '/../lib/roadmap.php';

$projectId = (int)req_int('project');
if ($projectId <= 0) {
    $projectId = (int)(req_int('project_id') ?: 0);
}
$project = $projectId > 0 ? node($projectId) : null;
if (!$project && !empty($_GET['project_ref'])) {
    $project = find_node_by_ref((string)$_GET['project_ref']);
    $projectId = $project ? (int)$project['id'] : 0;
}

$chat    = $projectId > 0 ? roadmap_chat($projectId, 120) : [];
$versions = $projectId > 0 ? roadmap_versions($projectId) : [];
$pinned  = $projectId > 0 ? roadmap_current($projectId) : null;

$models = mll_models();
$curModel = (string)(project_model($projectId) ?: '');
if ($curModel === '' && $models) {
    $curModel = (string)$models[0]['slug'];
}
$editId  = (int)req_int('edit');
$editRow = $editId > 0 ? roadmap_version($editId) : null;

function rm_badge(string $status): string
{
    if ($status === 'pinned') {
        return '<span class="badge bg-primary">закреплена</span>';
    }
    if ($status === 'approved') {
        return '<span class="badge bg-success">апрувнута</span>';
    }
    return '<span class="badge text-bg-light">черновик</span>';
}
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <h4 class="mb-0">RoadMap — <?= $project ? h((string)$project['title']) : 'проект не выбран' ?></h4>
    <div class="small muted">чат с MLL · версии плана · закрепление за проектом</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($project): ?>
      <a class="btn btn-sm btn-outline-secondary" href="?page=node&id=<?= $projectId ?>">← в проект</a>
      <form method="post" onsubmit="return confirm('Очистить переписку? Начнём с нуля.')">
        <input type="hidden" name="act" value="roadmap_clear">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-eraser me-1"></i>Очистить чат</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$project): ?>
  <div class="alert alert-warning small">Выбери проект: <a href="?page=tree">дерево</a> → карточка проекта → «RoadMap».</div>
<?php else: ?>
<div class="row g-3">
    <!-- ЧТО ВИДИТ MLL -->
  <div class="card mb-3">
    <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-eye me-1"></i>Что видит MLL</span>
      <span class="muted small">направление · пожелание · задачи · апрувнутый RoadMap · журнал</span>
    </div>
    <div class="card-body">
      <details>
        <summary class="small muted" style="cursor:pointer">Показать полный контекст, который уходит в модель</summary>
        <pre class="mt-2 p-2 bg-light rounded small" style="white-space:pre-wrap; max-height:420px; overflow:auto"><?= h(roadmap_context_view($projectId, (string)($_SESSION['mll_hint'] ?? ''), true)) ?></pre>
      </details>
      <?php if ($pinned): ?>
        <form method="post" class="d-flex gap-2 flex-wrap align-items-end mt-2">
          <input type="hidden" name="act" value="roadmap_decompose">
          <input type="hidden" name="project_id" value="<?= $projectId ?>">
          <input type="hidden" name="model" value="<?= h($curModel) ?>">
          <button class="btn btn-sm btn-warning"><i class="bi bi-diagram-3 me-1"></i>Разложить RoadMap на технические задачи</button>
          <span class="muted small">Chain: RoadMap (цель) → пошаговые задачи в план. Модель: <?= h($curModel) ?></span>
        </form>
      <?php else: ?>
        <div class="muted small mt-2">Разложить можно после того, как RoadMap утверждён и закреплён.</div>
      <?php endif; ?>
    </div>
  </div>

<!-- ЧАТ -->
  <div class="col-12 col-xl-7">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-chat-dots me-1"></i>Переписка с MLL</span>
        <span class="muted small">контекст сохраняется; «Очистить» — начать с нуля</span>
      </div>
      <div class="card-body" style="max-height:56vh; overflow:auto">
        <?php if (!$chat): ?>
          <div class="muted small">Переписка пуста. Опиши, чего хочешь от плана, или попроси собрать RoadMap с нуля.</div>
        <?php endif; ?>
        <?php foreach (array_reverse($chat) as $m): ?>
          <div class="mb-2 p-2 rounded" style="background:<?= $m['role'] === 'user' ? 'var(--sys-accent-soft)' : 'var(--sys-surface-2)' ?>">
            <div class="small muted mb-1">
              <?= $m['role'] === 'user' ? 'Ты' : 'MLL' ?>
              <?php if (!empty($m['model'])): ?>· <?= h((string)$m['model']) ?><?php endif; ?>
              · <?= h(dt((string)$m['created_at'], 'd.m H:i')) ?>
            </div>
            <div class="small" style="white-space:pre-wrap"><?= h((string)$m['text']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        <form method="post">
          <input type="hidden" name="act" value="roadmap_send">
          <input type="hidden" name="project_id" value="<?= $projectId ?>">
          <input type="hidden" name="model" value="<?= h($curModel) ?>">
          <textarea name="text" rows="3" class="form-control form-control-sm mb-2"
                    placeholder="Что нужно от плана? Например: собери RoadMap на ближайший месяц, сначала деньги"></textarea>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <button class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Спросить MLL</button>
            <label class="small muted d-flex gap-1 align-items-center">
              <input type="checkbox" name="with_log" value="1"> приложить журнал проекта
            </label>
            <span class="muted small">модель: <?= h($curModel) ?></span>
          </div>
        </form>
        <div class="mt-2">
          <form method="post" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="act" value="roadmap_set_model">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <select name="slug" class="form-select form-select-sm" style="width:auto">
              <?php foreach ($models as $mm): ?>
                <option value="<?= h((string)($mm['id'] ?? '')) ?>" <?= $curModel === (string)($mm['id'] ?? '') ? 'selected' : '' ?>>
                  <?= h((string)($mm['label'] ?? ($mm['id'] ?? ''))) ?><?= !empty($mm['free']) ? ' · free' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary">Сменить модель проекта</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ВЕРСИИ -->
  <div class="col-12 col-xl-5">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-list-ol me-1"></i>Версии RoadMap</span>
        <span class="muted small">всего: <?= count($versions) ?><?= $pinned ? ' · закреплена v' . (int)$pinned['version'] : '' ?></span>
      </div>
      <div class="card-body" style="max-height:56vh; overflow:auto">
        <?php if (!$versions): ?>
          <div class="muted small">Версий пока нет — спроси MLL, и он выдаст первый вариант плана.</div>
        <?php endif; ?>
        <?php foreach ($versions as $v):
            $steps = roadmap_steps($v);
            $isEdit = $editRow && (int)$editRow['id'] === (int)$v['id'];
            ?>
          <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
              <span class="fw-semibold">v<?= (int)$v['version'] ?> <?= rm_badge((string)$v['status']) ?>
                <?php if ($pinned && (int)$pinned['id'] === (int)$v['id']): ?>
                  <span class="badge bg-primary">текущий план</span>
                <?php endif; ?></span>
              <span class="muted small"><?= h(dt((string)$v['created_at'], 'd.m H:i')) ?>
                <?php if (!empty($v['model'])): ?>· <?= h((string)$v['model']) ?><?php endif; ?></span>
            </div>
            <?php if ($isEdit): ?>
              <form method="post">
                <input type="hidden" name="act" value="roadmap_edit">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <textarea name="steps" rows="<?= max(4, count($steps)) ?>" class="form-control form-control-sm mb-1"><?= h(implode("\n", array_map(static fn($sd) => $sd['title'] . (!empty($sd['detail']) ? ' — ' . $sd['detail'] : ''), roadmap_step_details($v)))) ?></textarea>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-primary">Сохранить правку</button>
                  <a class="btn btn-sm btn-outline-secondary" href="?page=roadmap&project=<?= $projectId ?>">Отмена</a>
                </div>
              </form>
            <?php else: ?>
              <ol class="small mb-2 ps-3">
                <?php foreach (roadmap_step_details($v) as $sd): ?>
                  <li><?= h($sd['title']) ?>
                    <?php if (!empty($sd['detail'])): ?>
                      <div class="muted" style="font-size:12px"><?= h($sd['detail']) ?></div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
              <div class="d-flex gap-1 flex-wrap">
                <form method="post" class="d-inline">
                  <input type="hidden" name="act" value="roadmap_approve">
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="btn btn-sm btn-outline-success">Апрувнуть</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="act" value="roadmap_pin">
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="btn btn-sm btn-primary">Закрепить за проектом</button>
                </form>
                <a class="btn btn-sm btn-outline-secondary" href="?page=roadmap&project=<?= $projectId ?>&edit=<?= (int)$v['id'] ?>">Скорректировать</a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
