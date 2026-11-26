<?php
declare(strict_types=1);

/**
 * SYSTEM — трекер проектов maximhot.
 * Общий каркас: БД, helpers, layout (Bootstrap 5.3.3, светлая тема).
 */

const DB_HOST = 'localhost';
const DB_NAME = 'system';
const DB_USER = 'system';
const DB_PASS = 'CHANGE_ME';

const CHAT_URL = 'https://velvetflux.click/money/api/chat.php';
const CHAT_SERVER = 'wifepornhub';
const CHAT_TOKEN = 'Ll9IMKbjsCJW4riaQRdmFliUY3dBIkee';

define('ROOT', dirname(__DIR__));
define('VIEWS', ROOT . '/views');
define('ASSETS', '/assets');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/chat.php';

function db(): mysqli
{
    static $db = null;
    if ($db === null) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
        if ($db->connect_errno) {
            die('DB error: ' . $db->connect_error);
        }
    }
    return $db;
}

function q(string $sql, string $types = '', array $args = []): mysqli_stmt
{
    $st = db()->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$args);
    }
    $st->execute();
    return $st;
}

function all(string $sql, string $types = '', array $args = []): array
{
    $st = q($sql, $types, $args);
    $res = $st->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $st->close();
    return $rows;
}

function one(string $sql, string $types = '', array $args = []): ?array
{
    $rows = all($sql, $types, $args);
    return $rows[0] ?? null;
}

function scalar(string $sql, string $types = '', array $args = []): mixed
{
    $st = q($sql, $types, $args);
    $row = $st->get_result()->fetch_row();
    $st->close();
    return $row ? $row[0] : null;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function e(mixed $v): string
{
    return h(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
}

function req_str(string $k, string $d = ''): string
{
    $v = $_GET[$k] ?? $_POST[$k] ?? $d;
    return is_string($v) ? trim($v) : $d;
}

function req_int(string $k, int $d = 0): int
{
    $v = $_GET[$k] ?? $_POST[$k] ?? null;
    return is_numeric($v) ? (int)$v : $d;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Дерево проектов: все узлы + сгруппированные по parent_id */
function tree_all(): array
{
    return all("SELECT * FROM projects ORDER BY kind ASC, prio ASC, pos ASC, id ASC");
}

function tree_build(?int $parent = null, array $flat = null, int $depth = 0): array
{
    $flat ??= tree_all();
    $out = [];
    foreach ($flat as $n) {
        $pid = $n['parent_id'] === null ? null : (int)$n['parent_id'];
        if ($pid === $parent) {
            $n['children'] = $depth < 5 ? tree_build((int)$n['id'], $flat, $depth + 1) : [];
            $out[] = $n;
        }
    }
    return $out;
}

function node(int $id): ?array
{
    return one("SELECT * FROM projects WHERE id = ?", 'i', [$id]);
}

function node_path(int $id): array
{
    $path = [];
    $cur = node($id);
    $guard = 0;
    while ($cur && $guard++ < 10) {
        array_unshift($path, $cur);
        $cur = $cur['parent_id'] ? node((int)$cur['parent_id']) : null;
    }
    return $path;
}

/** Отступ для выпадающих списков по глубине узла */
function indent_of(int $id): string
{
    return str_repeat('\u{00b7} ', max(0, count(node_path($id)) - 1));
}

/**
 * Задача выполнена: помечаем текущую активную задачу готово и активируем следующую.
 * Возвращает ['closed' => id|0, 'next' => id|0, 'title' => ...].
 */
/**
 * Направление и его подпроекты: узлы, чьи задачи принадлежат направлению.
 * Задача, поставленная в подпроект, уходит агенту этого направления.
 */
function direction_scope_ids(int $projectId): array
{
    $ids = [$projectId];
    $walk = function (array $nodes) use (&$walk, &$ids): void {
        foreach ($nodes as $n) {
            $ids[] = (int)$n['id'];
            if (!empty($n['children'])) {
                $walk($n['children']);
            }
        }
    };
    $walk(tree_build($projectId));
    return $ids;
}

/**
 * Компактная сводка по направлению:
 *  - версия: каждая закрытая задача +0.01, после 100 закрытых — +1 (V 0.37, V 1.00);
 *  - задачи: закрыто/всего;
 *  - токены агентов (объём: in + cache + out + reasoning);
 *  - траты MLL-советчика по этому направлению.
 */
function project_stats(int $projectId): array
{
    $ids = direction_scope_ids($projectId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $r = one("SELECT COUNT(*) total, COALESCE(SUM(status='done'),0) done
              FROM projects WHERE parent_id IN ($ph) AND kind='task'", $types, $ids);
    $done = (int)($r['done'] ?? 0);
    $total = (int)($r['total'] ?? 0);
    $version = sprintf('%d.%02d', intdiv($done, 100), $done % 100);

    $t = one("SELECT COALESCE(SUM(tok_in + tok_cache + tok_cache_write + tok_out + tok_think),0) tok
              FROM llm_calls WHERE project_id = ?", 'i', [$projectId]);
    $m = one("SELECT COALESCE(SUM(cost_usd),0) c, COUNT(*) n FROM mll_runs WHERE project_id = ?",
              'i', [$projectId]);

    return [
        'version'  => $version,
        'done'     => $done,
        'total'    => $total,
        'tokens'   => (int)($t['tok'] ?? 0),
        'mll_cost' => (float)($m['c'] ?? 0),
        'mll_calls'=> (int)($m['n'] ?? 0),
    ];
}

/** Компактный объём: 35,4M / 1,2K / 890 */
function format_tokens_ru(int|float $n): string
{
    $n = (float)$n;
    if ($n >= 1e9) {
        return number_format($n / 1e9, 1, ',', ' ') . 'B';
    }
    if ($n >= 1e6) {
        return number_format($n / 1e6, 1, ',', ' ') . 'M';
    }
    if ($n >= 1e3) {
        return number_format($n / 1e3, 1, ',', ' ') . 'K';
    }
    return number_format($n, 0, ',', ' ');
}

function complete_active_task(int $projectId): array
{
    $tasks = all("SELECT id, title, pos, status FROM projects
                  WHERE parent_id = ? AND kind='task' ORDER BY prio, pos, id", 'i', [$projectId]);
    $active = null;
    $next = null;
    foreach ($tasks as $t) {
        if ($t['status'] !== 'done') {
            if ($active === null) {
                $active = $t;
            } else {
                $next = $t;
                break;
            }
        }
    }
    if (!$active) {
        return ['closed' => 0, 'next' => 0, 'title' => ''];
    }
    q("UPDATE projects SET status='done', closed_at=NOW() WHERE id=?", 'i', [(int)$active['id']]);
    if ($next) {
        q("UPDATE projects SET status='active', closed_at=NULL WHERE id=?", 'i', [(int)$next['id']]);
    }
    return ['closed' => (int)$active['id'], 'next' => $next ? (int)$next['id'] : 0,
            'title' => (string)$active['title'], 'next_title' => $next ? (string)$next['title'] : ''];
}

/**
 * Статус конкретной задачи по протоколу агента: TASK|<id>|<СТАТУС>.
 * DONE  — задача выполнена, активируем следующую по порядку.
 * ACTIVE — задача в работе.
 */
function set_task_status(int $taskId, string $status, bool $requireActive = false): array
{
    $t = node($taskId);
    if (!$t) {
        return ['ok' => false, 'error' => 'task not found: ' . $taskId];
    }
    // Защита от чужой нумерации: агент может прислать свой номер.
    // Если требуется строго — отметка применяется только к активной задаче проекта.
    if ($requireActive && !empty($t['parent_id'])) {
        $act = one("SELECT id FROM projects WHERE parent_id=? AND kind='task' AND status<>'done'
                    ORDER BY prio, pos, id LIMIT 1", 'i', [(int)$t['parent_id']]);
        $actId = $act ? (int)$act['id'] : 0;
        if ($actId && $actId !== $taskId) {
            return ['ok' => false, 'error' => 'not_active', 'active_id' => $actId];
        }
    }
    $status = strtoupper(trim($status));
    if ($status === 'DONE') {
        q("UPDATE projects SET status='done', closed_at=NOW() WHERE id=?", 'i', [$taskId]);
        // автопилот: следующий шаг цепочки RoadMap готовится сам, с учётом паузы
        try {
            require_once __DIR__ . '/dispatch.php';
            require_once __DIR__ . '/roadmap.php';
            $done = node($taskId);
            // разбор результата выполненной задачи (факты: вызовы, токены, ошибки)
            try {
                require_once __DIR__ . '/plan.php';
                plan_review_task($taskId);
            } catch (Throwable $e) {
                // разбор не должен ломать закрытие
            }
            if ($done && !empty($done['auto_chain']) && !empty($done['parent_id'])) {
                $pid = (int)$done['parent_id'];
                $cfg = dispatch_settings($pid);
                // проверить настройку проекта auto_roadmap — если выключено, не продолжаем цепочку
                $proj = node($pid);
                if (!$proj || (int)($proj['auto_roadmap'] ?? 0) !== 1) {
                    // авто-цепочка отключена на уровне проекта
                } elseif ((int)($cfg['auto_next'] ?? 1) === 1) {
                    $chain = (string)$done['auto_chain'];
                    $steps = roadmap_chain_steps($pid, $chain);
                    if ($steps) {
                        $r = roadmap_autopilot_next($pid, $chain, $steps, (int)$done['auto_step'], $cfg);
                        if (!empty($r['task_id'])) {
                            q("INSERT INTO events (project_id, task_id, kind, level, title, text)
                               VALUES (?, ?, 'system', 'info', ?, ?)",
                              'iiss', [$pid, (int)$r['task_id'], 'автопилот: следующая задача готова',
                                        'шаг ' . (int)($r['step'] ?? 0) . ', выдача: '
                                        . dispatch_human((string)($r['ready_at'] ?? ''))]);
                        }
                    } else {
                        // цепочка не из RoadMap — следующий шаг уже стоит в плане,
                        // просто назначаем ему время готовности (пауза по производительности)
                        $nextInChain = one("SELECT id, title FROM projects
                                            WHERE parent_id=? AND kind='task' AND auto_chain=?
                                              AND auto_step=? AND status<>'done' LIMIT 1",
                                           'isi', [$pid, $chain, (int)$done['auto_step'] + 1]);
                        if ($nextInChain) {
                            $gapMin = dispatch_gap_minutes($pid, $cfg);
                            $ready  = dispatch_slot(dispatch_now()->modify('+' . $gapMin . ' minutes'), $cfg, $pid);
                            q("UPDATE projects SET ready_at=? WHERE id=?", 'si', [$ready, (int)$nextInChain['id']]);
                            q("INSERT INTO events (project_id, task_id, kind, level, title, text)
                               VALUES (?, ?, 'system', 'info', ?, ?)",
                              'iiss', [$pid, (int)$nextInChain['id'], 'автопилот: следующая задача готова',
                                        'выдача: ' . dispatch_human($ready)]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // автопилот не должен ломать закрытие задачи
        }
        $next = null;
        if (!empty($t['parent_id'])) {
            $tasks = all("SELECT id, ready_at FROM projects WHERE parent_id=? AND kind='task' AND status<>'done'
                          ORDER BY prio, pos, id", 'i', [(int)$t['parent_id']]);
            $next = $tasks[0] ?? null;
            if ($next) {
                // следующая задача не выдаётся моментально: ждёт окно и паузу
                if (empty($next['ready_at'])) {
                    try {
                        require_once __DIR__ . '/dispatch.php';
                        $pid = (int)$t['parent_id'];
                        $cfg = dispatch_settings($pid);
                        $gap = dispatch_gap_minutes($pid, $cfg);
                        $slot = dispatch_slot(dispatch_now()->modify('+' . $gap . ' minutes'), $cfg, $pid);
                        q("UPDATE projects SET ready_at=? WHERE id=?", 'si', [$slot, (int)$next['id']]);
                    } catch (Throwable $e) {
                        // планировщик не должен ломать закрытие
                    }
                }
                // активируем только если задача уже готова к выдаче
                $activate = true;
                try {
                    require_once __DIR__ . '/dispatch.php';
                    $pid2 = (int)$t['parent_id'];
                    $cfg2 = dispatch_settings($pid2);
                    $activate = dispatch_ready((string)($next['ready_at'] ?? ''), $cfg2, $pid2);
                } catch (Throwable $e) {
                    $activate = true;
                }
                if ($activate) {
                    q("UPDATE projects SET status='active', closed_at=NULL WHERE id=?", 'i', [(int)$next['id']]);
                }
            }
        }
        return ['ok' => true, 'task_id' => $taskId, 'status' => 'done',
                'next' => $next ? (int)$next['id'] : 0];
    }
    if ($status === 'ACTIVE' || $status === 'IN_PROGRESS' || $status === 'WORK') {
        q("UPDATE projects SET status='active', closed_at=NULL WHERE id=?", 'i', [$taskId]);
        return ['ok' => true, 'task_id' => $taskId, 'status' => 'active', 'next' => 0];
    }
    return ['ok' => true, 'task_id' => $taskId, 'status' => $status, 'next' => 0, 'note' => 'статус не менял'];
}
function status_label(string $s): string
{
    return [
        'planned' => 'Запланирована',
        'active'  => 'В работе',
        'blocked' => 'Блок',
        'review'  => 'На проверке',
        'done'    => 'Готово',
        'cancelled' => 'Отменена',
    ][$s] ?? $s;
}

function status_color(string $s): string
{
    return [
        'planned' => 'secondary',
        'active'  => 'primary',
        'blocked' => 'danger',
        'review'  => 'warning',
        'done'    => 'success',
        'cancelled' => 'dark',
    ][$s] ?? 'secondary';
}

/**
 * Агрегаты по поддереву (включая сам узел).
 * Метрики берём из agent_runs — это источник истины. Поля projects.cost_total
 * и т.п. НЕ суммируются: они лежат на каждом уровне и дали бы задвоение.
 */
function subtree_agg(int $id): array
{
    $row = one("
        WITH RECURSIVE t AS (
            SELECT id FROM projects WHERE id = ?
            UNION ALL
            SELECT p.id FROM projects p JOIN t ON p.parent_id = t.id
        )
        SELECT
          (SELECT COUNT(*) FROM projects WHERE id IN (SELECT id FROM t)) AS nodes,
          (SELECT COALESCE(SUM(cost_usd),0)   FROM agent_runs WHERE project_id IN (SELECT id FROM t)) AS cost,
          (SELECT COALESCE(SUM(tok_in),0)     FROM agent_runs WHERE project_id IN (SELECT id FROM t)) AS tok_in,
          (SELECT COALESCE(SUM(tok_cache),0)  FROM agent_runs WHERE project_id IN (SELECT id FROM t)) AS tok_cache,
          (SELECT COALESCE(SUM(tok_out),0)    FROM agent_runs WHERE project_id IN (SELECT id FROM t)) AS tok_out,
          (SELECT COUNT(*)                    FROM agent_runs WHERE project_id IN (SELECT id FROM t)) AS runs,
          (SELECT COALESCE(SUM(duration_ms),0) FROM agent_runs WHERE project_id IN (SELECT id FROM t)) AS dur,
          (SELECT COUNT(*) FROM projects WHERE id IN (SELECT id FROM t) AND status='done') AS done,
          (SELECT COUNT(*) FROM projects WHERE id IN (SELECT id FROM t)
             AND status IN ('planned','active','blocked','review')) AS open_n",
        'i', [$id]);
    return $row ?: [];
}

/** Ближайшая цель дня / глобальная */
function goals_for(string $dayKey = null): array
{
    $dayKey ??= date('Y-m-d');
    $out = [];
    foreach (all("SELECT * FROM goals") as $g) {
        if ($g['scope'] === 'day' && $g['period_key'] !== $dayKey) {
            continue;
        }
        $out[$g['scope']] = $g;
    }
    return $out;
}

/** Потрачено сегодня (по agent_runs) */
function spend_since(string $since): float
{
    return (float)scalar("SELECT COALESCE(SUM(cost_usd),0) FROM agent_runs WHERE ts >= ?", 's', [$since]);
}

function format_usd(float $v): string
{
    if ($v >= 1000) {
        return '$' . number_format($v, 0);
    }
    if ($v >= 1) {
        return '$' . number_format($v, 2);
    }
    return '$' . number_format($v, 4);
}

function format_tokens(int $v): string
{
    if ($v >= 1_000_000) {
        return round($v / 1_000_000, 2) . 'M';
    }
    if ($v >= 1_000) {
        return round($v / 1_000, 1) . 'K';
    }
    return (string)$v;
}

function format_dur(int $ms): string
{
    if ($ms <= 0) {
        return '—';
    }
    $s = $ms / 1000;
    if ($s < 60) {
        return rtrim(rtrim(number_format($s, 1, '.', ''), '0'), '.') . 's';
    }
    $m = intdiv((int)round($s), 60);
    if ($m < 60) {
        return $m . 'm ' . ($s - $m * 60) . 's';
    }
    return intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
}

function rel_time(?string $ts): string
{
    if (!$ts) {
        return '—';
    }
    $d = time() - strtotime($ts);
    if ($d < 60)     return 'только что';
    if ($d < 3600)   return intdiv($d, 60) . ' мин назад';
    if ($d < 86400)  return intdiv($d, 3600) . ' ч назад';
    if ($d < 604800) return intdiv($d, 86400) . ' дн назад';
    return substr($ts, 0, 10);
}

/** Форматирование даты */
function dt(?string $ts, string $fmt = 'd.m.Y H:i'): string
{
    return $ts ? date($fmt, strtotime($ts)) : '—';
}

function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $page = basename($view, '.php');
    $file = VIEWS . '/' . (str_ends_with($view, '.php') ? $view : $view . '.php');
    if (!is_file($file)) {
        http_response_code(500);
        echo '<!doctype html><meta charset=utf-8><h3>Шаблон не найден: ' . h($file) . '</h3>';
        return;
    }
    $embed = (string)($_GET['embed'] ?? '') === '1'; // bare-режим: без меню, для iframe
    if ($embed) {
        echo '<!doctype html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">'
           . '<link href=https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css rel=stylesheet>'
           . '<style>body{margin:10px 12px;font-size:14px;background:#fff}</style></head><body>';
    } else {
        require VIEWS . '/_layout_top.php';
    }
    try {
        require $file;
    } catch (Throwable $e) {
        echo '<div class="alert alert-danger"><b>Ошибка в представлении</b><pre class="mb-0" style="white-space:pre-wrap">'
            . h($e->getMessage()) . "\n" . h($e->getTraceAsString()) . '</pre></div>';
    }
    if ($embed) {
        echo '</body></html>';
    } else {
        require VIEWS . '/_layout_bottom.php';
    }
}
