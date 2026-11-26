<?php
$editId = req_int('id');
$n = $editId ? node($editId) : null;
$parent = req_int('parent');
if ($n && $n['parent_id']) $parent = (int)$n['parent_id'];

$all = tree_all();
$agentCodes = [];
foreach (all("SELECT DISTINCT agent_code, server, model FROM agent_runs") as $r) {
    if ($r['agent_code']) {
        $agentCodes[$r['agent_code']] = $r;
    }
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $n ? 'Изменить узел' : 'Новая задача' ?></h1>
    <div class="page-sub"><?= $n ? 'Правка карточки и привязок' : 'Завести узел в дереве' ?></div>
  </div>
  <a class="btn btn-light border" href="<?= $n ? '?page=node&id=' . (int)$n['id'] . (isset($_GET['open']) ? '&open=' . (int)$_GET['open'] : '') : '?page=tree' ?>">Отмена</a>
</div>

<?php $isNewTask = (!$n) && (req_str('kind', 'task') === 'task'); ?>
<form method="post" action="?page=<?= $n ? 'node' : 'node_new' ?><?= $n ? '&id=' . (int)$n['id'] : '' ?>">
  <?php if ($n): ?><input type="hidden" name="act" value="update"><?php else: ?><input type="hidden" name="act" value="create"><?php endif; ?>
  <script>
  // при создании задачи канал и сервер не нужны — они наследуются от направления
  function initNodeForm() {
    var kind = document.querySelector('select[name="kind"]');
    var ch = document.getElementById('card-channel');
    var ag = document.getElementById('card-agent');
    if (!kind || !ch || !ag) return;
    function apply() {
      var isTask = kind.value === 'task';
      ch.style.display = isTask ? 'none' : '';
      ag.style.display = isTask ? 'none' : '';
    }
    kind.addEventListener('change', apply);
    apply();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNodeForm);
  } else {
    initNodeForm();
  }
  </script>
  <input type="hidden" name="back" value="<?= h(req_str('back', 'node')) ?>">

  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <div class="card mb-3">
        <div class="card-header">Основное</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-sm-2">
              <label class="form-label small fw-semibold">Иконка</label>
              <input class="form-control form-control-lg text-center" name="icon" maxlength="4"
                     value="<?= h($n['icon'] ?? '📁') ?>" style="font-size:1.4rem">
            </div>
            <div class="col-12 col-sm-10">
              <label class="form-label small fw-semibold">Название</label>
              <input class="form-control" name="title" required maxlength="255"
                     value="<?= h($n['title'] ?? '') ?>" placeholder="Например: Починить воронку на Adult">
            </div>
            <div class="col-12 col-sm-4">
              <label class="form-label small fw-semibold">Тип узла</label>
              <select class="form-select" name="kind">
                <option value="project" <?= ($n['kind'] ?? 'task') === 'project' ? 'selected' : '' ?>>проект (с каналом)</option>
                <option value="subproject" <?= ($n['kind'] ?? '') === 'subproject' ? 'selected' : '' ?>>подпроект</option>
                <option value="task" <?= ($n['kind'] ?? 'task') === 'task' ? 'selected' : '' ?>>задача</option>
              </select>
            </div>
            <div class="col-12 col-sm-4">
              <label class="form-label small fw-semibold">Родитель</label>
              <select class="form-select" name="parent_id">
                <option value="">— корень —</option>
                <?php foreach ($all as $o): if ((int)$o['id'] === $editId) continue; ?>
                  <option value="<?= (int)$o['id'] ?>" <?= $parent === (int)$o['id'] ? 'selected' : '' ?>>
                    <?= indent_of((int)$o['id']) ?><?= h($o['icon']) ?> <?= h($o['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-4">
              <label class="form-label small fw-semibold">Статус</label>
              <select class="form-select" name="status">
                <option value="active" <?= ($n['status'] ?? 'active') !== 'done' ? 'selected' : '' ?>>не готово</option>
                <option value="done" <?= ($n['status'] ?? '') === 'done' ? 'selected' : '' ?>>готово</option>
              </select>
            </div>
            <?php if ($n): ?>
            <div class="col-12 col-sm-4">
              <label class="form-label small fw-semibold">Приоритет</label>
              <select class="form-select" name="prio">
                <?php $curPrio = (int)($n['prio'] ?? 2); ?>
                <option value="1" <?= $curPrio === 1 ? 'selected' : '' ?>>P1 — деньги</option>
                <option value="2" <?= $curPrio === 2 ? 'selected' : '' ?>>P2 — улучшение бизнеса</option>
                <option value="3" <?= $curPrio === 3 ? 'selected' : '' ?>>P3 — надёжность</option>
                <option value="4" <?= $curPrio === 4 ? 'selected' : '' ?>>P4 — обслуживание</option>
                <option value="5" <?= $curPrio >= 5 ? 'selected' : '' ?>>P5 — прочее</option>
              </select>
            </div>
            <?php endif; ?>
            <div class="col-12">
              <label class="form-label small fw-semibold">Суть / описание</label>
              <textarea class="form-control" name="summary" rows="4" placeholder="Что именно нужно сделать, критерии готовности"><?= h($n['summary'] ?? '') ?></textarea>
            </div>
            <?php if ($n): ?>
            <div class="col-12">
              <label class="form-label small fw-semibold">Критерии готовности (по одному в строке)</label>
              <textarea class="form-control" name="criteria" rows="4"
                        placeholder="Что должно быть доказано, чтобы задача считалась выполненной"><?= h($n['criteria'] ?? '') ?></textarea>
              <div class="form-text">Критерии уходят агенту вместе с задачей — он присылает отчёт по ним.</div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card mb-3" id="card-channel">
        <div class="card-header">Канал (Telegram)</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-semibold">ID чата</label>
              <input class="form-control" name="agent_chat_id" inputmode="numeric"
                     value="<?= h($n['agent_chat_id'] ?? '') ?>" placeholder="-1004469933182">
              <div class="form-text">Рабочее поле: сторож чатов берёт направление в работу
                по этому ID. Указан — значит скрипт сам за ним следит.</div>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-semibold">@агента в чате</label>
              <input class="form-control" name="agent_name" value="<?= h($n['agent_name'] ?? '') ?>" placeholder="@mainmaclaude_bot">
              <div class="form-text">По нему сторож отличает сообщения агента от сообщений оператора.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3" id="card-agent">
        <div class="card-header">Агент и сервер</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-semibold">Код агента</label>
              <input class="form-control" name="agent_code" list="agentList" value="<?= h($n['agent_code'] ?? '') ?>" placeholder="edge-147">
              <datalist id="agentList">
                <?php foreach ($agentCodes as $code => $info): ?>
                  <option value="<?= h($code) ?>"><?= h($info['server'] ?: '') ?> <?= h($info['model'] ?: '') ?></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-semibold">IP сервера</label>
              <input class="form-control" name="server_ip" value="<?= h($n['server_ip'] ?? '') ?>" placeholder="145.223.69.147">
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-semibold">GitHub репозитарий</label>
              <input class="form-control" name="github_url" value="<?= h($n['github_url'] ?? '') ?>" placeholder="https://github.com/martinroot/repo">
            </div>
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-semibold">Модель</label>
              <input class="form-control" name="model" value="<?= h($n['model'] ?? '') ?>" placeholder="minimax-m3.1-flash:free">
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small fw-semibold">Приоритет</label>
              <select class="form-select" name="priority">
                <?php for ($i=0;$i<=3;$i++): ?>
                  <option value="<?= $i ?>" <?= (int)($n['priority'] ?? 0) === $i ? 'selected' : '' ?>>P<?= $i ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card mb-3">
        <div class="card-header">Финансы и сроки</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Финансовая цель узла, $</label>
            <input class="form-control" name="goal_amount" type="number" step="0.01" min="0"
                   value="<?= h($n['goal_amount'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Срок</label>
            <input class="form-control" name="goal_date" type="date" value="<?= h($n['goal_date'] ?? '') ?>">
          </div>
          <?php if ($n): ?>
            <div class="small text-secondary">
              <div class="d-flex justify-content-between"><span>Потрачено</span><b><?= format_usd((float)$n['cost_total']) ?></b></div>
              <div class="d-flex justify-content-between"><span>Запусков</span><b><?= (int)$n['runs_count'] ?></b></div>
              <div class="d-flex justify-content-between"><span>Время</span><b><?= format_dur((int)$n['duration_ms']) ?></b></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <button class="btn btn-primary w-100"><?= $n ? 'Сохранить' : 'Создать узел' ?></button>
    </div>
  </div>
</form>
