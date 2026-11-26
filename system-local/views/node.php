<?php
require_once __DIR__ . '/../lib/dispatch.php';
/**
 * Карточка задачи — просто.
 * Заголовок, чекбокс готово/не готово, лог агента, следующая задача.
 */
$id = req_int('id');
$n = node($id);
if (!$n) {
    echo '<div class="alert alert-warning">Задача не найдена</div>';
    return;
}
$parent = $n['parent_id'] ? node((int)$n['parent_id']) : null;
$isTask = ($n['kind'] ?? '') === 'task';
// Задачи направления: для проекта/подпроекта — со всего поддерева (вкл. подпроекты-серверы)
if ($isTask) {
    $siblings = $parent
        ? all("SELECT id, title, status, pos, prio, ready_at, auto_chain, auto_step FROM projects
               WHERE parent_id = ? AND kind='task' ORDER BY prio, pos, id",
              'i', [(int)$parent['id']])
        : [];
} else {
    $taskScope = direction_scope_ids((int)$n['id']);
    $ph = implode(',', array_fill(0, count($taskScope), '?'));
    $siblings = all("SELECT id, title, status, pos, prio, ready_at, auto_chain, auto_step FROM projects
                     WHERE parent_id IN ($ph) AND kind='task' ORDER BY prio, pos, id",
                    str_repeat('i', count($taskScope)), $taskScope);
}
// Лог задачи — сообщения, относящиеся к ней; если их нет, показываем лог направления
$isTask = ($n['kind'] ?? '') === 'task';
$events = [];
if ($isTask) {
    $events = all("SELECT * FROM events WHERE task_id = ? ORDER BY id DESC LIMIT 300", 'i', [$id]);
}
if (!$events) {
    $scopeId = $isTask && $parent ? (int)$parent['id'] : $id;
    $events = all("SELECT * FROM events WHERE project_id = ? ORDER BY id DESC LIMIT 300", 'i', [$scopeId]);
}
$isDone = $n['status'] === 'done';
// Периодические задачи — только для проектов и подпроектов
$recs = [];
if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)) {
    require_once __DIR__ . '/../lib/recurring.php';
    $recs = recurring_list((int)$n['id']);
}
$next = null;
foreach ($siblings as $s) {
    if ((int)$s['pos'] > (int)$n['pos'] && $s['status'] !== 'done') {
        $next = $s;
        break;
    }
}
?>
<div class="page-head">
  <div class="d-flex align-items-center gap-3">
    <a class="tcheck <?= $isDone ? 'on' : '' ?>"
       href="?page=node&id=<?= (int)$n['id'] ?>&act=toggle"
       title="<?= $isDone ? 'Готово' : 'Не готово' ?>"
       style="width:24px;height:24px;flex:0 0 24px;border-radius:7px"></a>
    <div>
      <h1 class="page-title"><?= h($n['title']) ?></h1>
      <div class="page-sub">
        <?php if ($parent): ?>
          <a class="text-reset" href="?page=node&id=<?= (int)$parent['id'] ?>"><?= h($parent['title']) ?></a> ·
        <?php endif; ?>
        <?= $isDone ? 'готово' : 'не готово' ?>
        <?php if ($n['agent_chat_id']): ?> · канал <?= h((string)$n['agent_chat_id']) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (!empty($n['github_url'])): ?>
      <a class="btn btn-dark btn-sm" href="<?= h($n['github_url']) ?>" target="_blank" rel="noopener"
         title="GitHub репозитарий">
        <svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" class="me-1" style="vertical-align:-2px"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.67-.89-3.67-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.89 3.75-3.68 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A7.995 7.995 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>GitHub
      </a>
    <?php endif; ?>
    <?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
      <button class="btn btn-light border btn-sm" type="button" data-toggle="newsub">
        <i class="bi bi-plus-lg me-1"></i>Проект
      </button>
    <?php endif; ?>
    <a class="btn btn-light border btn-sm" href="?page=node_edit&id=<?= (int)$n['id'] ?>">Изменить</a>
    <a class="btn btn-light border btn-sm" href="?page=tree<?= isset($_GET['open']) ? '&open=' . (int)$_GET['open'] : '' ?>">К списку</a>
  </div>
</div>

<?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
<div class="taddbox mb-3" id="newsub" style="display:none">
  <form method="post" action="?page=node&id=<?= (int)$n['id'] ?>" class="taddform">
    <input type="hidden" name="act" value="create_subproject">
    <input type="hidden" name="parent_id" value="<?= (int)$n['id'] ?>">
    <div class="taddrow">
      <input class="form-control form-control-sm" name="title" required maxlength="160"
             placeholder="Название подпроекта" style="max-width:320px">
      <input class="form-control form-control-sm" name="icon" maxlength="4" value="📁"
             placeholder="иконка" style="max-width:70px; text-align:center">
      <button class="btn btn-primary btn-sm" type="submit">Создать подпроект</button>
    </div>
    <div class="form-text mt-1">Подпроект — группа задач внутри направления. Его нельзя закрыть
      статусом; внутри него задачи добавляются кнопкой «+».</div>
  </form>
</div>
<?php endif; ?>

<?php if (!empty($n['goal']) || !empty($n['deadline'])): ?>
  <div class="card mb-3">
    <div class="card-body small">
      <?php if (!empty($n['goal'])): ?>
        <div><b>Чего добиться:</b> <?= h((string)$n['goal']) ?></div>
      <?php endif; ?>
      <?php if (!empty($n['deadline'])): ?>
        <div><b>Срок:</b> <?= h((string)$n['deadline']) ?></div>
      <?php endif; ?>
      <?php if (!empty($n['stage_goal'])): ?>
        <div class="muted"><b>Цель этапа:</b> <?= h((string)$n['stage_goal']) ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if (!empty($n['result_note'])): ?>
  <div class="card mb-3">
    <div class="card-header py-2">Разбор результата</div>
    <div class="card-body small" style="white-space:pre-wrap"><?= h((string)$n['result_note']) ?></div>
  </div>
<?php endif; ?>
<?php if (!empty($n['ready_at'])): ?>
  <div class="card mb-3">
    <div class="card-body small d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-clock me-1"></i>Выдача агенту: <b><?= h(dispatch_human((string)$n['ready_at'])) ?></b></span>
      <?php if (!empty($n['auto_chain'])): ?>
        <span class="muted">цепочка <?= h((string)$n['auto_chain']) ?>, шаг <?= (int)($n['auto_step'] ?? 0) ?></span>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if (!empty($n['task_desc'])): ?>
  <div class="card mb-3">
    <div class="card-header py-2">Описание задачи (это видит агент)</div>
    <div class="card-body small" style="white-space:pre-wrap"><?= h((string)$n['task_desc']) ?></div>
  </div>
<?php endif; ?>

<?php if ($n['summary']): ?>
  <div class="card mb-3"><div class="card-body" style="white-space:pre-wrap"><?= h($n['summary']) ?></div></div>
<?php endif; ?>

<?php if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)): ?>
  <div class="card mb-3">
    <div class="card-header">Периодические задачи</div>
    <div class="card-body">
      <form method="post" class="d-flex gap-2 mb-2 flex-wrap">
        <input type="hidden" name="act" value="recurring_add">
        <input type="hidden" name="project_id" value="<?= (int)$n['id'] ?>">
        <input type="number" name="every_min" class="form-control form-control-sm" style="width:120px"
               placeholder="Минуты" min="1" value="60" required>
        <input type="text" name="title" class="form-control form-control-sm" style="min-width:260px"
               placeholder="Задача — что периодически спросить у агента" required>
        <button class="btn btn-sm btn-primary">Добавить</button>
      </form>
      <?php if (!$recs): ?>
        <div class="muted small">Пока нет. Например: каждые 60 минут — «Сделай чекап конвейера заявок».</div>
      <?php endif; ?>
      <?php foreach ($recs as $r): ?>
        <div class="d-flex justify-content-between align-items-center gap-2 border-top py-1 small">
          <span>каждые <b><?= (int)$r['every_min'] ?> мин</b> — <?= h((string)$r['title']) ?></span>
          <span class="d-flex align-items-center gap-2">
            <span class="muted"><?= $r['last_sent'] ? 'последний: ' . h(dt((string)$r['last_sent'], 'd.m H:i')) : 'ещё не отдавалась' ?></span>
            <form method="post" class="d-inline">
              <input type="hidden" name="act" value="recurring_del">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Удалить периодическую задачу">удалить</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)): ?>
  <?php
  require_once __DIR__ . '/../lib/roadmap.php';
  $nid = (int)$n['id'];
  $rmPinned = roadmap_current($nid);
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>RoadMap — план работ</span>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="?page=roadmap&project=<?= (int)$nid ?>">Конструктор</a>
        <a class="btn btn-sm btn-outline-secondary" href="?page=log&project=<?= (int)$nid ?>" title="Полный лог событий с фильтрами">
          <i class="bi bi-journal-text me-1"></i>Лог
        </a>
      </div>
    </div>
    <div class="card-body">
      <a class="btn btn-sm btn-outline-primary" href="?page=orch&id=<?= (int)$nid ?>">
        <i class="bi bi-diagram-2 me-1"></i>Оркестрация
      </a>
    </div>
  </div>

  <!-- ГЕНЕРАЦИЯ ЗАДАЧ (MLL) -->
  <?php
  require_once __DIR__ . '/../lib/mll.php';
  require_once __DIR__ . '/../lib/models.php';
  $mllList = mll_models();
  $curMll  = (string)(project_model($nid) ?: '');
  if ($curMll === '' && $mllList) {
      $curMll = (string)($mllList[0]['id'] ?? '');
  }
  $genSteps = $_SESSION['mll_steps'] ?? [];
  $genUsage = $_SESSION['mll_usage'] ?? null;
  $backHere = '?page=node&id=' . $nid;
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-stars me-1"></i>Генерация задач (MLL)</span>
      <span class="muted small">на входе: лог · задачи · апрувнутый RoadMap</span>
    </div>
    <div class="card-body">
      <form method="post" class="d-flex gap-2 flex-wrap align-items-end mb-2">
        <input type="hidden" name="act" value="mll_project_generate">
        <input type="hidden" name="project_id" value="<?= $nid ?>">
        <div>
          <label class="small muted d-block">Модель</label>
          <select name="model" class="form-select form-select-sm" style="min-width:240px">
            <?php foreach ($mllList as $mm): ?>
              <option value="<?= h((string)($mm['id'] ?? '')) ?>" <?= $curMll === (string)($mm['id'] ?? '') ? 'selected' : '' ?>>
                <?= h((string)($mm['label'] ?? ($mm['id'] ?? ''))) ?><?= !empty($mm['free']) ? ' · free' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1; min-width:260px">
          <label class="small muted d-block">Куда развивать (опционально)</label>
          <input name="hint" class="form-control form-control-sm" maxlength="300"
                 placeholder="например: сначала починить конвейер выплат" value="<?= h((string)($_SESSION['mll_hint'] ?? '')) ?>">
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-stars me-1"></i>Сгенерировать задачи</button>
      </form>

      <?php if ($genSteps): ?>
        <form method="post">
          <input type="hidden" name="act" value="mll_add_many">
          <input type="hidden" name="back" value="<?= h($backHere) ?>">
          <input type="hidden" name="parent" value="<?= $nid ?>">
          <div class="small muted mb-1">
            Предложено шагов: <?= count($genSteps) ?>
            <?php if ($genUsage): ?>
              · <?= h((string)$genUsage['model']) ?> · $<?= number_format((float)$genUsage['cost'], 6) ?>
            <?php endif; ?>
            — отметь нужные и добавь в план:
          </div>
          <?php
          $genDetails = $_SESSION['mll_details'] ?? [];
          foreach ($genSteps as $i => $st):
              $gd = (string)($genDetails[$i] ?? '');
              ?>
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="pick[]" value="<?= (int)$i ?>" id="mllp<?= (int)$i ?>" checked>
              <label class="form-check-label small w-100" for="mllp<?= (int)$i ?>">
                <input type="text" name="step<?= (int)$i ?>" class="form-control form-control-sm w-100"
                       value="<?= h(rm_one_field(['title' => (string)$st, 'detail' => $gd])) ?>">
              </label>
            </div>
          <?php endforeach; ?>
          <div class="d-flex gap-2 mt-2">
            <button class="btn btn-sm btn-success">Добавить отмеченные в план</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($backHere) ?>&act=mll_clear&back=<?= urlencode($backHere) ?>">Очистить</a>
          </div>
        </form>
      <?php else: ?>
        <div class="muted small">MLL ещё не предлагал шагов для этого проекта. Он увидит лог, текущие задачи и утверждённый RoadMap — и сможет предложить шаг на исправление, если задача развёрнута неверно.</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <span><?= $isTask ? 'Лог задачи' : 'Лог агента' ?></span>
        <span class="d-flex gap-2">
          <a class="btn btn-sm btn-light border"
             href="?page=node&amp;id=<?= (int)$n['id'] ?>&amp;act=export_log<?php
                if ($isTask): echo '&amp;task_id=' . (int)$n['id'];
                else: echo '&amp;project_id=' . (int)$n['id']; endif; ?>"
             title="Скачать лог текстом">Скачать лог</a>
          <button class="btn btn-sm btn-light border" onclick="location.href='?page=node&id=<?= (int)$n['id'] ?>&amp;act=import_chat'">Забрать из чата</button>
        </span>
      </div>
      <div class="card-body">
        <div class="timeline">
          <?php if (!$events): ?>
            <div class="empty-state py-4"><i class="bi bi-journal-text"></i>Пока пусто</div>
          <?php else: foreach ($events as $ev): ?>
            <div class="tl-item">
              <span class="tl-dot <?= h($ev['level']) ?>"></span>
              <div class="tl-time"><?= h(dt($ev['ts'], 'd.m H:i')) ?> · <?= h($ev['kind']) ?></div>
              <div class="tl-text"><?= h($ev['text'] ?: '') ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if ($siblings): ?>
      <div class="card mb-3">
        <div class="card-header">Задачи по порядку</div>
        <div class="card-body p-2">
          <?php foreach ($siblings as $s): ?>
            <div class="trow <?= $s['status'] === 'done' ? 'is-done' : '' ?>">
              <a class="tcheck <?= $s['status'] === 'done' ? 'on' : '' ?>"
                 href="?page=node&id=<?= (int)$s['id'] ?>&act=toggle" title="переключить"></a>
              <a class="ttext text-reset" href="?page=node&id=<?= (int)$s['id'] ?>"><?= h($s['title']) ?></a>
              <?php if (!empty($s['ready_at']) && $s['status'] !== 'done'): ?>
                <span class="muted small ms-1" title="выдача агенту">⏱ <?= h(dispatch_human((string)$s['ready_at'])) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Действия</div>
      <div class="card-body d-flex flex-column gap-2">
        <?php if ($next): ?>
          <a class="btn btn-outline-primary" href="?page=node&id=<?= (int)$next['id'] ?>">
            <i class="bi bi-arrow-right me-1"></i>Следующая: <?= h(mb_substr($next['title'], 0, 40)) ?>
          </a>
        <?php endif; ?>
        <?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
          <button class="btn btn-outline-primary w-100" type="button" data-toggle="newsub">
            <i class="bi bi-plus-lg me-1"></i>Проект (подпроект)
          </button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="?page=dispatch_new&project=<?= (int)$n['id'] ?>">
          <i class="bi bi-send me-1"></i>Поставить задачу агенту
        </a>
        <form method="post" action="?page=node&id=<?= (int)$n['id'] ?>" onsubmit="return confirm('Удалить задачу?')">
          <input type="hidden" name="act" value="delete">
          <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Удалить</button>
        </form>
      </div>
    </div>
  </div>
</div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if ($siblings): ?>
      <div class="card mb-3">
        <div class="card-header">Задачи по порядку</div>
        <div class="card-body p-2">
          <?php foreach ($siblings as $s): ?>
            <div class="trow <?= $s['status'] === 'done' ? 'is-done' : '' ?>">
              <a class="tcheck <?= $s['status'] === 'done' ? 'on' : '' ?>"
                 href="?page=node&id=<?= (int)$s['id'] ?>&act=toggle" title="переключить"></a>
              <a class="ttext text-reset" href="?page=node&id=<?= (int)$s['id'] ?>"><?= h($s['title']) ?></a>
              <?php if (!empty($s['ready_at']) && $s['status'] !== 'done'): ?>
                <span class="muted small ms-1" title="выдача агенту">⏱ <?= h(dispatch_human((string)$s['ready_at'])) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Действия</div>
      <div class="card-body d-flex flex-column gap-2">
        <?php if ($next): ?>
          <a class="btn btn-outline-primary" href="?page=node&id=<?= (int)$next['id'] ?>">
            <i class="bi bi-arrow-right me-1"></i>Следующая: <?= h(mb_substr($next['title'], 0, 40)) ?>
          </a>
        <?php endif; ?>
        <?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
          <button class="btn btn-outline-primary w-100" type="button" data-toggle="newsub">
            <i class="bi bi-plus-lg me-1"></i>Проект (подпроект)
          </button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="?page=dispatch_new&project=<?= (int)$n['id'] ?>">
          <i class="bi bi-send me-1"></i>Поставить задачу агенту
        </a>
        <form method="post" action="?page=node&id=<?= (int)$n['id'] ?>" onsubmit="return confirm('Удалить задачу?')">
          <input type="hidden" name="act" value="delete">
          <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Удалить</button>
        </form>
      </div>
    </div>
  </div>
</div>    <a class="btn btn-sm btn-outline-primary mb-3" href="?page=orch&id=<?= (int)$n['id'] ?>">
<?php
require_once __DIR__ . '/../lib/dispatch.php';
/**
 * Карточка задачи — просто.
 * Заголовок, чекбокс готово/не готово, лог агента, следующая задача.
 */
$id = req_int('id');
$n = node($id);
if (!$n) {
    echo '<div class="alert alert-warning">Задача не найдена</div>';
    return;
}
$parent = $n['parent_id'] ? node((int)$n['parent_id']) : null;
$isTask = ($n['kind'] ?? '') === 'task';
// Задачи направления: для проекта/подпроекта — со всего поддерева (вкл. подпроекты-серверы)
if ($isTask) {
    $siblings = $parent
        ? all("SELECT id, title, status, pos, prio, ready_at, auto_chain, auto_step FROM projects
               WHERE parent_id = ? AND kind='task' ORDER BY prio, pos, id",
              'i', [(int)$parent['id']])
        : [];
} else {
    $taskScope = direction_scope_ids((int)$n['id']);
    $ph = implode(',', array_fill(0, count($taskScope), '?'));
    $siblings = all("SELECT id, title, status, pos, prio, ready_at, auto_chain, auto_step FROM projects
                     WHERE parent_id IN ($ph) AND kind='task' ORDER BY prio, pos, id",
                    str_repeat('i', count($taskScope)), $taskScope);
}
// Лог задачи — сообщения, относящиеся к ней; если их нет, показываем лог направления
$isTask = ($n['kind'] ?? '') === 'task';
$events = [];
if ($isTask) {
    $events = all("SELECT * FROM events WHERE task_id = ? ORDER BY id DESC LIMIT 300", 'i', [$id]);
}
if (!$events) {
    $scopeId = $isTask && $parent ? (int)$parent['id'] : $id;
    $events = all("SELECT * FROM events WHERE project_id = ? ORDER BY id DESC LIMIT 300", 'i', [$scopeId]);
}
$isDone = $n['status'] === 'done';
// Периодические задачи — только для проектов и подпроектов
$recs = [];
if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)) {
    require_once __DIR__ . '/../lib/recurring.php';
    $recs = recurring_list((int)$n['id']);
}
$next = null;
foreach ($siblings as $s) {
    if ((int)$s['pos'] > (int)$n['pos'] && $s['status'] !== 'done') {
        $next = $s;
        break;
    }
}
?>
<div class="page-head">
  <div class="d-flex align-items-center gap-3">
    <a class="tcheck <?= $isDone ? 'on' : '' ?>"
       href="?page=node&id=<?= (int)$n['id'] ?>&act=toggle"
       title="<?= $isDone ? 'Готово' : 'Не готово' ?>"
       style="width:24px;height:24px;flex:0 0 24px;border-radius:7px"></a>
    <div>
      <h1 class="page-title"><?= h($n['title']) ?></h1>
      <div class="page-sub">
        <?php if ($parent): ?>
          <a class="text-reset" href="?page=node&id=<?= (int)$parent['id'] ?>"><?= h($parent['title']) ?></a> ·
        <?php endif; ?>
        <?= $isDone ? 'готово' : 'не готово' ?>
        <?php if ($n['agent_chat_id']): ?> · канал <?= h((string)$n['agent_chat_id']) ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (!empty($n['github_url'])): ?>
      <a class="btn btn-dark btn-sm" href="<?= h($n['github_url']) ?>" target="_blank" rel="noopener"
         title="GitHub репозитарий">
        <svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" class="me-1" style="vertical-align:-2px"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.67-.89-3.67-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.89 3.75-3.68 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A7.995 7.995 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>GitHub
      </a>
    <?php endif; ?>
    <?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
      <button class="btn btn-light border btn-sm" type="button" data-toggle="newsub">
        <i class="bi bi-plus-lg me-1"></i>Проект
      </button>
    <?php endif; ?>
    <a class="btn btn-light border btn-sm" href="?page=node_edit&id=<?= (int)$n['id'] ?>">Изменить</a>
    <a class="btn btn-light border btn-sm" href="?page=tree<?= isset($_GET['open']) ? '&open=' . (int)$_GET['open'] : '' ?>">К списку</a>
  </div>
</div>

<?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
<div class="taddbox mb-3" id="newsub" style="display:none">
  <form method="post" action="?page=node&id=<?= (int)$n['id'] ?>" class="taddform">
    <input type="hidden" name="act" value="create_subproject">
    <input type="hidden" name="parent_id" value="<?= (int)$n['id'] ?>">
    <div class="taddrow">
      <input class="form-control form-control-sm" name="title" required maxlength="160"
             placeholder="Название подпроекта" style="max-width:320px">
      <input class="form-control form-control-sm" name="icon" maxlength="4" value="📁"
             placeholder="иконка" style="max-width:70px; text-align:center">
      <button class="btn btn-primary btn-sm" type="submit">Создать подпроект</button>
    </div>
    <div class="form-text mt-1">Подпроект — группа задач внутри направления. Его нельзя закрыть
      статусом; внутри него задачи добавляются кнопкой «+».</div>
  </form>
</div>
<?php endif; ?>

<?php if (!empty($n['goal']) || !empty($n['deadline'])): ?>
  <div class="card mb-3">
    <div class="card-body small">
      <?php if (!empty($n['goal'])): ?>
        <div><b>Чего добиться:</b> <?= h((string)$n['goal']) ?></div>
      <?php endif; ?>
      <?php if (!empty($n['deadline'])): ?>
        <div><b>Срок:</b> <?= h((string)$n['deadline']) ?></div>
      <?php endif; ?>
      <?php if (!empty($n['stage_goal'])): ?>
        <div class="muted"><b>Цель этапа:</b> <?= h((string)$n['stage_goal']) ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if (!empty($n['result_note'])): ?>
  <div class="card mb-3">
    <div class="card-header py-2">Разбор результата</div>
    <div class="card-body small" style="white-space:pre-wrap"><?= h((string)$n['result_note']) ?></div>
  </div>
<?php endif; ?>
<?php if (!empty($n['ready_at'])): ?>
  <div class="card mb-3">
    <div class="card-body small d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-clock me-1"></i>Выдача агенту: <b><?= h(dispatch_human((string)$n['ready_at'])) ?></b></span>
      <?php if (!empty($n['auto_chain'])): ?>
        <span class="muted">цепочка <?= h((string)$n['auto_chain']) ?>, шаг <?= (int)($n['auto_step'] ?? 0) ?></span>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php if (!empty($n['task_desc'])): ?>
  <div class="card mb-3">
    <div class="card-header py-2">Описание задачи (это видит агент)</div>
    <div class="card-body small" style="white-space:pre-wrap"><?= h((string)$n['task_desc']) ?></div>
  </div>
<?php endif; ?>

<?php if ($n['summary']): ?>
  <div class="card mb-3"><div class="card-body" style="white-space:pre-wrap"><?= h($n['summary']) ?></div></div>
<?php endif; ?>

<?php if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)): ?>
  <div class="card mb-3">
    <div class="card-header">Периодические задачи</div>
    <div class="card-body">
      <form method="post" class="d-flex gap-2 mb-2 flex-wrap">
        <input type="hidden" name="act" value="recurring_add">
        <input type="hidden" name="project_id" value="<?= (int)$n['id'] ?>">
        <input type="number" name="every_min" class="form-control form-control-sm" style="width:120px"
               placeholder="Минуты" min="1" value="60" required>
        <input type="text" name="title" class="form-control form-control-sm" style="min-width:260px"
               placeholder="Задача — что периодически спросить у агента" required>
        <button class="btn btn-sm btn-primary">Добавить</button>
      </form>
      <?php if (!$recs): ?>
        <div class="muted small">Пока нет. Например: каждые 60 минут — «Сделай чекап конвейера заявок».</div>
      <?php endif; ?>
      <?php foreach ($recs as $r): ?>
        <div class="d-flex justify-content-between align-items-center gap-2 border-top py-1 small">
          <span>каждые <b><?= (int)$r['every_min'] ?> мин</b> — <?= h((string)$r['title']) ?></span>
          <span class="d-flex align-items-center gap-2">
            <span class="muted"><?= $r['last_sent'] ? 'последний: ' . h(dt((string)$r['last_sent'], 'd.m H:i')) : 'ещё не отдавалась' ?></span>
            <form method="post" class="d-inline">
              <input type="hidden" name="act" value="recurring_del">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Удалить периодическую задачу">удалить</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)): ?>
  <?php
  require_once __DIR__ . '/../lib/roadmap.php';
  $rmPinned = roadmap_current((int)$n['id']);
  $rmCount  = count(roadmap_versions((int)$n['id']));
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <!-- ПЛАН НА СЕГОДНЯ -->
        <?php
        $todayPlan = null;
        try {
            require_once __DIR__ . '/../lib/plan.php';
            $todayPlan = plan_current((int)$id);
        } catch (Throwable $e) {
            $todayPlan = null;
        }
        ?>
        <div class="card mb-3">
          <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-calendar-check me-1"></i>План на сегодня</span>
            <?php if ($todayPlan): ?>
              <span class="muted small"><?= h(date('d.m.Y', strtotime((string)$todayPlan['day']))) ?></span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!$todayPlan): ?>
              <div class="muted small">Плана на сегодня нет. Его задаёт глобальный MLL: зафиксируй цель дня в глобальном чате — и задачи этого направления будут сразу знать, чего добиться и к какому сроку.</div>
            <?php else: ?>
              <div class="small mb-1"><b>Цель дня:</b> <?= h((string)$todayPlan['goal']) ?></div>
              <?php if (!empty($todayPlan['metric'])): ?>
                <div class="small muted mb-1">Метрика: <?= h((string)$todayPlan['metric']) ?></div>
              <?php endif; ?>
              <?php if (!empty($todayPlan['why'])): ?>
                <div class="small muted mb-1">Почему: <?= h((string)$todayPlan['why']) ?></div>
              <?php endif; ?>
              <?php if (!empty($todayPlan['first_move'])): ?>
                <div class="small mb-1">Первое действие: <?= h((string)$todayPlan['first_move']) ?></div>
              <?php endif; ?>
              <form method="post" class="d-flex gap-2 align-items-center mt-2">
                <input type="hidden" name="act" value="plan_save">
                <input type="hidden" name="project_id" value="<?= (int)$id ?>">
                <input type="hidden" name="day" value="<?= h(date('Y-m-d')) ?>">
                <input class="form-control form-control-sm" name="goal" value="<?= h((string)$todayPlan['goal']) ?>" placeholder="Цель дня">
                <input class="form-control form-control-sm" name="metric" style="max-width:180px" value="<?= h((string)$todayPlan['metric']) ?>" placeholder="Метрика">
                <button class="btn btn-sm btn-outline-primary">Сохранить</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- ПЛАНИРОВЩИК ВЫДАЧИ -->
        <div class="card mb-3">
          <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-clock-history me-1"></i>Планировщик выдачи</span>
            <span class="muted small">MSK (UTC+3) — задачи не уходят мгновенно</span>
          </div>
          <div class="card-body">
            <?php
            $ds = dispatch_settings((int)$id);
            $dNext = null;
            foreach (all("SELECT id, title, ready_at FROM projects
                          WHERE parent_id=? AND kind='task' AND status<>'done'
                          ORDER BY prio, pos, id LIMIT 1", 'i', [$id]) as $cand) {
                $dNext = $cand;
                break;
            }
            ?>
            <form method="post" class="row g-2 align-items-end mb-2">
              <input type="hidden" name="act" value="dispatch_settings">
              <input type="hidden" name="project_id" value="<?= (int)$id ?>">
              <input type="hidden" name="back" value="?page=node&id=<?= (int)$id ?>">
              <div class="col-6 col-md-2">
                <label class="small muted d-block">Не раньше (МСК)</label>
                <input class="form-control form-control-sm" name="window_from" placeholder="19:55" value="<?= h((string)$ds['window_from']) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="small muted d-block">Не позже (МСК)</label>
                <input class="form-control form-control-sm" name="window_to" placeholder="23:59" value="<?= h((string)$ds['window_to']) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="small muted d-block">Мин. пауза, мин</label>
                <input type="number" min="0" max="1440" class="form-control form-control-sm" name="min_gap_min" value="<?= (int)$ds['min_gap_min'] ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="small muted d-block">Макс. пауза, мин</label>
                <input type="number" min="0" max="1440" class="form-control form-control-sm" name="max_gap_min" value="<?= (int)$ds['max_gap_min'] ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="small muted d-block">Пауза автопилота, мин</label>
                <input type="number" min="0" max="1440" class="form-control form-control-sm" name="auto_next_delay_min" value="<?= (int)$ds['auto_next_delay_min'] ?>">
              </div>
              <div class="col-6 col-md-3 d-flex gap-3">
                <label class="small"><input type="checkbox" name="adaptive" value="1" <?= (int)$ds['adaptive'] === 1 ? 'checked' : '' ?>> по производительности</label>
                <label class="small"><input type="checkbox" name="auto_next" value="1" <?= (int)$ds['auto_next'] === 1 ? 'checked' : '' ?>> автопилот</label>
              </div>
              <div class="col-12 col-md-3">
                <button class="btn btn-sm btn-outline-primary">Сохранить</button>
              </div>
            </form>
            <div class="small muted">
              Окно выдачи: <?= (string)$ds['window_from'] !== '' ? 'с ' . h((string)$ds['window_from']) : 'без ограничения снизу' ?>
              <?= (string)$ds['window_to'] !== '' ? ' до ' . h((string)$ds['window_to']) : '' ?> (МСК).
              Пауза между задачами: база <?= (int)$ds['min_gap_min'] ?> мин<?= (int)$ds['adaptive'] === 1 ? ' + по факту работы агента' : '' ?>.
              Автопилот: следующая задача готовится сама по завершении текущей<?= (int)$ds['auto_next_delay_min'] > 0 ? ' (+' . (int)$ds['auto_next_delay_min'] . ' мин)' : '' ?>.
              <?php if ($dNext): ?>
                Ближайшая задача: <b><?= h(mb_substr((string)$dNext['title'], 0, 70)) ?></b> — выдача <?= h(dispatch_human((string)($dNext['ready_at'] ?? ''))) ?>.
              <?php endif; ?>
            </div>
          </div>
        </div>

      <span>RoadMap — план работ</span>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="?page=roadmap&project=<?= (int)$n['id'] ?>">Конструктор</a>
        <a class="btn btn-sm btn-outline-secondary" href="?page=log&project=<?= (int)$n['id'] ?>" title="Полный лог событий с фильтрами">
          <i class="bi bi-journal-text me-1"></i>Лог
        </a>
      </div>
    </div>
    <div class="card-body">
      <?php
      $nid = (int)$n['id'];
      ?>
      <a cl
    </div>
  </div>
<?php endif; ?>

<?php if (in_array($n['kind'] ?? '', ['project', 'subproject'], true)): ?>
  <?php
  $todayTasks = all("SELECT t.id, t.title, t.status, t.created_at, t.updated_at, p.title AS proj
                     FROM projects t JOIN projects p ON p.id=t.parent_id
                     WHERE t.kind='task' AND (t.parent_id=? OR p.parent_id=?)
                       AND (DATE(t.created_at)=CURDATE() OR DATE(t.updated_at)=CURDATE())
                     ORDER BY t.id DESC LIMIT 30", 'ii', [$nid, $nid]);
  $openTasks = (int)(scalar("SELECT COUNT(*) FROM projects t JOIN projects p ON p.id=t.parent_id
                             WHERE t.kind='task' AND t.status<>'done' AND (t.parent_id=? OR p.parent_id=?)",
                             'ii', [$nid, $nid]) ?? 0);
  $allTasks = (int)(scalar("SELECT COUNT(*) FROM projects t JOIN projects p ON p.id=t.parent_id
                            WHERE t.kind='task' AND (t.parent_id=? OR p.parent_id=?)",
                            'ii', [$nid, $nid]) ?? 0);
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Задачи за сегодня</span>
      <span class="muted small">за сегодня: <?= count($todayTasks) ?> · всего: <?= $allTasks ?> · открытых: <?= $openTasks ?></span>
    </div>
    <div class="card-body">
      <?php if (!$todayTasks): ?>
        <div class="muted small">Сегодня задач не было — ни создано, ни обновлено.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead><tr><th style="width:44px">#</th><th>Задача</th><th style="width:130px">Статус</th>
              <th style="width:120px">Создана</th><th style="width:120px">Обновлена</th></tr></thead>
            <tbody>
            <?php foreach ($todayTasks as $tt): ?>
              <tr class="small">
                <td class="muted"><?= (int)$tt['id'] ?></td>
                <td><a href="?page=node&id=<?= (int)$tt['id'] ?>"><?= h((string)$tt['title']) ?></a></td>
                <td><span class="badge <?= (string)$tt['status'] === 'done' ? 'bg-success' : 'text-bg-light' ?>"><?= h((string)$tt['status']) ?></span></td>
                <td class="muted"><?= h(dt((string)$tt['created_at'], 'd.m H:i')) ?></td>
                <td class="muted"><?= h(dt((string)$tt['updated_at'], 'd.m H:i')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ГЕНЕРАЦИЯ ЗАДАЧ (MLL) -->
  <?php
  require_once __DIR__ . '/../lib/mll.php';
  require_once __DIR__ . '/../lib/models.php';
  $mllList = mll_models();
  $curMll  = (string)(project_model($nid) ?: '');
  if ($curMll === '' && $mllList) {
      $curMll = (string)($mllList[0]['id'] ?? '');
  }
  $genSteps = $_SESSION['mll_steps'] ?? [];
  $genUsage = $_SESSION['mll_usage'] ?? null;
  $backHere = '?page=node&id=' . $nid;
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-stars me-1"></i>Генерация задач (MLL)</span>
      <span class="muted small">на входе: лог · задачи · апрувнутый RoadMap</span>
    </div>
    <div class="card-body">
      <form method="post" class="d-flex gap-2 flex-wrap align-items-end mb-2">
        <input type="hidden" name="act" value="mll_project_generate">
        <input type="hidden" name="project_id" value="<?= $nid ?>">
        <div>
          <label class="small muted d-block">Модель</label>
          <select name="model" class="form-select form-select-sm" style="min-width:240px">
            <?php foreach ($mllList as $mm): ?>
              <option value="<?= h((string)($mm['id'] ?? '')) ?>" <?= $curMll === (string)($mm['id'] ?? '') ? 'selected' : '' ?>>
                <?= h((string)($mm['label'] ?? ($mm['id'] ?? ''))) ?><?= !empty($mm['free']) ? ' · free' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1; min-width:260px">
          <label class="small muted d-block">Куда развивать (опционально)</label>
          <input name="hint" class="form-control form-control-sm" maxlength="300"
                 placeholder="например: сначала починить конвейер выплат" value="<?= h((string)($_SESSION['mll_hint'] ?? '')) ?>">
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-stars me-1"></i>Сгенерировать задачи</button>
      </form>

      <?php if ($genSteps): ?>
        <form method="post">
          <input type="hidden" name="act" value="mll_add_many">
          <input type="hidden" name="back" value="<?= h($backHere) ?>">
          <input type="hidden" name="parent" value="<?= $nid ?>">
          <div class="small muted mb-1">
            Предложено шагов: <?= count($genSteps) ?>
            <?php if ($genUsage): ?>
              · <?= h((string)$genUsage['model']) ?> · $<?= number_format((float)$genUsage['cost'], 6) ?>
            <?php endif; ?>
            — отметь нужные и добавь в план:
          </div>
          <?php
          $genDetails = $_SESSION['mll_details'] ?? [];
          foreach ($genSteps as $i => $st):
              $gd = (string)($genDetails[$i] ?? '');
              ?>
            <div class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="pick[]" value="<?= (int)$i ?>" id="mllp<?= (int)$i ?>" checked>
              <label class="form-check-label small w-100" for="mllp<?= (int)$i ?>">
                <input type="text" name="step<?= (int)$i ?>" class="form-control form-control-sm w-100"
                       value="<?= h(rm_one_field(['title' => (string)$st, 'detail' => $gd])) ?>">
              </label>
            </div>
          <?php endforeach; ?>
          <div class="d-flex gap-2 mt-2">
            <button class="btn btn-sm btn-success">Добавить отмеченные в план</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($backHere) ?>&act=mll_clear&back=<?= urlencode($backHere) ?>">Очистить</a>
          </div>
        </form>
      <?php else: ?>
        <div class="muted small">MLL ещё не предлагал шагов для этого проекта. Он увидит лог, текущие задачи и утверждённый RoadMap — и сможет предложить шаг на исправление, если задача развёрнута неверно.</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <span><?= $isTask ? 'Лог задачи' : 'Лог агента' ?></span>
        <span class="d-flex gap-2">
          <a class="btn btn-sm btn-light border"
             href="?page=node&amp;id=<?= (int)$n['id'] ?>&amp;act=export_log<?php
                if ($isTask): echo '&amp;task_id=' . (int)$n['id'];
                else: echo '&amp;project_id=' . (int)$n['id']; endif; ?>"
             title="Скачать лог текстом">Скачать лог</a>
          <button class="btn btn-sm btn-light border" onclick="location.href='?page=node&id=<?= (int)$n['id'] ?>&amp;act=import_chat'">Забрать из чата</button>
        </span>
      </div>
      <div class="card-body">
        <div class="timeline">
          <?php if (!$events): ?>
            <div class="empty-state py-4"><i class="bi bi-journal-text"></i>Пока пусто</div>
          <?php else: foreach ($events as $ev): ?>
            <div class="tl-item">
              <span class="tl-dot <?= h($ev['level']) ?>"></span>
              <div class="tl-time"><?= h(dt($ev['ts'], 'd.m H:i')) ?> · <?= h($ev['kind']) ?></div>
              <div class="tl-text"><?= h($ev['text'] ?: '') ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if ($siblings): ?>
      <div class="card mb-3">
        <div class="card-header">Задачи по порядку</div>
        <div class="card-body p-2">
          <?php foreach ($siblings as $s): ?>
            <div class="trow <?= $s['status'] === 'done' ? 'is-done' : '' ?>">
              <a class="tcheck <?= $s['status'] === 'done' ? 'on' : '' ?>"
                 href="?page=node&id=<?= (int)$s['id'] ?>&act=toggle" title="переключить"></a>
              <a class="ttext text-reset" href="?page=node&id=<?= (int)$s['id'] ?>"><?= h($s['title']) ?></a>
              <?php if (!empty($s['ready_at']) && $s['status'] !== 'done'): ?>
                <span class="muted small ms-1" title="выдача агенту">⏱ <?= h(dispatch_human((string)$s['ready_at'])) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Действия</div>
      <div class="card-body d-flex flex-column gap-2">
        <?php if ($next): ?>
          <a class="btn btn-outline-primary" href="?page=node&id=<?= (int)$next['id'] ?>">
            <i class="bi bi-arrow-right me-1"></i>Следующая: <?= h(mb_substr($next['title'], 0, 40)) ?>
          </a>
        <?php endif; ?>
        <?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
          <button class="btn btn-outline-primary w-100" type="button" data-toggle="newsub">
            <i class="bi bi-plus-lg me-1"></i>Проект (подпроект)
          </button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="?page=dispatch_new&project=<?= (int)$n['id'] ?>">
          <i class="bi bi-send me-1"></i>Поставить задачу агенту
        </a>
        <form method="post" action="?page=node&id=<?= (int)$n['id'] ?>" onsubmit="return confirm('Удалить задачу?')">
          <input type="hidden" name="act" value="delete">
          <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Удалить</button>
        </form>
      </div>
    </div>
  </div>
</div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if ($siblings): ?>
      <div class="card mb-3">
        <div class="card-header">Задачи по порядку</div>
        <div class="card-body p-2">
          <?php foreach ($siblings as $s): ?>
            <div class="trow <?= $s['status'] === 'done' ? 'is-done' : '' ?>">
              <a class="tcheck <?= $s['status'] === 'done' ? 'on' : '' ?>"
                 href="?page=node&id=<?= (int)$s['id'] ?>&act=toggle" title="переключить"></a>
              <a class="ttext text-reset" href="?page=node&id=<?= (int)$s['id'] ?>"><?= h($s['title']) ?></a>
              <?php if (!empty($s['ready_at']) && $s['status'] !== 'done'): ?>
                <span class="muted small ms-1" title="выдача агенту">⏱ <?= h(dispatch_human((string)$s['ready_at'])) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Действия</div>
      <div class="card-body d-flex flex-column gap-2">
        <?php if ($next): ?>
          <a class="btn btn-outline-primary" href="?page=node&id=<?= (int)$next['id'] ?>">
            <i class="bi bi-arrow-right me-1"></i>Следующая: <?= h(mb_substr($next['title'], 0, 40)) ?>
          </a>
        <?php endif; ?>
        <?php if (in_array($n['kind'], ['project', 'subproject'], true)): ?>
          <button class="btn btn-outline-primary w-100" type="button" data-toggle="newsub">
            <i class="bi bi-plus-lg me-1"></i>Проект (подпроект)
          </button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="?page=dispatch_new&project=<?= (int)$n['id'] ?>">
          <i class="bi bi-send me-1"></i>Поставить задачу агенту
        </a>
        <form method="post" action="?page=node&id=<?= (int)$n['id'] ?>" onsubmit="return confirm('Удалить задачу?')">
          <input type="hidden" name="act" value="delete">
          <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Удалить</button>
        </form>
      </div>
    </div>
  </div>
</div>

