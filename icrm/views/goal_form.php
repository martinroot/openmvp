<?php
$dayKey = date('Y-m-d');
$edit = req_int('edit');
$g = $edit ? one("SELECT * FROM goals WHERE id = ?", 'i', [$edit]) : null;
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $g ? 'Изменить цель' : 'Поставить цель' ?></h1>
    <div class="page-sub">Глобальная финансовая цель или цель дня</div>
  </div>
  <a class="btn btn-light border" href="?page=finance">К целям</a>
</div>

<form method="post" action="?page=finance">
  <input type="hidden" name="act" value="<?= $g ? 'upd_goal' : 'add_goal' ?>">
  <?php if ($g): ?><input type="hidden" name="gid" value="<?= (int)$g['id'] ?>"><?php endif; ?>
  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card">
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Скоуп</label>
            <select class="form-select" name="scope">
              <option value="global" <?= ($g['scope'] ?? 'global') === 'global' ? 'selected' : '' ?>>Глобальная (квартальная/месячная)</option>
              <option value="day" <?= ($g['scope'] ?? '') === 'day' ? 'selected' : '' ?>>Цель дня</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Период (для дня — YYYY-MM-DD)</label>
            <input class="form-control" name="period_key" value="<?= h($g['period_key'] ?? $dayKey) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Название</label>
            <input class="form-control" name="title" required value="<?= h($g['title'] ?? '') ?>" placeholder="Цель на месяц по расходу агентов">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Цель, $</label>
            <input class="form-control" name="target_usd" type="number" step="0.01" min="0" required
                   value="<?= h($g['target_usd'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Заметка</label>
            <input class="form-control" name="note" value="<?= h($g['note'] ?? '') ?>" placeholder="Что входит в цель">
          </div>
          <button class="btn btn-primary w-100">Сохранить</button>
        </div>
      </div>
    </div>
  </div>
</form>
