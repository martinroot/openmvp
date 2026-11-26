<?php
$dbOk = false; $dbErr = '';
try { db()->query("SELECT 1"); $dbOk = true; } catch (Throwable $e) { $dbErr = $e->getMessage(); }

$chatOk = false; $chatErr = ''; $chatMsgs = 0;
$ctx = stream_context_create(['http' => ['timeout' => 12, 'header' =>
    "X-Server: " . CHAT_SERVER . "\r\nX-Token: " . CHAT_TOKEN . "\r\n"]]);
$raw = @file_get_contents(CHAT_URL . '?since=0&limit=3', false, $ctx);
if ($raw !== false) {
    $j = json_decode($raw, true);
    if (is_array($j) && isset($j['messages'])) { $chatOk = true; $chatMsgs = count($j['messages']); }
    else { $chatErr = substr((string)$raw, 0, 200); }
} else {
    $chatErr = 'нет ответа';
}

$counts = [];
foreach (['projects','events','agent_runs','goals','dispatch_queue'] as $t) {
    $counts[$t] = (int)scalar("SELECT COUNT(*) FROM {$t}");
}
$phpv = PHP_VERSION;
$diskFree = @disk_free_space(ROOT);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Состояние системы</h1>
    <div class="page-sub">Проверка БД, чата агентов и наполненности трекера</div>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php
  $items = [
    ['База данных', $dbOk ? 'доступна' : 'ошибка', $dbOk ? 'ok' : 'err', $dbErr ?: 'system @ localhost'],
    ['Чат агентов', $chatOk ? 'отвечает' : 'ошибка', $chatOk ? 'ok' : 'err', $chatErr ?: ('последних сообщений: ' . $chatMsgs)],
    ['PHP', $phpv, 'ok', 'CLI/FPM 8.3'],
    ['Диск', $diskFree ? round($diskFree / 1024 / 1024 / 1024, 1) . ' GB свободно' : '—', 'ok', ROOT],
  ];
  foreach ($items as $it): ?>
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card h-100"><div class="card-body">
        <div class="kpi-label"><?= h($it[0]) ?></div>
        <div class="mt-2 d-flex align-items-center gap-2">
          <span class="tl-dot <?= $it[2] === 'ok' ? 'ok' : 'err' ?>"></span>
          <b class="fs-6"><?= h($it[1]) ?></b>
        </div>
        <div class="kpi-sub mt-2"><?= h($it[3]) ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">Наполненность</div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($counts as $t => $c): ?>
        <div class="col-6 col-md-4 col-xl-2">
          <div class="stat-inline"><b style="font-size:1.3rem"><?= $c ?></b><span><?= h($t) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
