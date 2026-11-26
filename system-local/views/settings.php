<?php
$settings = [];
foreach (all("SELECT * FROM settings") as $s) { $settings[$s['k']] = $s['v']; }
$known = [
    'chat_url'    => 'URL агентского чата',
    'chat_server' => 'Код сервера для X-Server',
    'ingest_key'  => 'Ключ ingest API',
];
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Настройки</h1>
    <div class="page-sub">Секреты хранятся в конфиге приложения, не в БД</div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header">Параметры</div>
      <div class="card-body">
        <form method="post" action="?page=settings">
          <input type="hidden" name="act" value="save_settings">
          <?php foreach ($known as $k => $lbl): ?>
            <div class="mb-3">
              <label class="form-label small fw-semibold"><?= h($lbl) ?> <code><?= h($k) ?></code></label>
              <input class="form-control mono" name="<?= h($k) ?>" value="<?= h($settings[$k] ?? '') ?>">
            </div>
          <?php endforeach; ?>
          <button class="btn btn-primary">Сохранить</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card mb-3">
      <div class="card-header">Планировщик выдачи (глобально)</div>
      <div class="card-body">
        <?php require_once __DIR__ . '/../lib/dispatch.php'; $ds = dispatch_settings(0); ?>
        <p class="small text-secondary">Задачи не уходят агенту мгновенно: окно выдачи в MSK, пауза между задачами и ожидание по факту производительности агента. Направления могут переопределить эти значения в своей карточке.</p>
        <form method="post" action="?page=settings">
          <input type="hidden" name="act" value="save_dispatch_settings">
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Не раньше (МСК)</label>
              <input class="form-control" name="window_from" placeholder="19:55" value="<?= h((string)$ds['window_from']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Не позже (МСК)</label>
              <input class="form-control" name="window_to" placeholder="23:59" value="<?= h((string)$ds['window_to']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Мин. пауза, мин</label>
              <input type="number" min="0" max="1440" class="form-control" name="min_gap_min" value="<?= (int)$ds['min_gap_min'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Макс. пауза, мин</label>
              <input type="number" min="0" max="1440" class="form-control" name="max_gap_min" value="<?= (int)$ds['max_gap_min'] ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Пауза автопилота, мин</label>
              <input type="number" min="0" max="1440" class="form-control" name="auto_next_delay_min" value="<?= (int)$ds['auto_next_delay_min'] ?>">
            </div>
            <div class="col-6 d-flex align-items-end gap-3 pb-1">
              <label class="small"><input type="checkbox" name="adaptive" value="1" <?= (int)$ds['adaptive'] === 1 ? 'checked' : '' ?>> по производительности</label>
              <label class="small"><input type="checkbox" name="auto_next" value="1" <?= (int)$ds['auto_next'] === 1 ? 'checked' : '' ?>> автопилот</label>
            </div>
          </div>
          <button class="btn btn-primary">Сохранить планировщик</button>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Ingest API</div>
      <div class="card-body">
        <p class="small text-secondary">Агенты шлют метрики запуска (in/cache/out, модель, стоимость, время) POST-запросом:</p>
        <pre class="bg-light p-2 rounded small mono mb-2">POST /api/ingest.php
X-Ingest-Key: &lt;key&gt;
Content-Type: application/json

{
  "agent_code": "edge-147",
  "server": "edge-147",
  "model": "minimax-m3.1-flash:free",
  "project_ref": "adult",
  "tok_in": 12000,
  "tok_cache": 34000,
  "tok_out": 900,
  "cost_usd": 0.0123,
  "duration_ms": 5200
}</pre>
        <p class="small text-secondary mb-0">События в лог задачи:
          <code>POST /api/event.php</code> с <code>project_ref</code>, <code>kind</code>, <code>text</code>, <code>level</code>.</p>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Схема БД</div>
      <div class="card-body small text-secondary">
        <div class="mono mb-2">projects · events · agent_runs · goals · settings · dispatch_queue</div>
        <div>Дерево — <code>projects.parent_id</code>, глубина до 5 уровней.</div>
      </div>
    </div>
  </div>
</div>
