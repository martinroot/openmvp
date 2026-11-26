<?php
/* Лог проекта / направления — полная история событий с фильтрами */
$projectId = req_int('project');
if ($projectId <= 0) {
    flash('warning', 'Не выбран проект');
    header('Location: ?page=tree');
    exit;
}

$n = node($projectId);
if (!$n) {
    flash('danger', 'Проект не найден');
    header('Location: ?page=tree');
    exit;
}

// DEBUG
error_log("LOG PAGE HIT: project=$projectId, page=" . (req_str('page') ?? 'none'));

/* Определяем scope: для направления — все подпроекты и задачи */
$isDirection = in_array($n['kind'] ?? '', ['project', 'subproject'], true);
$scopeIds = direction_scope_ids($projectId);

/* Фильтры */
$level   = req_str('level', '');       // info, warning, danger, success
$kind    = req_str('kind', '');        // task_created, task_done, auto_queue, etc.
$from    = req_str('from', '');        // Y-m-d
$to      = req_str('to', '');          // Y-m-d
$search  = req_str('q', '');           // текст поиска
$limit   = req_int('limit', 500);      // сколько строк
$offset  = req_int('offset', 0);       // пагинация

$where  = ['project_id IN (' . implode(',', array_fill(0, count($scopeIds), '?')) . ')'];
$params = array_merge($scopeIds, $params ?? []);

if ($level !== '') {
    $where[] = 'level = ?';
    $params[] = $level;
}
if ($kind !== '') {
    $where[] = 'kind LIKE ?';
    $params[] = '%' . $kind . '%';
}
if ($from !== '') {
    $where[] = 'DATE(ts) >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'DATE(ts) <= ?';
    $params[] = $to;
}
if ($search !== '') {
    $where[] = '(title LIKE ? OR text LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sqlWhere = implode(' AND ', $where);

/* Всего записей для пагинации */
$total = (int)(scalar("SELECT COUNT(*) FROM events WHERE $sqlWhere", str_repeat('i', count($scopeIds)) . str_repeat('s', count($params) - count($scopeIds)), $params) ?? 0);

/* Выборка */
$events = all("SELECT * FROM events WHERE $sqlWhere ORDER BY id DESC LIMIT ? OFFSET ?", 
    str_repeat('i', count($scopeIds)) . str_repeat('s', count($params) - count($scopeIds)) . 'ii',
    array_merge($params, [$limit, $offset]));

// DEBUG
// error_log("LOG PAGE: project=$projectId, scope=" . count($scopeIds) . ", events=" . count($events) . ", total=$total");

/* Уникальные kinds для фильтра */
$kinds = all("SELECT DISTINCT kind FROM events WHERE project_id IN (" . implode(',', array_fill(0, count($scopeIds), '?')) . ") ORDER BY kind", str_repeat('i', count($scopeIds)), $scopeIds);
$kindsList = array_column($kinds, 'kind');

/* Хлебные крошки */
$path = node_path($projectId);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-1">Лог: <?= h($n['title']) ?> <small class="text-muted">(<?= h($n['kind']) ?>)</small></h4>
    <nav class="breadcrumb small mb-0" aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="?page=tree">Дерево</a></li>
        <?php foreach ($path as $p): ?>
          <li class="breadcrumb-item"><a href="?page=node&id=<?= (int)$p['id'] ?>"><?= h($p['title']) ?></a></li>
        <?php endforeach; ?>
        <li class="breadcrumb-item active" aria-current="page">Лог</li>
      </ol>
    </nav>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="?page=node&id=<?= $projectId ?>">
      <i class="bi bi-arrow-left me-1"></i>Назад к карточке
    </a>
    <a class="btn btn-primary btn-sm" href="?page=node&id=<?= $projectId ?>&act=export_log&project_id=<?= $projectId ?>">
      <i class="bi bi-download me-1"></i>Скачать полный лог
    </a>
  </div>
</div>

<!-- Фильтры -->
<div class="card mb-3">
  <div class="card-header">
    <i class="bi bi-funnel me-1"></i>Фильтры
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="page" value="log">
      <input type="hidden" name="project" value="<?= $projectId ?>">
      
      <div class="col-md-3">
        <label class="form-label small">Уровень</label>
        <select name="level" class="form-select form-select-sm">
          <option value="">Все</option>
          <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>info</option>
          <option value="success" <?= $level === 'success' ? 'selected' : '' ?>>success</option>
          <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>warning</option>
          <option value="danger" <?= $level === 'danger' ? 'selected' : '' ?>>danger</option>
        </select>
      </div>
      
      <div class="col-md-3">
        <label class="form-label small">Тип события (kind)</label>
        <select name="kind" class="form-select form-select-sm">
          <option value="">Все</option>
          <?php foreach ($kindsList as $k): ?>
            <option value="<?= h($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= h($k) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="col-md-2">
        <label class="form-label small">От даты</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>">
      </div>
      
      <div class="col-md-2">
        <label class="form-label small">До даты</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>">
      </div>
      
      <div class="col-md-3">
        <label class="form-label small">Поиск по тексту</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="текст в title или text" value="<?= h($search) ?>">
      </div>
      
      <div class="col-md-2">
        <label class="form-label small">Лимит</label>
        <select name="limit" class="form-select form-select-sm">
          <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
          <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
          <option value="1000" <?= $limit === 1000 ? 'selected' : '' ?>>1000</option>
          <option value="5000" <?= $limit === 5000 ? 'selected' : '' ?>>5000</option>
        </select>
      </div>
      
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Применить</button>
        <a href="?page=log&project=<?= $projectId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i>Сбросить</a>
      </div>
    </form>
  </div>
</div>

<!-- Инфо -->
<div class="d-flex justify-content-between align-items-center mb-2 small muted">
  <span>Всего событий: <b><?= $total ?></b> | Показано: <b><?= count($events) ?></b> | Смещение: <b><?= $offset ?></b></span>
  <div class="d-flex gap-2">
    <?php if ($offset > 0): ?>
      <a class="btn btn-sm btn-outline-secondary" href="?page=log&project=<?= $projectId ?>&level=<?= urlencode($level) ?>&kind=<?= urlencode($kind) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&q=<?= urlencode($search) ?>&limit=<?= $limit ?>&offset=<?= max(0, $offset - $limit) ?>">
        <i class="bi bi-chevron-left"></i> Назад
      </a>
    <?php endif; ?>
    <?php if ($offset + $limit < $total): ?>
      <a class="btn btn-sm btn-outline-secondary" href="?page=log&project=<?= $projectId ?>&level=<?= urlencode($level) ?>&kind=<?= urlencode($kind) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&q=<?= urlencode($search) ?>&limit=<?= $limit ?>&offset=<?= $offset + $limit ?>">
        Вперёд <i class="bi bi-chevron-right"></i>
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Таблица событий -->
<div class="card">
  <div class="card-body p-0">
    <?php if (!$events): ?>
      <div class="empty-state py-5 text-center">
        <i class="bi bi-journal-text fs-1 text-muted"></i>
        <p class="mt-2 mb-0">Событий не найдено</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:140px">Дата/время</th>
              <th style="width:80px">Уровень</th>
              <th style="width:160px">Тип (kind)</th>
              <th>Заголовок</th>
              <th style="width:400px">Текст</th>
              <th style="width:100px">Сервер / ID задачи</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($events as $ev): ?>
              <tr class="small">
                <td class="muted"><?= h(dt($ev['ts'], 'd.m H:i:s')) ?></td>
                <td><span class="badge bg-<?= h($ev['level']) ?>"><?= h($ev['level']) ?></span></td>
                <td class="muted" title="<?= h($ev['kind']) ?>"><?= h(mb_substr($ev['kind'], 0, 30)) ?></td>
                <td><?= h($ev['title'] ?: '') ?></td>
                <td class="text-wrap" style="max-width:400px"><?= h($ev['text'] ?: '') ?></td>
                <td class="muted">
                  <?php if (!empty($ev['server'])): ?>
                    <div><?= h($ev['server']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($ev['task_id'])): ?>
                    <div><a href="?page=node&id=<?= (int)$ev['task_id'] ?>">#<?= (int)$ev['task_id'] ?></a></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Пагинация внизу -->
<?php if ($total > $limit): ?>
  <nav class="mt-3 d-flex justify-content-center">
    <ul class="pagination pagination-sm mb-0">
      <?php if ($offset > 0): ?>
        <li class="page-item"><a class="page-link" href="?page=log&project=<?= $projectId ?>&level=<?= urlencode($level) ?>&kind=<?= urlencode($kind) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&q=<?= urlencode($search) ?>&limit=<?= $limit ?>&offset=<?= max(0, $offset - $limit) ?>"><i class="bi bi-chevron-left"></i></a></li>
      <?php endif; ?>
      <li class="page-item disabled"><span class="page-link">Страница <?= (int)($offset / $limit) + 1 ?> из <?= (int)ceil($total / $limit) ?></span></li>
      <?php if ($offset + $limit < $total): ?>
        <li class="page-item"><a class="page-link" href="?page=log&project=<?= $projectId ?>&level=<?= urlencode($level) ?>&kind=<?= urlencode($kind) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&q=<?= urlencode($search) ?>&limit=<?= $limit ?>&offset=<?= $offset + $limit ?>"><i class="bi bi-chevron-right"></i></a></li>
      <?php endif; ?>
    </ul>
  </nav>
<?php endif; ?>