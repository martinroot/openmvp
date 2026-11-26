<?php
/**
 * Дерево задач — простое.
 * Строка одна: переключатель готово/не готово + заголовок.
 * Папками (с детьми) могут быть только узлы с каналом (agent_chat_id).
 * Узел без канала — лист: его дети поднимаются на уровень выше.
 *
 * У каждого направления есть «+» (добавить задачу в план), у каждой задачи —
 * крестик (удалить). План ведёт сторож направления, трекер только показывает.
 */
$flat = tree_all();
$roots = tree_build(null, $flat);

// Раскрытые узлы: по умолчанию всё свернуто, состояние держим в URL (?open=27,28).
$openIds = array_values(array_filter(array_map('intval',
    explode(',', (string)($_GET['open'] ?? '')))));
$openSet = array_flip($openIds);
$openParam = $openIds ? '&open=' . implode(',', $openIds) : '';
// (отладка убрана)

/** Поднять детей узлов без канала на уровень родителя */
function flatten(array $nodes): array
{
    $out = [];
    foreach ($nodes as $n) {
        $kids = $n['children'] ?? [];
        if (empty($kids)) {
            $out[] = $n;
            continue;
        }
        if (!empty($n['agent_chat_id']) || ($n['kind'] ?? '') === 'subproject') {
            // канал (направление) или подпроект — дети остаются внутри
            $n['children'] = flatten($kids);
            $out[] = $n;
        } else {
            // без канала папкой быть не может: дети выходят наверх
            $out = array_merge($out, flatten($kids));
        }
    }
    return $out;
}
$roots = flatten($roots);

$total = 0;
$done = 0;
$walk = function (array $nodes) use (&$walk, &$total, &$done) {
    foreach ($nodes as $n) {
        if (($n['kind'] ?? '') === 'task') {
            $total++;
            if ($n['status'] === 'done') {
                $done++;
            }
        }
        if (!empty($n['children'])) {
            $walk($n['children']);
        }
    }
};
$walk($roots);

/** Форма добавления задачи в направление */
function add_task_form(int $projectId, string $openParam = ''): string
{
    $openVal = $openParam !== '' ? trim(substr($openParam, 5)) : '';
    ob_start(); ?>
    <form method="post" class="taddform" action="?page=tree">
      <input type="hidden" name="act" value="add_task">
      <input type="hidden" name="parent_id" value="<?= $projectId ?>">
      <?php if ($openVal !== ''): ?>
        <input type="hidden" name="open" value="<?= h($openVal) ?>">
      <?php endif; ?>
      <div class="taddrow">
        <input class="form-control form-control-sm" name="title" required maxlength="200"
               placeholder="Одной строкой: что сделать" style="max-width:640px">
        <button class="btn btn-primary btn-sm" type="submit">Добавить</button>
      </div>
    </form>
    <?php
    return (string)ob_get_clean();
}
?>
<div class="page-head">
  <div>
    <h1 class="page-title">Задачи</h1>
    <div class="page-sub"><?= $done ?> из <?= $total ?> готово</div>
  </div>
  <div class="d-flex gap-2 align-items-start">
    <a class="btn btn-light border btn-sm" href="?page=tree&act=export_plan"
       title="Скачать план (JSON)"><i class="bi bi-box-arrow-up me-1"></i>Экспорт</a>
    <a class="btn btn-light border btn-sm" href="?page=import"
       title="Загрузить план из файла"><i class="bi bi-box-arrow-in-down me-1"></i>Импорт</a>
    <a class="btn btn-light border btn-sm" href="?page=tree&act=export_log"
       title="Скачать лог переписки (txt)"><i class="bi bi-journal-text me-1"></i>Скачать log</a>
    <button class="btn btn-light border btn-sm" type="button" data-toggle="newproj">
      <i class="bi bi-plus-lg me-1"></i>Проект
    </button>
    <a class="btn btn-primary btn-sm" href="?page=node_new"><i class="bi bi-plus-lg me-1"></i>Задача</a>
  </div>
</div>

<div class="taddbox" id="newproj" style="display:none">
  <form method="post" action="?page=tree" class="taddform">
    <input type="hidden" name="act" value="create_project">
    <div class="row g-2">
      <div class="col-12 col-md-4">
        <label class="form-label small fw-semibold mb-0">Название</label>
        <input class="form-control form-control-sm" name="title" required maxlength="120"
               placeholder="например, FABRA">
      </div>
      <div class="col-6 col-md-1">
        <label class="form-label small fw-semibold mb-0">Иконка</label>
        <input class="form-control form-control-sm text-center" name="icon" maxlength="4" value="📁">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-0">ID чата</label>
        <input class="form-control form-control-sm" name="agent_chat_id" inputmode="numeric"
               placeholder="-1003919000688">
        <div class="form-text">Рабочее поле: по нему сторож возьмёт направление в работу.</div>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-0">@агента</label>
        <input class="form-control form-control-sm" name="agent_name" placeholder="@ad9418318698_bot">
        <div class="form-text">По нему сторож отличает сообщения агента от сообщений оператора.</div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-0">Код агента</label>
        <input class="form-control form-control-sm" name="agent_code" placeholder="fab-001">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-0">IP сервера</label>
        <input class="form-control form-control-sm" name="server_ip" placeholder="1.2.3.4">
      </div>
    </div>
    <div class="mt-2"><button class="btn btn-primary btn-sm" type="submit">Создать направление</button></div>
  </form>
</div>

<div class="card">
  <div class="card-body p-2">
    <?php if (!$roots): ?>
      <div class="empty-state"><i class="bi bi-list-check"></i>Задач нет</div>
    <?php else: ?>
      <?php
      $render = function (array $nodes, int $depth = 0) use (&$render, $openParam, $openSet) {
        foreach ($nodes as $n) {
          $isTask = ($n['kind'] ?? '') === 'task';
          $isDone = $n['status'] === 'done';
          // Направление (проект) — всегда может получить новую задачу,
          // даже если задач пока нет.
          $isDir  = !$isTask && (in_array(($n['kind'] ?? ''), ['project', 'subproject'], true)
                                 || !empty($n['children']));
          // Направление, где все задачи закрыты — подсвечиваем красным.
          $spent = false;
          $spentRow = '';
          $psRow = null;
          if (!$isTask) {
              $psRow = project_stats((int)$n['id']);
          }
          ?>
          <div class="trow <?= $isTask ? '' : 'trow-folder' ?> <?= $isDone ? 'is-done' : '' ?> <?= $spentRow ?? '' ?>"
               style="margin-left:<?= $depth * 22 ?>px">
            <?php if (!empty($n['children'])): ?>
              <?php $nodeOpen = isset($openSet[(int)$n['id']]); ?>
              <button class="tchev <?= $nodeOpen ? '' : 'collapsed' ?>" type="button"
                      data-toggle="kids-<?= (int)$n['id'] ?>"
                      title="Свернуть / развернуть">▾</button>
            <?php else: ?>
              <span class="tchev tchev-leaf"></span>
            <?php endif; ?>

            <?php if ($isTask): ?>
              <?php if ((int)($n['prio'] ?? 5) <= 2): ?>
                <span class="tprio">P<?= (int)$n['prio'] ?></span>
              <?php endif; ?>
              <a class="tcheck <?= $isDone ? 'on' : '' ?>"
                 href="?page=node&id=<?= (int)$n['id'] ?>&act=toggle<?= $openParam ?>"
                 title="<?= $isDone ? 'Готово' : 'Не готово' ?>"></a>
            <?php endif; ?>

            <?php if (!$isTask && $n['icon']): ?>
              <span class="tficon"><?= h($n['icon']) ?></span>
            <?php endif; ?>

            <?php if (!$isTask): ?>
              <?php $chatOk = !empty($n['agent_chat_id']); ?>
              <span class="chatdot <?= $chatOk ? 'ok' : 'no' ?>"
                    title="<?= $chatOk ? 'ТГ-группа настроена' : 'ТГ-группа не настроена (нет agent_chat_id)' ?>">●</span>
            <?php endif; ?>

            <a class="ttext <?= $isTask ? '' : 'ttext-folder' ?> text-reset"
               href="?page=node&id=<?= (int)$n['id'] ?><?= $openParam ?>">
              <?php if ($isTask): ?><span class="tid">[<?= (int)$n['id'] ?>]</span><?php endif; ?><span title="<?= h((string)$n['title']) ?>"><?= h(mb_strlen((string)$n['title']) > 110 ? mb_substr((string)$n['title'], 0, 110) . '…' : (string)$n['title']) ?></span>
            </a>

            <?php if ($isTask): ?>
              <?php if ($isDone): ?>
                <span class="tstate tstate-done">готово</span>
              <?php else: ?>
                <span class="tstate">не готово</span>
              <?php endif; ?>
            <?php else: ?>
              <?php
              $ps = $psRow ?? project_stats((int)$n['id']);
              $mllCost = (float)$ps['mll_cost'];
              ?>
              <?php if (($n['kind'] ?? '') === 'project'): ?>
              <span class="tsum" title="версия · задачи · токены агентов · траты MLL-советчика">
                V <?= h($ps['version']) ?> · задач <?= (int)$ps['done'] ?>/<?= (int)$ps['total'] ?>
                · <?= format_tokens_ru((int)$ps['tokens']) ?> токенов
                · MLL $<?= $mllCost < 0.01 ? number_format($mllCost, 4) : number_format($mllCost, 2) ?>
                <?php
                $treeModel = '';
                try {
                    require_once __DIR__ . '/../lib/models.php';
                    $treeSlug = project_model((int)$n['id']);
                    if ($treeSlug) {
                        $tm = model_get($treeSlug);
                        $treeModel = $tm ? (string)$tm['name'] : $treeSlug;
                    }
                } catch (Throwable $e) {
                    $treeModel = '';
                }
                if ($treeModel !== ''): ?>
                  · модель <?= h($treeModel) ?>
                <?php endif; ?>
              </span>
              <?php endif; ?>
            <?php endif; ?>

            <a class="tedit" href="?page=node_edit&id=<?= (int)$n['id'] ?>&back=tree<?= $openParam ?>"
               title="Изменить <?= $isTask ? 'задачу' : 'узел' ?>">✎</a>

            <?php if ($isTask): ?>
              <form method="post" class="tdelfrm" onsubmit="return confirm('Удалить задачу [<?= (int)$n['id'] ?>]?');">
                <input type="hidden" name="act" value="del_task">
                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <?php if ($openParam !== ''): ?>
                  <input type="hidden" name="open" value="<?= h(trim(substr($openParam, 5))) ?>">
                <?php endif; ?>
                <button class="tdel" type="submit" title="Удалить задачу">✕</button>
              </form>
            <?php endif; ?>

            <?php if ($isDir): ?>
              <button class="tplus" type="button" data-toggle="add-<?= (int)$n['id'] ?>"
                      title="Добавить задачу в это направление">+</button>
            <?php endif; ?>
          </div>
          <?php
          // Периодические задачи направления — свои строки с удалением
          if ($isDir) {
              $recsTree = [];
              try {
                  require_once __DIR__ . '/../lib/recurring.php';
                  $recsTree = recurring_list((int)$n['id']);
              } catch (Throwable $e) {
                  $recsTree = [];
              }
              foreach ($recsTree as $rt): ?>
                <div class="trow trec">
                  <span class="ttext">🔁 каждые <b><?= (int)$rt['every_min'] ?> мин</b> — <?= h((string)$rt['title']) ?></span>
                  <span class="muted small"><?= $rt['last_sent'] ? 'последний: ' . h(dt((string)$rt['last_sent'], 'd.m H:i')) : 'ещё не отдавалась' ?></span>
                  <form method="post" class="tdelfrm" onsubmit="return confirm('Удалить периодическую задачу?');">
                    <input type="hidden" name="act" value="recurring_del">
                    <input type="hidden" name="id" value="<?= (int)$rt['id'] ?>">
                    <input type="hidden" name="back" value="tree">
                    <?php if ($openParam !== ''): ?>
                      <input type="hidden" name="open" value="<?= h(trim(substr($openParam, 5))) ?>">
                    <?php endif; ?>
                    <button class="tdel" type="submit" title="Удалить периодическую задачу">удалить</button>
                  </form>
                </div>
              <?php endforeach;
          }
          ?>
          <?php
          if (!empty($n['children'])) {
              echo '<div class="tkids" id="kids-' . (int)$n['id'] . '"'
                 . (isset($openSet[(int)$n['id']]) ? '' : ' style="display:none"') . '>';
              $render($n['children'], $depth + 1);
              echo '</div>';
          }
          if ($isDir) {
              echo '<div class="taddbox" id="add-' . (int)$n['id'] . '">' . add_task_form((int)$n['id'], $openParam) . '</div>';
          }
        }
      };
      $render($roots);
      ?>
    <?php endif; ?>
  </div>
</div>

<!-- MLL-советчик — внизу дерева, запускается только по кнопке. -->
<div class="mt-4 mb-3 text-end">
  <a class="btn btn-outline-secondary btn-sm" href="?page=mll">
    <i class="bi bi-stars me-1"></i>MLL
  </a>
</div>

<!-- Формы добавления (задача/проект/подпроект) раскрывает общий скрипт макета. -->
