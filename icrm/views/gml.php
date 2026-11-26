<?php
declare(strict_types=1);
/**
 * Глобальный MLL — чат главного управляющего сетью направлений.
 *
 * На вход: все RoadMap, открытые и недавно закрытые задачи, пояснения по
 * направлениям, переписка. Ответ — список конкретных задач по глобальному
 * приоритету (развитие, капитализация, фокус).
 */
require_once __DIR__ . '/../lib/gml.php';

$chat     = gml_chat(120);
$dirs     = gml_directions();
$models   = mll_models();
$curModel = (string)(models_setting('gml_model', ''));
if ($curModel === '' && $models) {
    $curModel = (string)($models[0]['id'] ?? '');
}
$hint    = (string)($_SESSION['gml_hint'] ?? '');
$logDepth = gml_log_depth();
$levels   = gml_delegation_levels();
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <h4 class="mb-0">Глобальный MLL — главный управляющий</h4>
    <div class="small muted">на входе: все RoadMap · открытые и закрытые задачи · пояснения по направлениям</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" onsubmit="return confirm('Очистить переписку?')">
      <input type="hidden" name="act" value="gml_clear">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-eraser me-1"></i>Очистить чат</button>
    </form>
    <form method="post">
      <input type="hidden" name="act" value="export_gml_chat">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Скачать чат</button>
    </form>
  </div>
</div>

<div class="row g-3">
  <!-- ЧАТ -->
  <div class="col-12 col-xl-7">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-chat-square-dots me-1"></i>Переписка с главным управляющим</span>
        <span class="muted small">контекст сохраняется</span>
      </div>
      <div class="card-body" style="max-height:54vh; overflow:auto">
        <?php if (!$chat): ?>
          <div class="muted small">Переписка пуста. Спроси, куда двигать сеть: приоритеты, капитализация направлений, фокус.</div>
        <?php endif; ?>
        <?php foreach (array_reverse($chat) as $m): ?>
          <div class="mb-2 p-2 rounded" style="background:<?= $m['role'] === 'user' ? 'var(--sys-accent-soft)' : 'var(--sys-surface-2)' ?>">
            <div class="small muted mb-1">
              <?= $m['role'] === 'user' ? 'Ты' : 'Глобальный MLL' ?>
              <?php if (!empty($m['model'])): ?>· <?= h((string)$m['model']) ?><?php endif; ?>
              · <?= h(dt((string)$m['created_at'], 'd.m H:i')) ?>
            </div>
            <?php
            $pl = !empty($m['payload']) ? json_decode((string)$m['payload'], true) : null;
            if (is_array($pl) && $m['role'] !== 'user' && (($pl['assessment'] ?? '') !== '' || !empty($pl['leverage']) || !empty($pl['questions']))):
            ?>
              <?php if (($pl['assessment'] ?? '') !== ''): ?>
                <div class="small mb-2" style="white-space:pre-wrap"><?= h((string)$pl['assessment']) ?></div>
              <?php endif; ?>
              <?php if (!empty($pl['leverage'])): ?>
                <div class="small fw-semibold mt-2 mb-1">Что даст эффект</div>
                <ul class="small mb-2"><?php foreach ($pl['leverage'] as $l): ?><li><?= h((string)$l) ?></li><?php endforeach; ?></ul>
              <?php endif; ?>
              <?php if (!empty($pl['rejects'])): ?>
                <div class="small fw-semibold mt-2 mb-1">Отвергаю</div>
                <ul class="small mb-2"><?php foreach ($pl['rejects'] as $l): ?><li class="text-danger"><?= h((string)$l) ?></li><?php endforeach; ?></ul>
              <?php endif; ?>
              <?php if (!empty($pl['questions'])): ?>
                <div class="small fw-semibold mt-2 mb-1">Вопросы к тебе</div>
                <ol class="small mb-2"><?php foreach ($pl['questions'] as $l): ?><li><?= h((string)$l) ?></li><?php endforeach; ?></ol>
              <?php endif; ?>
              <?php if (!empty($pl['steps'])): ?>
                <div class="small fw-semibold mt-2 mb-1">Действия</div>
                <ol class="small mb-0"><?php foreach ($pl['steps'] as $l): ?><li><?= h((string)$l) ?></li><?php endforeach; ?></ol>
              <?php endif; ?>
              <?php if (!empty($pl['roadmaps'])): ?>
                <div class="small fw-semibold mt-3 mb-1">Предлагаемые RoadMap — можно применить</div>
                <?php foreach ($pl['roadmaps'] as $ri => $rm):
                    $rp = null;
                    foreach ($dirs as $dd) { if ((int)$dd['id'] === (int)($rm['project'] ?? 0)) { $rp = $dd; break; } }
                ?>
                  <div class="border rounded p-2 mb-2 bg-white">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                      <div class="small">
                        <span class="fw-semibold"><?= h((string)($rm['title'] ?? 'RoadMap')) ?></span>
                        <?php if ($rp): ?>
                          <span class="muted">· <?= h((string)($rp['icon'] . ' ' . $rp['title'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($rm['why'])): ?>
                          <div class="muted"><?= h((string)$rm['why']) ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="d-flex gap-1">
                        <form method="post" onsubmit="return confirm('Применить этот RoadMap за проектом? Текущий план будет заменён.')">
                          <input type="hidden" name="act" value="gml_apply_roadmap">
                          <input type="hidden" name="chat_id" value="<?= (int)$m['id'] ?>">
                          <input type="hidden" name="idx" value="<?= (int)$ri ?>">
                          <button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Применить</button>
                        </form>
                        <?php if ($rp): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?page=roadmap&project=<?= (int)$rp['id'] ?>"><i class="bi bi-sliders me-1"></i>Скорректировать</a>
                        <?php endif; ?>
                      </div>
                    </div>
                    <ol class="small mt-2 mb-0"><?php foreach ((array)($rm['steps'] ?? []) as $s):
                      $stText = is_array($s) ? (string)($s['task'] ?? ($s['title'] ?? '')) : (string)$s;
                      $stGoal = is_array($s) ? trim((string)($s['goal'] ?? '')) : '';
                      $stHrs  = is_array($s) ? (int)($s['hours'] ?? 0) : 0;
                    ?><li><?= h($stText) ?><?php if ($stGoal !== ''): ?><br><span class="muted">цель этапа: <?= h($stGoal) ?></span><?php endif; ?><?php if ($stHrs > 0): ?><br><span class="muted">срок: через <?= (int)$stHrs ?> ч от начала этапа</span><?php endif; ?></li><?php endforeach; ?></ol>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if (!empty($pl['today'])): ?>
                <div class="small fw-semibold mt-3 mb-1">План на сегодня</div>
                <?php foreach ($pl['today'] as $ti => $tp):
                    $tpProj = null;
                    foreach ($dirs as $dd) { if ((int)$dd['id'] === (int)($tp['project'] ?? 0)) { $tpProj = $dd; break; } }
                ?>
                  <div class="border rounded p-2 mb-2 bg-white">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                      <div class="small">
                        <span class="fw-semibold"><?= h((string)($tp['goal'] ?? '')) ?></span>
                        <?php if ($tpProj): ?>
                          <span class="muted">· <?= h((string)($tpProj['icon'] . ' ' . $tpProj['title'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($tp['metric'])): ?>
                          <div class="muted">метрика: <?= h((string)$tp['metric']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tp['why'])): ?>
                          <div class="muted"><?= h((string)$tp['why']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tp['first_move'])): ?>
                          <div>первое действие: <?= h((string)$tp['first_move']) ?></div>
                        <?php endif; ?>
                      </div>
                      <form method="post" onsubmit="return confirm('Зафиксировать план на сегодня для этого направления?')">
                        <input type="hidden" name="act" value="gml_apply_today">
                        <input type="hidden" name="chat_id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="idx" value="<?= (int)$ti ?>">
                        <input type="hidden" name="day" value="<?= h(date('Y-m-d')) ?>">
                        <button class="btn btn-sm btn-success"><i class="bi bi-calendar-check me-1"></i>Зафиксировать на сегодня</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if (!empty($pl['info_roadmaps'])): ?>
                <div class="small fw-semibold mt-3 mb-1">Информационные RoadMap — сбор данных, ждут утверждения</div>
                <?php foreach ($pl['info_roadmaps'] as $ri => $rm):
                    $rp = null;
                    foreach ($dirs as $dd) { if ((int)$dd['id'] === (int)($rm['project'] ?? 0)) { $rp = $dd; break; } }
                ?>
                  <div class="border rounded p-2 mb-2 bg-white">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                      <div class="small">
                        <span class="fw-semibold"><?= h((string)($rm['title'] ?? 'Информационный RoadMap')) ?></span>
                        <?php if ($rp): ?>
                          <span class="muted">· <?= h((string)($rp['icon'] . ' ' . $rp['title'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($rm['why'])): ?>
                          <div class="muted"><?= h((string)$rm['why']) ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="d-flex gap-1">
                        <form method="post" onsubmit="return confirm('Сохранить как черновик RoadMap? Он будет ждать утверждения.')">
                          <input type="hidden" name="act" value="gml_apply_info_roadmap">
                          <input type="hidden" name="chat_id" value="<?= (int)$m['id'] ?>">
                          <input type="hidden" name="idx" value="<?= (int)$ri ?>">
                          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-plus me-1"></i>Сохранить черновиком</button>
                        </form>
                        <?php if ($rp): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?page=roadmap&project=<?= (int)$rp['id'] ?>"><i class="bi bi-sliders me-1"></i>В конструктор</a>
                        <?php endif; ?>
                      </div>
                    </div>
                    <ol class="small mt-2 mb-0"><?php foreach ((array)($rm['steps'] ?? []) as $s):
                      $stText = is_array($s) ? (string)($s['task'] ?? ($s['title'] ?? '')) : (string)$s;
                      $stGoal = is_array($s) ? trim((string)($s['goal'] ?? '')) : '';
                      $stHrs  = is_array($s) ? (int)($s['hours'] ?? 0) : 0;
                    ?><li><?= h($stText) ?><?php if ($stGoal !== ''): ?><br><span class="muted">цель этапа: <?= h($stGoal) ?></span><?php endif; ?><?php if ($stHrs > 0): ?><br><span class="muted">срок: через <?= (int)$stHrs ?> ч от начала этапа</span><?php endif; ?></li><?php endforeach; ?></ol>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php else: ?>
              <div class="small" style="white-space:pre-wrap"><?= h((string)$m['text']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer">
        <form method="post">
          <input type="hidden" name="act" value="gml_send">
          <input type="hidden" name="model" value="<?= h($curModel) ?>">
          <textarea name="text" rows="4" class="form-control form-control-sm mb-2"
                    placeholder="Что решаем? Например: где фокус на ближайший месяц и что капитализировать"><?= h($hint) ?></textarea>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <button class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Спросить</button>
            <span class="muted small">модель: <?= h($curModel) ?></span>
          </div>
        </form>
        <form method="post" class="d-flex gap-2 align-items-center mt-2">
          <input type="hidden" name="act" value="gml_set_model">
          <select name="slug" class="form-select form-select-sm" style="width:auto">
            <?php foreach ($models as $mm): ?>
              <option value="<?= h((string)($mm['id'] ?? '')) ?>" <?= $curModel === (string)($mm['id'] ?? '') ? 'selected' : '' ?>>
                <?= h((string)($mm['label'] ?? ($mm['id'] ?? ''))) ?><?= !empty($mm['free']) ? ' · free' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-secondary">Модель глобального MLL</button>
        </form>
        <form method="post" class="d-flex gap-2 align-items-center mt-2">
          <input type="hidden" name="act" value="gml_log_depth">
          <label class="small muted">Глубина истории лога (сообщений по каждому направлению):</label>
          <input type="number" name="depth" min="0" max="500" value="<?= (int)$logDepth ?>"
                 class="form-control form-control-sm" style="width:90px">
          <button class="btn btn-sm btn-outline-secondary">Применить</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ПОЯСНЕНИЯ ПО НАПРАВЛЕНИЯМ -->
  <div class="col-12 col-xl-5">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold"><i class="bi bi-journal-text me-1"></i>Пояснения по направлениям</div>
      <div class="card-body" style="max-height:54vh; overflow:auto">
        <div class="muted small mb-2">Эти пояснения видит глобальный управляющий вместе с RoadMap и задачами. Сохраняются по каждому направлению.</div>
        <?php foreach ($dirs as $d): ?>
          <form method="post" class="mb-2">
            <input type="hidden" name="act" value="gml_note">
            <input type="hidden" name="project_id" value="<?= (int)$d['id'] ?>">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="small fw-semibold"><?= h((string)$d['icon'] . ' ' . (string)$d['title']) ?></span>
              <span class="muted small">задач: <?= (int)$d['tasks_open'] ?>/<?= (int)$d['tasks_all'] ?> ·
                расход $<?= number_format((float)$d['cost'], 4) ?>
                <?php if ((int)$d['roadmap_version']): ?>· RoadMap v<?= (int)$d['roadmap_version'] ?><?php endif; ?></span>
            </div>
            <textarea name="text" rows="3" class="form-control form-control-sm mb-1"
                      placeholder="Что важно знать про это направление: деньги, риски, фокус, что не делаем"><?= h((string)($d['note'] ?? '')) ?></textarea>
            <button class="btn btn-sm btn-outline-secondary">Сохранить</button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ПЛАН НА СЕГОДНЯ + РАЗБОРЫ ВЫПОЛНЕНИЙ -->
<?php
$gmlPlans = [];
$gmlReviews = [];
try {
    require_once __DIR__ . '/../lib/plan.php';
    $gmlPlans = plan_for_day();
    $gmlReviews = all("SELECT t.id, t.title, t.closed_at, t.result_note, p.title proj
                        FROM projects t JOIN projects p ON p.id=t.parent_id
                        WHERE t.kind='task' AND t.status='done' AND t.result_note IS NOT NULL
                        ORDER BY t.closed_at DESC LIMIT 8");
} catch (Throwable $e) {
    $gmlPlans = []; $gmlReviews = [];
}
?>
<div class="card mb-3 mt-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-calendar-check me-1"></i>План на сегодня</div>
  <div class="card-body">
    <?php if (!$gmlPlans): ?>
      <div class="muted small">Плана дня пока нет. Глобальный MLL формирует его в ответе (секция «План на сегодня») — применить можно кнопкой «Применить».</div>
    <?php else: ?>
      <table class="table table-sm mb-0">
        <thead><tr><th>Направление</th><th>Цель дня</th><th>Метрика</th><th>Первое действие</th><th>Задачи</th></tr></thead>
        <tbody>
        <?php foreach ($gmlPlans as $pl): ?>
          <tr>
            <td><?= h((string)$pl['proj_icon'] . ' ' . (string)$pl['proj_title']) ?></td>
            <td><?= h((string)$pl['goal']) ?></td>
            <td><?= h((string)$pl['metric']) ?></td>
            <td><?= h((string)$pl['first_move']) ?></td>
            <td>открыто <?= (int)$pl['tasks_open'] ?>, закрыто <?= (int)$pl['tasks_done'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-clipboard-check me-1"></i>Разборы выполнений (цель vs результат)</div>
  <div class="card-body" style="max-height:26vh; overflow:auto">
    <?php if (!$gmlReviews): ?>
      <div class="muted small">Разборов пока нет — они появляются автоматически при закрытии задачи (факты: вызовы, токены, стоимость, ответ агента, достигнута ли цель этапа).</div>
    <?php else: ?>
      <?php foreach ($gmlReviews as $rv): ?>
        <div class="border rounded p-2 mb-2 small">
          <b>[<?= (int)$rv['id'] ?>] <?= h((string)$rv['proj']) ?></b>
          — <?= h(mb_substr((string)$rv['title'], 0, 90)) ?><br>
          <span class="muted"><?= h(str_replace("\n", ' / ', mb_substr((string)$rv['result_note'], 0, 700))) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- УРОВНИ ДЕЛЕГИРОВАНИЯ + АВТО-РЕЖИМ ПРОЕКТОВ -->
<div class="card mb-3 mt-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Уровни делегирования</div>
  <div class="card-body">
    <div class="small mb-2">
      <b>L0 — ты (глобальный управляющий):</b> приоритеты, капитализация, фокус, отвержение слабых планов.<br>
      <b>L1 — менеджер направления (MLL проекта):</b> раскладывает утверждённый RoadMap на технические задачи.<br>
      <b>L2 — исполнитель (агент в чате):</b> берёт задачи из плана сам и отчитывается токенами.
    </div>
    <table class="table table-sm mb-2">
      <thead><tr><th>Направление</th><th>RoadMap</th><th>Черновики</th><th>В плане</th><th>В работе</th><th>Закрыто</th><th>Recurring</th><th>Расход</th><th>Авто-задачи</th></tr></thead>
      <tbody>
      <?php foreach ($levels as $l): ?>
        <tr>
          <td><?= h((string)$l['title']) ?></td>
          <td><?= (int)$l['roadmap'] ? 'v' . (int)$l['roadmap'] : '—' ?></td>
          <td><?= (int)$l['drafts'] ?></td>
          <td><?= (int)$l['planned'] ?></td>
          <td><?= (int)$l['active'] ?></td>
          <td><?= (int)$l['done'] ?></td>
          <td><?= (int)$l['recurring'] ?></td>
          <td>$<?= number_format((float)$l['cost'], 4) ?></td>
          <td>
            <form method="post">
              <input type="hidden" name="act" value="project_auto_roadmap">
              <input type="hidden" name="project_id" value="<?= (int)$l['id'] ?>">
              <input type="hidden" name="on" value="<?= $l['auto'] ? 0 : 1 ?>">
              <input type="hidden" name="back" value="?page=gml">
              <button class="btn btn-sm <?= $l['auto'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                <?= $l['auto'] ? 'вкл' : 'выкл' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="small muted">«Авто-задачи» — после утверждения RoadMap его шаги сами становятся задачами в плане, оператор не вмешивается. Задачи уходят агенту по одной, как обычно.</div>
  </div>
</div>

<details class="mt-3">
  <summary class="small muted" style="cursor:pointer">Что видит глобальный MLL (полный контекст)</summary>
  <pre class="mt-2 p-2 bg-light rounded small" style="white-space:pre-wrap; max-height:460px; overflow:auto"><?= h(gml_context([], '')) ?></pre>
</details>
