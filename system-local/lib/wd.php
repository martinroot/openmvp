<?php
declare(strict_types=1);

/**
 * Мост к автономному сторожу направления (direction_watchdog.py).
 *
 * План и статусы задач ведёт watchdog (источник правды). Трекер — вид и пульт
 * оператора: кнопки «+» и «удалить» меняют план в стороже, а статус приходит
 * обратно зеркалом (событие watchdog-task).
 *
 * Сторож лежит рядом с трекером на этой же машине, поэтому вызов локальный.
 */

// Сторож живёт в /opt: каталог доступен веб-серверу (группа www-data),
// поэтому кнопки трекера правят настоящий план. Секреты (wintask.env) — 600 main.
const WD_DIR    = '/opt/wintask_watchdog';
const WD_PY     = '/usr/bin/python3';   // системный python: CLI не требует telethon
const WD_SCRIPT = WD_DIR . '/direction_watchdog.py';

/**
 * Окружение сторожа из wintask.env (ключи, модели, тарифы).
 * @return array<string,string>
 */
function wd_env(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }
    $env = [];
    $file = WD_DIR . '/wintask.env';
    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\"'");
        }
    }
    return $env;
}

/**
 * Выполнить команду сторожа.
 * @param array<int,string|int> $args
 * @return array{code:int,out:string,err:string}
 */
function wd_run(array $args, int $timeout = 40): array
{
    $cmd = escapeshellcmd(WD_PY) . ' ' . escapeshellarg(WD_SCRIPT);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string)$a);
    }
    $env = wd_env();
    $env['PATH']    = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
    $env['HOME']    = '/tmp';
    $env['LANG']    = 'C.UTF-8';
    $descr = [];
    foreach ($env as $k => $v) {
        $descr[] = $k . '=' . $v;
    }
    $pipes = [];
    // cwd = /tmp: рабочий каталог веб-сервера может быть недоступен www-data
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/tmp', $descr);
    if (!is_resource($proc)) {
        return ['code' => 127, 'out' => '', 'err' => 'proc_open не удался'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $deadline = time() + $timeout;
    $code = 0;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $code = (int)$st['exitcode'];
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc);
            $code = 124;
            $err .= ' таймаут ' . $timeout . 'с';
            break;
        }
        usleep(80000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return ['code' => $code, 'out' => trim($out), 'err' => trim($err)];
}

/**
 * Текущий план сторожа: id => задача.
 * @return array<string,array>
 */
function wd_tasks(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = wd_run(['status']);
    $cache = [];
    if ($r['code'] === 0) {
        $data = json_decode($r['out'], true);
        foreach (($data['tasks'] ?? []) as $t) {
            $cache[(string)$t['id']] = $t;
        }
    }
    return $cache;
}

/**
 * Текущий бизнес-фокус сторожа (задача «деньги») или ''.
 */
function wd_focus(): string
{
    $r = wd_run(['status']);
    if ($r['code'] !== 0) {
        return '';
    }
    $data = json_decode($r['out'], true);
    $tasks = $data['tasks'] ?? [];
    foreach ($tasks as $t) {
        if (($t['status'] ?? '') === 'running') {
            return (string)$t['id'];
        }
    }
    return '';
}

/**
 * Следующий свободный id задачи: максимум по сторожу и по трекеру + 1.
 */
function wd_next_id(): int
{
    $max = 0;
    foreach (array_keys(wd_tasks()) as $id) {
        if (ctype_digit((string)$id)) {
            $max = max($max, (int)$id);
        }
    }
    $row = one("SELECT MAX(id) m FROM projects WHERE kind='task'");
    if ($row && $row['m'] !== null) {
        $max = max($max, (int)$row['m']);
    }
    return $max + 1;
}

/**
 * Приоритет трекера (1 = деньги) -> приоритет сторожа (больше = раньше).
 * Ниже текущего фокуса, иначе барьер фокуса отклонит задачу.
 */
function wd_priority(int $trackerPrio): int
{
    $map = [1 => 95, 2 => 80, 3 => 60, 4 => 40, 5 => 20];
    return $map[$trackerPrio] ?? 40;
}

/**
 * Добавить задачу в план сторожа.
 * @param array<int,string> $criteria
 * @return array{code:int,out:string,err:string}
 */
function wd_add_task(int $id, string $title, array $criteria, int $trackerPrio = 2): array
{
    if (!$criteria) {
        return ['code' => 2, 'out' => '', 'err' => 'Нужен хотя бы один критерий готовности'];
    }
    $args = ['add', '--id', (string)$id, '--title', $title, '--priority', (string)wd_priority($trackerPrio)];
    foreach ($criteria as $c) {
        $args[] = '--criterion';
        $args[] = $c;
    }
    // Связываем с текущим фокусом: иначе барьер «деньги» отклонит задачу.
    $focus = wd_focus();
    if ($focus !== '' && $focus !== (string)$id) {
        $args[] = '--parent';
        $args[] = $focus;
    }
    return wd_run($args);
}

/**
 * Сверить статусы задач в трекере с планом сторожа.
 * Сторож — источник правды, трекер показывает; без сверки UI может разойтись
 * (особенно после ручных правок). Задачи, которых нет в плане, не трогаем.
 * @return array{updated:int,missing:int}
 */
function wd_sync_statuses(): array
{
    $map = [
        'done'    => 'done',
        'running' => 'active',
        'review'  => 'review',
        'blocked' => 'blocked',
        'todo'    => 'planned',
    ];
    $updated = 0;
    $missing = 0;
    foreach (wd_tasks() as $id => $t) {
        $want = $map[(string)($t['status'] ?? '')] ?? null;
        if ($want === null) {
            continue;
        }
        $row = one("SELECT id, status FROM projects WHERE id=? AND kind='task'", 'i', [(int)$id]);
        if (!$row) {
            $missing++;
            continue;
        }
        if ((string)$row['status'] !== $want) {
            q("UPDATE projects SET status=?, closed_at=? WHERE id=?",
              'ssi', [$want, $want === 'done' ? date('Y-m-d H:i:s') : null, (int)$id]);
            $updated++;
        }
    }
    return ['updated' => $updated, 'missing' => $missing];
}

/**
 * Удалить задачу из плана сторожа (если её там есть).
 * @return array{code:int,out:string,err:string}
 */
function wd_remove_task(int $id, string $reason): array
{
    if (!isset(wd_tasks()[(string)$id])) {
        return ['code' => 0, 'out' => 'задачи нет в плане сторожа', 'err' => ''];
    }
    return wd_run(['remove', '--id', (string)$id, '--reason', $reason]);
}
