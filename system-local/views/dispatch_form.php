<?php
$project = req_int('project');
$n = $project ? node($project) : null;
$all = tree_all();
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Поставить задачу агенту</h1>
    <div class="page-sub">Задача попадёт в очередь диспетчера — отправка только вручную</div>
  </div>
  <a class="btn btn-light border" href="?page=dispatch">К очереди</a>
</div>

<form method="post" action="?page=dispatch_new<?= $project ? '&project=' . $project : '' ?>">
  <input type="hidden" name="act" value="create">
  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <div class="card">
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Код агента</label>
            <input class="form-control" name="agent_code" required value="<?= h($n['agent_code'] ?? '') ?>" placeholder="edge-147">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Узел трекера</label>
            <select class="form-select" name="project_id">
              <option value="">— не привязывать —</option>
              <?php foreach ($all as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= $project === (int)$o['id'] ? 'selected' : '' ?>>
                  <?= indent_of((int)$o['id']) ?><?= h($o['icon']) ?> <?= h($o['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Текст задачи</label>
            <textarea class="form-control" name="payload" rows="8" required
              placeholder="Что нужно сделать агенту. Формат: что сделать, что проверить, что отчитаться."></textarea>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="card mb-3">
        <div class="card-header">Как это работает</div>
        <div class="card-body small text-secondary">
          <p class="mb-2">Задача встаёт в очередь диспетчера со статусом <span class="chip">new</span>.</p>
          <p class="mb-2">Кнопка «Отправить» кладёт её в <code>chat.php</code> агентского чата с <code>kind=task</code> — агент подхватит её в своём цикле.</p>
          <p class="mb-0">Автоматической оркестрации нет: следующую задачу ставим вместе или через интерфейс.</p>
        </div>
      </div>
      <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>В очередь</button>
    </div>
  </div>
</form>
