<?php
$evs = all("SELECT e.*, p.title AS ptitle, p.icon AS picon FROM events e
   LEFT JOIN projects p ON p.id = e.project_id
   ORDER BY e.id DESC LIMIT 300");
$q = req_str('q');
$kind = req_str('kind');
if ($q !== '' || $kind !== '') {
    $sql = "SELECT e.*, p.title AS ptitle, p.icon AS picon FROM events e
            LEFT JOIN projects p ON p.id = e.project_id WHERE 1=1";
    $types = ''; $args = [];
    if ($q !== '') { $sql .= " AND (e.text LIKE ? OR e.title LIKE ? OR p.title LIKE ?)"; $types = 'sss'; $args = ["%$q%", "%$q%", "%$q%"]; }
    if ($kind !== '') { $sql .= " AND e.kind = ?"; $types .= 's'; $args[] = $kind; }
    $sql .= " ORDER BY e.id DESC LIMIT 300";
    $evs = all($sql, $types, $args);
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Лента событий</h1>
    <div class="page-sub">Всё, что агенты отчитывались в чат и в трекер</div>
  </div>
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="page" value="events">
    <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Поиск по тексту…" style="min-width:240px">
    <select class="form-select" name="kind" style="width:150px">
      <option value="">Все типы</option>
      <?php foreach (['agent','chat','system','metric','file','note'] as $k): ?>
        <option value="<?= h($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= h($k) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Найти</button>
  </form>
</div>

<div class="card">
  <div class="card-body">
    <div class="timeline">
      <?php if (!$evs): ?>
        <div class="empty-state"><i class="bi bi-inbox"></i>Событий нет</div>
      <?php else: foreach ($evs as $ev): ?>
        <div class="tl-item">
          <span class="tl-dot <?= h($ev['level']) ?>"></span>
          <div class="tl-time">
            <?= h(dt($ev['ts'], 'd.m.Y H:i')) ?> · <?= h($ev['kind']) ?>
            <?php if ($ev['server']): ?> · <?= h($ev['server']) ?><?php endif; ?>
          </div>
          <?php if ($ev['project_id']): ?>
            <a class="small fw-semibold text-reset" href="?page=node&id=<?= (int)$ev['project_id'] ?>">
              <?= h($ev['picon'] ?: '•') ?> <?= h($ev['ptitle'] ?: '—') ?>
            </a>
          <?php endif; ?>
          <?php if ($ev['title']): ?><div class="fw-semibold small"><?= h($ev['title']) ?></div><?php endif; ?>
          <div class="tl-text"><?= h($ev['text'] ?: '') ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
