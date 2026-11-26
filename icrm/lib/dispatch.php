<?php
declare(strict_types=1);
/**
 * Планировщик выдачи задач.
 *
 * Логика:
 *  - у каждого направления есть окно выдачи: «не раньше HH:MM (МСК)» и
 *    «не позже HH:MM (МСК)» внутри дня — задача не уходит в часы, когда
 *    оператор/агент этого не ждёт;
 *  - задачи из RoadMap ставятся по цепочке: следующая готовится только после
 *    завершения текущей — и с готовностью, зависящей от фактической
 *    производительности агента (долго/коротко, были ли ошибки);
 *  - всё в одной тайм-зоне: MSK (Europe/Moscow). В БД храним UTC.
 */
require_once __DIR__ . '/core.php';

const DISPATCH_TZ      = 'Europe/Moscow';
const DISPATCH_TZ_NAME = 'MSK';

/** Настройки планировщика: глобальные + правки направления. */
function dispatch_settings(int $projectId = 0): array
{
    $def = [
        'window_from'   => '',      // не раньше (МСК), пусто = без ограничения снизу
        'window_to'     => '',      // не позже (МСК), пусто = без ограничения сверху
        'min_gap_min'   => 0,       // минимальная пауза между задачами, мин
        'max_gap_min'   => 0,       // максимальная пауза (0 = ограничения нет)
        'adaptive'      => 1,       // учитывать производительность агента
        'auto_next'     => 1,       // автопилот: следующая задача готовится сама
        'auto_next_delay_min' => 0, // базовая пауза автопилота, мин
        'batch_window'  => 1,       // 1 = выдавать по одной, 0 = пачкой
    ];
    $get = static function (string $k, string $defv = '') use (&$def): string {
        try {
            $r = one("SELECT v FROM settings WHERE k=?", 's', [$k]);
            $v = $r ? trim((string)$r['v']) : '';
            return $v !== '' ? $v : $defv;
        } catch (Throwable $e) {
            return $defv;
        }
    };
    foreach (array_keys($def) as $k) {
        $def[$k] = $get('dispatch_' . $k);
    }
    if ($projectId > 0) {
        try {
            $p = one("SELECT dispatch_window_from, dispatch_window_to, dispatch_min_gap_min,
                             dispatch_max_gap_min, dispatch_adaptive, dispatch_auto_next,
                             dispatch_auto_next_delay_min, dispatch_batch_window
                      FROM projects WHERE id=?", 'i', [$projectId]);
            if ($p) {
                foreach (['window_from' => 'dispatch_window_from',
                          'window_to'   => 'dispatch_window_to',
                          'min_gap_min' => 'dispatch_min_gap_min',
                          'max_gap_min' => 'dispatch_max_gap_min',
                          'adaptive'    => 'dispatch_adaptive',
                          'auto_next'   => 'dispatch_auto_next',
                          'auto_next_delay_min' => 'dispatch_auto_next_delay_min',
                          'batch_window' => 'dispatch_batch_window'] as $k => $col) {
                    $v = $p[$col];
                    if ($v === null || $v === '') {
                        continue;
                    }
                    // интервалы: 0 = «как в глобальных настройках» (иначе проектные
                    // нули по умолчанию обнуляли бы глобальную паузу);
                    // флаги 0/1 — явное значение направления
                    if (in_array($k, ['min_gap_min', 'max_gap_min', 'auto_next_delay_min'], true)
                        && (int)$v === 0) {
                        continue;
                    }
                    $def[$k] = (string)$v;
                }
            }
        } catch (Throwable $e) {
            // колонок может не быть — работаем на глобальных
        }
    }
    $def['window_from'] = trim((string)$def['window_from']);
    $def['window_to']   = trim((string)$def['window_to']);
    return $def;
}

/** Текущее время в MSK как DateTimeImmutable. */
function dispatch_now(): DateTimeImmutable
{
    return (new DateTimeImmutable('now', new DateTimeZone(DISPATCH_TZ)));
}

/** Строку HH:MM → DateTimeImmutable сегодня в MSK (или null). */
function dispatch_time_today(string $hhmm, ?DateTimeImmutable $base = null): ?DateTimeImmutable
{
    $hhmm = trim($hhmm);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
        return null;
    }
    $base = $base ?: dispatch_now();
    $day = $base->setTime((int)$m[1], (int)$m[2], 0);
    return $day;
}

/**
 * Ближайшее время выдачи: не раньше $after, но с учётом окна MSK.
 * Если окно закрыто (после window_to), откладываем на следующий день
 * (окно открыто с window_from).
 * Возвращает UTC-строку 'Y-m-d H:i:s' для записи в БД.
 */
function dispatch_slot(?DateTimeImmutable $after = null, array $cfg = [], int $projectId = 0): string
{
    $cfg = $cfg ?: dispatch_settings($projectId);
    $after = $after ?: dispatch_now();
    $from  = dispatch_time_today((string)($cfg['window_from'] ?? ''), $after);
    $to    = dispatch_time_today((string)($cfg['window_to'] ?? ''), $after);

    if ($from === null && $to === null) {
        return $after->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    // целевое время = старт окна, если $after раньше старта окна
    $target = $after;
    if ($from !== null && $after < $from) {
        $target = $from;
    }
    // если целевое попало за закрытие окна — переносим на следующий день
    if ($to !== null && $target > $to) {
        $target = $from ? $from->modify('+1 day') : $after->modify('+1 day');
    }
    return $target->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Пауза до следующей задачи по факту производительности агента.
 *
 * Чем дольше агент работал над задачей и чем больше было ошибок, тем длиннее
 * пауза перед следующей: это и есть «в зависимости от производительности,
 * нужно уметь ждать». Плюс минимальный и максимальный интервалы.
 */
function dispatch_gap_minutes(int $projectId, array $cfg = []): int
{
    $cfg = $cfg ?: dispatch_settings($projectId);
    $min = max(0, (int)($cfg['min_gap_min'] ?? 0));
    $max = (int)($cfg['max_gap_min'] ?? 0);
    $adaptive = (int)($cfg['adaptive'] ?? 1) === 1;

    $gap = $min;
    if ($adaptive) {
        // последние завершённые задачи направления: длина работы и ошибки
        $last = all("SELECT t.id, t.closed_at,
                        (SELECT COALESCE(SUM(a.duration_ms),0) FROM agent_runs a WHERE a.task_id=t.id)
                        + (SELECT COALESCE(SUM(c.duration_ms),0) FROM llm_calls c WHERE c.task_id=t.id) dur,
                        (SELECT COUNT(*) FROM events e WHERE e.task_id=t.id AND e.level='error') errs
                 FROM projects t
                 WHERE t.parent_id=? AND t.kind='task' AND t.status='done'
                 ORDER BY t.closed_at DESC LIMIT 3", 'i', [$projectId]);
        if ($last) {
            $avgDur = 0;
            $errs   = 0;
            foreach ($last as $l) {
                $avgDur += (int)$l['dur'];
                $errs   += (int)$l['errs'];
            }
            $avgDur = (int)round($avgDur / count($last));
            // чем дольше прошла задача, тем больше пауза: 1 минута работы → 1 минута паузы
            $fromWork = (int)round($avgDur / 60000);
            $gap = max($gap, $fromWork);
            $gap += $errs * 5;                       // ошибки — тоже повод подождать
            if ($avgDur > 0 && $avgDur < 15000) {
                $gap = max($gap, (int)round($min));  // быстрые задачи — не тормозим
            }
        }
    }
    if ($max > 0 && $gap > $max) {
        $gap = $max;
    }
    return max(0, $gap);
}

/**
 * Готова ли задача к выдаче прямо сейчас.
 */
function dispatch_ready(?string $readyAtUtc, array $cfg = [], int $projectId = 0): bool
{
    $cfg = $cfg ?: dispatch_settings($projectId);
    // окно выдачи соблюдается всегда: сейчас час вне окна — не выдаём
    $now = dispatch_now();
    $from = dispatch_time_today((string)($cfg['window_from'] ?? ''), $now);
    $to   = dispatch_time_today((string)($cfg['window_to'] ?? ''), $now);
    if ($from !== null && $to !== null) {
        if ($now < $from || $now > $to) {
            return false;
        }
    }
    if (!$readyAtUtc || $readyAtUtc === '') {
        return true;
    }
    $t = strtotime($readyAtUtc . ' UTC');
    if ($t === false) {
        return true;
    }
    return time() >= $t;
}

/**
 * Готова ли конкретная задача к выдаче: время готовности + правило «одна
 * задача из цепочки за раз» (следующий шаг ждёт, пока предыдущий не закрыт).
 */
function dispatch_task_eligible(array $t, int $projectId = 0): bool
{
    if (!dispatch_ready(isset($t['ready_at']) ? (string)$t['ready_at'] : null, [], $projectId)) {
        return false;
    }
    $chain = trim((string)($t['auto_chain'] ?? ''));
    if ($chain !== '' && (int)($t['auto_step'] ?? 0) > 1) {
        // ждём, пока предыдущие шаги цепочки будут закрыты
        $q = one("SELECT COUNT(*) c FROM projects
                 WHERE parent_id=? AND kind='task' AND auto_chain=? AND auto_step<? AND status<>'done'",
                 'isi', [(int)($t['parent_id'] ?? 0), $chain, (int)$t['auto_step']]);
        if ((int)($q['c'] ?? 0) > 0) {
            return false;
        }
    }
    return true;
}

/**
 * Карта готовности задач направления: id => можно ли выдавать сейчас.
 * Учитывает окно выдачи (MSK), время готовности и правило «из цепочки
 * RoadMap выдаём только голову».
 */
function dispatch_eligible_map(array $tasks, int $projectId = 0): array
{
    $cfg = dispatch_settings($projectId);
    $map = [];
    foreach ($tasks as $t) {
        $id = (int)($t['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        // закрытые/отменённые/заблокированные задачи не выдаются никогда
        if (in_array(strtolower((string)($t['status'] ?? '')), ['done', 'cancelled', 'blocked', 'paused'], true)) {
            $map[$id] = false;
            continue;
        }
        $ok = dispatch_ready(isset($t['ready_at']) ? (string)$t['ready_at'] : null, $cfg, $projectId);
        if ($ok) {
            $chain = trim((string)($t['auto_chain'] ?? ''));
            $step  = (int)($t['auto_step'] ?? 0);
            if ($chain !== '' && $step > 1) {
                foreach ($tasks as $o) {
                    if ((int)($o['id'] ?? 0) === $id) {
                        continue;
                    }
                    if (trim((string)($o['auto_chain'] ?? '')) !== $chain) {
                        continue;
                    }
                    if ((int)($o['auto_step'] ?? 0) >= $step) {
                        continue;
                    }
                    if (strtolower((string)($o['status'] ?? '')) === 'done') {
                        continue;
                    }
                    $ok = false;
                    break;
                }
            }
        }
        $map[$id] = $ok;
    }
    return $map;
}

/** Человекочитаемое «выдача в HH:MM (МСК)». */
function dispatch_human(?string $readyAtUtc): string
{
    if (!$readyAtUtc || $readyAtUtc === '') {
        return 'сразу';
    }
    try {
        $utc = new DateTimeImmutable($readyAtUtc, new DateTimeZone('UTC'));
        $msk = $utc->setTimezone(new DateTimeZone(DISPATCH_TZ));
        $today = dispatch_now()->setTime(0, 0);
        $diffDays = (int)$today->diff($msk->setTime(0, 0))->format('%r%a');
        $prefix = $diffDays === 0 ? 'сегодня ' : ($diffDays === 1 ? 'завтра ' : $msk->format('d.m') . ' ');
        return $prefix . $msk->format('H:i') . ' ' . DISPATCH_TZ_NAME;
    } catch (Throwable $e) {
        return (string)$readyAtUtc;
    }
}
