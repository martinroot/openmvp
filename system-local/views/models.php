<?php
declare(strict_types=1);
/**
 * Раздел «Модели» — под выбор модели для кодинга по цене одной задачи.
 *
 * Три блока:
 *   1) ТОП моделей по объёму в OpenRouter: цена, кэш, контекст, качество, $ за задачу;
 *   2) цена одной выполненной задачи по реальным вызовам;
 *   3) проекты: цена за задачу, предложение по назначению, кнопки «Назначить»/«Ротация».
 */
require_once __DIR__ . '/../lib/models.php';

$onlyFree = req_str('free') === '1';
$maxOut   = (float)str_replace(',', '.', req_str('maxout') !== '' ? req_str('maxout') : '3');   // $/1M
$topLimit = 50;

$top      = models_top($topLimit, $onlyFree, $maxOut / 1e6);   // порог приходит в $/1M
$econ     = models_economics(30);
$projects = models_project_economics();
$limits   = or_key_info();
$dirs = all("SELECT id, title, icon, kind FROM projects
             WHERE kind IN ('project','subproject') ORDER BY (kind='subproject'), id") ?: [];
$assignModels = all("SELECT slug, name, p_out FROM models
                     WHERE pop_rank>0 OR slug IN (SELECT slug FROM project_models)
                     ORDER BY (pop_rank=0), pop_rank ASC, p_out ASC LIMIT 120") ?: [];
// закреплённые модели добавляем в список выбора (могут не попасть в pop_rank)
if ($pinnedList) {
    $have = array_column($assignModels, 'slug');
    foreach ($pinnedList as $ps) {
        if (!in_array($ps, $have, true)) {
            $pm = model_get($ps);
            if ($pm) {
                $assignModels[] = ['slug' => $ps, 'name' => (string)$pm['name'], 'p_out' => (float)$pm['p_out']];
            }
        }
    }
}
$pendingCmds = array_column(all("SELECT cmd FROM chat_commands WHERE sent_at IS NULL ORDER BY id DESC LIMIT 5"), 'cmd');
$bestProviders = all("SELECT slug, name, p_best_provider provider, p_min_out cheapest FROM models
                      WHERE p_best_provider<>'' AND pop_rank>0
                      ORDER BY pop_rank LIMIT 12") ?: [];
$queue    = json_decode(models_setting('model_retest_queue'), true) ?: [];
$pinned      = models_setting('pinned_models');
$pinnedList  = array_values(array_filter(array_map('trim', explode(',', (string)$pinned))));

/** Цена за 1M токенов. */
function m_p(float $perToken): string
{
    if ($perToken < 0) {
        return 'кредит $1';
    }
    return '$' . number_format($perToken * 1e6, 3);
}

/** Контекст в компактном виде. */
function m_ctx(?float $v): string
{
    if ($v === null || $v <= 0) {
        return '—';
    }
    if ($v >= 1000000) {
        return number_format($v / 1000000, 2) . 'M';
    }
    if ($v >= 1000) {
        return number_format($v / 1000, 0) . 'K';
    }
    return number_format($v);
}
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <h4 class="mb-0">Модели — выбор под кодинг</h4>
    <div class="small muted">объём в OpenRouter · цена и кэш · контекст · цена одной задачи</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" class="d-inline">
      <input type="hidden" name="act" value="models_sync">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Каталог</button>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="act" value="models_rank_sync">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart me-1"></i>Объём</button>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="act" value="models_retest">
      <input type="hidden" name="scope" value="tested">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-play-fill me-1"></i>Ретест</button>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="act" value="models_endpoints">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-down me-1"></i>Цены провайдеров</button>
    </form>
    <form method="post" class="d-inline d-flex gap-1 align-items-center">
      <input type="hidden" name="act" value="models_provider_mode">
      <select name="mode" class="form-select form-select-sm" style="width:auto">
        <option value="" <?= or_provider_mode() === '' ? 'selected' : '' ?>>провайдер: по умолчанию</option>
        <option value="price" <?= or_provider_mode() === 'price' ? 'selected' : '' ?>>провайдер: по цене</option>
        <?php foreach ($bestProviders as $bp): ?>
          <option value="<?= h((string)$bp['provider']) ?>" <?= or_provider_mode() === (string)$bp['provider'] ? 'selected' : '' ?>>
            <?= h((string)$bp['provider']) ?> — <?= h((string)$bp['name']) ?> (<?= m_p((float)$bp['cheapest']) ?>/1M)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-secondary">Применить</button>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="act" value="models_limits">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i>Лимиты</button>
    </form>
  </div>
</div>

<?php if (!empty($limits['ok'])): $k = $limits['data']; ?>
  <div class="alert alert-light border py-1 small mb-3 d-flex flex-wrap gap-3">
    <span><b>Баланс:</b> $<?= number_format((float)($k['usage'] ?? 0), 2) ?> всего ·
          $<?= number_format((float)($k['usage_daily'] ?? 0), 2) ?> за день ·
          $<?= number_format((float)($k['usage_weekly'] ?? 0), 2) ?> за неделю</span>
    <span><b>Бесплатные:</b>
      <?php $fr = $k['free_model_daily_requests'] ?? null; ?>
      <?php if ($fr): ?>
        <?= (int)($fr['used'] ?? 0) ?>/<?= (int)($fr['limit'] ?? 0) ?> в сутки
        <?php if ((int)($fr['remaining'] ?? 0) <= 0): ?>
          <span class="badge bg-danger">лимит исчерпан</span>
        <?php else: ?>
          <span class="badge bg-success">осталось <?= (int)$fr['remaining'] ?></span>
        <?php endif; ?>
      <?php else: ?>
        без ограничений
      <?php endif; ?>
    </span>
    <span class="muted">платные ограничены балансом (402) и бюджетом воркспейса (403)</span>
  </div>
<?php endif; ?>

<?php if (($queue['status'] ?? '') === 'run'): ?>
  <div class="alert alert-info py-2 small d-flex justify-content-between align-items-center">
    <span><i class="bi bi-hourglass-split me-1"></i>Идёт ретест: <?= (int)($queue['done'] ?? 0) ?> из <?= (int)($queue['total'] ?? 0) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="?page=models">Обновить</a>
  </div>
<?php elseif (($queue['status'] ?? '') === 'done' && !empty($queue['log'])): ?>
  <div class="alert alert-success py-2 small">
    <i class="bi bi-check2-circle me-1"></i>Ретест завершён — <?= count($queue['log']) ?> моделей.
    <div class="mt-1 muted"><?= h(implode(' · ', array_slice($queue['log'], -8))) ?></div>
  </div>
<?php endif; ?>


<!-- ЗАКРЕПЛЁННЫЕ МОДЕЛИ (всегда в списках выбора) -->
<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-pin-angle me-1"></i>Закреплённые модели</div>
  <div class="card-body">
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
      <input type="hidden" name="act" value="models_pinned">
      <div class="flex-grow-1">
        <label class="small muted d-block">Список через запятую — эти модели всегда есть в выпадающих списках (MLL, глобальный MLL, генератор в карточке проекта)</label>
        <input class="form-control form-control-sm" name="slugs" value="<?= h((string)($pinned ?? '')) ?>"
               placeholder="openai/gpt-6.1-terra-pro, z-ai/glm-5.3-flash">
      </div>
      <button class="btn btn-sm btn-outline-primary">Сохранить</button>
    </form>
    <?php if (!empty($pinnedList)): ?>
      <div class="small muted mt-2">Сейчас закреплены:
        <?php foreach ($pinnedList as $pm): ?>
          <span class="badge text-bg-secondary me-1"><?= h((string)$pm) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- НАЗНАЧИТЬ МОДЕЛЬ ПРОЕКТУ + КОМАНДА В ЧАТ -->
<div class="card mb-3">
  <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-send me-1"></i>Назначить модель проекту и отправить команду в чат</span>
    <span class="muted small">сторож отправит команду в чат агента в ближайшем цикле</span>
  </div>
  <div class="card-body">
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
      <input type="hidden" name="act" value="model_assign_command">
      <div>
        <label class="small muted d-block">Проект</label>
        <select name="project_id" class="form-select form-select-sm" style="min-width:230px">
          <?php foreach ($dirs as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= h((string)($d['icon'] . ' ' . $d['title'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="small muted d-block">Модель</label>
        <select name="slug" class="form-select form-select-sm" style="min-width:280px">
          <?php foreach ($assignModels as $am): ?>
            <option value="<?= h((string)$am['slug']) ?>"><?= h((string)($am['name'] ?: $am['slug'])) ?> — <?= m_p((float)$am['p_out']) ?>/1M</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="small muted d-block">Провайдер Hermes</label>
        <input type="text" name="provider" class="form-control form-control-sm" style="width:220px"
               placeholder="пусто = текущий провайдер" value="">
      </div>
      <div>
        <label class="small muted d-block">Область</label>
        <select name="scope" class="form-select form-select-sm" style="width:130px">
          <option value="global">глобально</option>
          <option value="session">в сессии</option>
          <option value="once">на один ход</option>
        </select>
      </div>
      <button class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Назначить и отправить</button>
    </form>
    <div class="muted small mt-2">
      Команда уйдёт в чат агента как обычное сообщение: <code>/model &lt;модель&gt; --global</code>.
      Поле «Провайдер» оставь пустым — тогда агент переключит модель на текущем провайдере (встроенном openrouter);
      укажи значение, только если у агента заведён свой провайдер в секции <code>providers:</code>.
      Чтобы весь трафик агента шёл к самому дешёвому провайдеру, настрой маршрутизацию (форма ниже) —
      это нативная настройка Hermes <code>provider_routing</code>, а не костыль с extra_body.
      <?php if ($pendingCmds): ?>
        <div class="mt-1">Не отправлено ещё: <?= count($pendingCmds) ?> (<?= h(implode('; ', array_slice($pendingCmds, 0, 3))) ?>)</div>
      <?php endif; ?>
    </div>
    <hr>
    <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
      <input type="hidden" name="act" value="model_routing_command">
      <div>
        <label class="small muted d-block">Проект</label>
        <select name="project_id" class="form-select form-select-sm" style="min-width:230px">
          <?php foreach ($dirs as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= h((string)($d['icon'] . ' ' . $d['title'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="small muted d-block">Маршрутизация провайдера</label>
        <select name="sort" class="form-select form-select-sm" style="width:190px">
          <option value="price">по цене (дешевле всего)</option>
          <option value="throughput">по пропускной способности</option>
          <option value="latency">по задержке</option>
        </select>
      </div>
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-terminal me-1"></i>Отправить агенту команду</button>
    </form>
    <div class="muted small mt-1">
      Агент выполнит у себя <code>hermes config set provider_routing.sort price</code> — это нативная настройка
      Hermes для OpenRouter (без неё OpenRouter выбирает провайдера случайно). Затем <code>/restart</code>.
    </div>
  </div>
</div>

<!-- 1. ТОП ПО ОБЪЁМУ -->
<div class="card mb-3">
  <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="fw-semibold"><i class="bi bi-fire me-1"></i>ТОП-<?= $topLimit ?> по объёму в OpenRouter</span>
    <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
      <input type="hidden" name="page" value="models">
      <label class="small muted d-flex gap-1 align-items-center">
        <input type="checkbox" name="free" value="1" <?= $onlyFree ? 'checked' : '' ?>> только бесплатные
      </label>
      <label class="small muted d-flex gap-1 align-items-center">
        выход ≤
        <input type="text" name="maxout" value="<?= $maxOut > 0 ? (string)$maxOut : '' ?>" class="form-control form-control-sm" style="width:70px" placeholder="∞">
        $/1M
      </label>
      <button class="btn btn-sm btn-outline-secondary">Показать</button>
    </form>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr>
          <th>#</th><th>Модель</th><th class="text-end">объём за неделю</th>
          <th class="text-end">вход $/1M</th><th class="text-end">выход $/1M</th>
          <th class="text-end">кэш чт</th><th class="text-end">кэш записи</th>
          <th class="text-end">контекст</th><th class="text-center">качество</th>
          <th class="text-end">$ за задачу</th><th>бенчмарк</th><th class="text-end">действия</th>
        </tr></thead>
        <tbody>
        <?php if (!$top): ?>
          <tr><td colspan="12" class="muted small p-3">Рейтинг пуст — нажми «Объём», чтобы подтянуть список.</td></tr>
        <?php endif; ?>
        <?php foreach ($top as $t):
            $free   = (int)$t['is_free'] === 1;
            $score  = $t['avg_score'] !== null ? (float)$t['avg_score'] : null;
            $bench  = (string)($t['code_note'] ?? '');
            $benchOk = strpos($bench, 'валиден') !== false;
            $per    = $t['per_task'] !== null ? (float)$t['per_task'] : null;
            $lt     = model_last_test((string)$t['slug']);
            ?>
          <tr>
            <td class="muted"><?= (int)$t['pop_rank'] ?></td>
            <td>
              <div class="fw-semibold"><?= h((string)($t['name'] ?: $t['slug'])) ?></div>
              <div class="muted small"><?= h((string)$t['slug']) ?><?= $free ? ' · free' : '' ?></div>
            </td>
            <td class="text-end">
              <?= number_format((float)$t['pop_score'] / 1e12, 2) ?> T
              <div class="muted small"><?= number_format((float)$t['pop_requests'] / 1e6, 1) ?> M запр.</div>
            </td>
            <td class="text-end">
              <?php if ((float)($t['p_min_in'] ?? 0) > 0): ?>
                от <?= m_p((float)$t['p_min_in']) ?>
                <div class="muted small">маршрут <?= m_p((float)$t['p_in']) ?></div>
              <?php else: ?>
                <?= m_p((float)$t['p_in']) ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ((float)($t['p_min_out'] ?? 0) > 0): ?>
                <?= m_p((float)$t['p_min_out']) ?> – <?= m_p((float)$t['p_max_out']) ?>
                <div class="muted small">средняя <?= m_p((float)$t['p_avg_out']) ?>,
                  провайдеров: <?= (int)($t['providers'] ?? 0) ?></div>
                <?php if (!empty($t['p_best_provider'])): ?>
                  <div class="muted small">дешевле всех: <?= h((string)$t['p_best_provider']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <?= m_p((float)$t['p_out']) ?>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= m_p((float)$t['p_cache_read']) ?></td>
            <td class="text-end"><?= m_p((float)$t['p_cache_write']) ?></td>
            <td class="text-end"><?= m_ctx((float)$t['ctx']) ?></td>
            <td class="text-center">
              <?php if ((float)($t['pop_quality'] ?? 0) > 0): ?>
                <div><b><?= number_format((float)$t['pop_quality'], 1) ?></b></div>
                <div class="muted small">индекс OpenRouter</div>
              <?php endif; ?>
              <?php if ($lt): ?>
                <form method="post" class="d-flex gap-1 justify-content-center">
                  <input type="hidden" name="act" value="model_score">
                  <input type="hidden" name="id" value="<?= (int)$lt['id'] ?>">
                  <input type="number" name="score" min="1" max="5" class="form-control form-control-sm"
                         style="width:56px" placeholder="1-5"
                         value="<?= $lt['score'] !== null ? (int)$lt['score'] : '' ?>">
                  <button class="btn btn-sm btn-outline-primary">OK</button>
                </form>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= $per === null ? '—' : '$' . number_format($per, 4) ?></td>
            <td class="small">
              <?php if ($bench === ''): ?>
                <span class="muted">не гоняли</span>
              <?php else: ?>
                <span class="badge <?= $benchOk ? 'bg-success' : 'bg-secondary' ?>"><?= $benchOk ? 'код валиден' : 'код невалиден' ?></span>
                <div class="muted small">$<?= number_format((float)($t['code_cost'] ?? 0), 6) ?> · <?= (int)($t['code_ms'] ?? 0) ?> мс</div>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline">
                <input type="hidden" name="act" value="model_code_test">
                <input type="hidden" name="slug" value="<?= h((string)$t['slug']) ?>">
                <button class="btn btn-sm btn-outline-primary">Тест</button>
              </form>
              <form method="post" class="d-inline">
                <input type="hidden" name="act" value="model_test">
                <input type="hidden" name="slug" value="<?= h((string)$t['slug']) ?>">
                <button class="btn btn-sm btn-outline-secondary">Промт</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer py-2 small muted">
    Объём — токены за последнюю неделю в OpenRouter (рейтинг openrouter.ai/rankings, view=week),
    в скобках запросы. Качество — индекс OpenRouter, рядом оценка оператора 1..5.
    Кэш чт — цена чтения кэша, кэш записи — цена записи в кэш, обе за 1M токенов.
    <details class="mt-1">
      <summary style="cursor:pointer">Промт для кодинг-бенчмарка и для «Промт»-тестов</summary>
      <form method="post" class="mt-1">
        <input type="hidden" name="act" value="models_prompt">
        <textarea name="prompt" rows="7" class="form-control form-control-sm mb-1"><?= h(models_prompt_default()) ?></textarea>
        <button class="btn btn-sm btn-outline-primary">Сохранить общий промт</button>
      </form>
      <pre class="mt-1 p-2 bg-light rounded small" style="white-space:pre-wrap"><?= h(models_code_prompt()) ?></pre>
    </details>
  </div>
</div>

<!-- 2. ЦЕНА ЗА ВЫПОЛНЕННУЮ ЗАДАЧУ -->
<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-cash-coin me-1"></i>Цена одной выполненной задачи — по факту</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr>
          <th>Модель</th><th class="text-end">вызовов</th><th class="text-end">задач</th>
          <th class="text-end">$ всего</th><th class="text-end">$ за задачу</th>
          <th class="text-end">контекст/вызов</th><th class="text-end">выход</th><th class="text-end">среднее, мс</th>
        </tr></thead>
        <tbody>
        <?php if (!$econ): ?>
          <tr><td colspan="8" class="muted small p-3">Вызовов пока нет.</td></tr>
        <?php endif; ?>
        <?php foreach ($econ as $e):
            $pt = $e['per_task'] !== null ? (float)$e['per_task'] : null;
            ?>
          <tr>
            <td>
              <?= h((string)($e['name'] ?: $e['model'])) ?>
              <div class="muted small"><?= h((string)$e['model']) ?><?= (int)($e['is_free'] ?? 0) ? ' · free' : '' ?></div>
            </td>
            <td class="text-end"><?= (int)$e['calls'] ?></td>
            <td class="text-end"><?= (int)$e['tasks'] ?></td>
            <td class="text-end">$<?= number_format((float)$e['cost'], 4) ?></td>
            <td class="text-end fw-semibold"><?= $pt === null ? '—' : '$' . number_format($pt, 4) ?></td>
            <td class="text-end"><?= number_format((float)$e['ctx']) ?></td>
            <td class="text-end"><?= number_format((float)$e['tout']) ?></td>
            <td class="text-end"><?= number_format((float)$e['dur']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 3. ПРОЕКТЫ -->
<div class="card">
  <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-diagram-3 me-1"></i>Проекты: цена за задачу и предложение по назначению</span>
    <span class="muted small">доход заносится вручную</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr>
          <th>Проект</th><th>Текущая модель</th><th class="text-end">$ за задачу</th>
          <th class="text-end">расход</th><th class="text-end">доход</th><th class="text-end">прибыль</th>
          <th>Предложение</th><th class="text-end">действия</th><th>доход →</th>
        </tr></thead>
        <tbody>
        <?php foreach ($projects as $p):
            $pid   = (int)$p['id'];
            $cost  = (float)$p['cost'];
            $inc   = (float)($p['income_usd'] ?? 0);
            $tasks = (int)$p['tasks'];
            $per   = $tasks > 0 ? $cost / $tasks : null;
            $recs  = models_recommend($pid, 1);
            $rec   = $recs[0] ?? null;
            $cur   = (string)($p['model'] ?? '');
            ?>
          <tr>
            <td><?= ($p['icon'] ? $p['icon'] . ' ' : '') . h((string)$p['title']) ?></td>
            <td class="small"><?= $cur !== '' ? h($cur) : '<span class="muted">не назначена</span>' ?></td>
            <td class="text-end"><?= $per === null ? '—' : '$' . number_format($per, 4) ?></td>
            <td class="text-end">$<?= number_format($cost, 4) ?></td>
            <td class="text-end"><?= $inc > 0 ? '$' . number_format($inc, 2) : '<span class="muted">—</span>' ?></td>
            <td class="text-end <?= $inc > 0 ? ($inc - $cost >= 0 ? 'text-success' : 'text-danger') : '' ?>">
              <?= $inc > 0 ? '$' . number_format($inc - $cost, 2) : '—' ?>
            </td>
            <td class="small">
              <?php if ($rec): ?>
                <div><?= h((string)($rec['name'] ?: $rec['slug'])) ?>
                  <?= (int)($rec['is_free'] ?? 0) ? '<span class="badge bg-success">free</span>' : '' ?></div>
                <div class="muted">
                  выход <?= m_p((float)$rec['p_out']) ?> · контекст <?= m_ctx((float)$rec['ctx']) ?>
                  <?= $rec['code_cost'] !== null ? ' · бенчмарк $' . number_format((float)$rec['code_cost'], 6) : '' ?>
                </div>
              <?php else: ?>
                <span class="muted">нет данных</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <?php if ($rec): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="act" value="project_model">
                  <input type="hidden" name="project_id" value="<?= $pid ?>">
                  <input type="hidden" name="slug" value="<?= h((string)$rec['slug']) ?>">
                  <button class="btn btn-sm btn-primary">Назначить</button>
                </form>
              <?php endif; ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="act" value="model_rotate">
                <input type="hidden" name="project_id" value="<?= $pid ?>">
                <button class="btn btn-sm btn-outline-secondary">Ротация</button>
              </form>
            </td>
            <td>
              <form method="post" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="act" value="project_income">
                <input type="hidden" name="project_id" value="<?= $pid ?>">
                <input name="income" class="form-control form-control-sm" style="width:100px" placeholder="0.00"
                       value="<?= $inc > 0 ? (string)$inc : '' ?>">
                <button class="btn btn-sm btn-outline-secondary">OK</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('submit', function(ev){
  var f = ev.target;
  if (!f) return;
  var a = f.querySelector('input[name=act][value=model_code_test], input[name=act][value=model_test]');
  if (!a) return;
  var b = f.querySelector('button');
  if (b) { b.disabled = true; b.textContent = 'тестирую…'; }
});
</script>
