<?php
declare(strict_types=1);
/**
 * Проверка соответствия типов bind_param количеству плейсхолдеров в q().
 * Запуск: php bin/check_queries.php
 *
 * Парсит вызовы q("SQL", 'types', [args]) вручную (скобки сбалансированы),
 * динамические вызовы (types не литерал) пропускает.
 */
require_once __DIR__ . '/../lib/core.php';   // нужен доступ к БД: сверка буквы типа с типом колонки

$files = [];
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));
foreach ($dir as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

/** Разбор одного вызова q() начиная с позиции $p (указатель на '('). */
function parse_call(string $src, int $p): ?array
{
    $len = strlen($src);
    $i = $p + 1;
    $skip = " \t\n\r";
    while ($i < $len && strpos($skip, $src[$i]) !== false) {
        $i++;
    }
    // SQL — строковый литерал
    if ($i >= $len || ($src[$i] !== '"' && $src[$i] !== "'")) {
        return null;
    }
    $quote = $src[$i];
    $start = ++$i;
    while ($i < $len) {
        if ($src[$i] === '\\') { $i += 2; continue; }
        if ($src[$i] === $quote) { break; }
        $i++;
    }
    if ($i >= $len) {
        return null;
    }
    $sql = substr($src, $start, $i - $start);
    $i++; // закрывающая кавычка
    while ($i < $len && strpos($skip, $src[$i]) !== false) { $i++; }
    if ($i >= $len || $src[$i] !== ',') {
        return null;
    }
    $i++;
    while ($i < $len && strpos($skip, $src[$i]) !== false) { $i++; }
    // types — строковый литерал из [isdf]
    if ($i >= $len || ($src[$i] !== '"' && $src[$i] !== "'")) {
        return null;
    }
    $q2 = $src[$i];
    $s2 = ++$i;
    while ($i < $len && $src[$i] !== $q2) { $i++; }
    if ($i >= $len) {
        return null;
    }
    $types = substr($src, $s2, $i - $s2);
    $i++;
    while ($i < $len && strpos($skip, $src[$i]) !== false) { $i++; }
    if ($i >= $len || $src[$i] !== ',') {
        return null;
    }
    $i++;
    while ($i < $len && strpos($skip, $src[$i]) !== false) { $i++; }
    if ($i >= $len || $src[$i] !== '[') {
        // аргументы не литерал массива (например array_merge(...)) —
        // количество аргументов не проверим, но буквы типов и колонки — проверим
        $depth = 1;
        $inStr = false;
        for (; $i < $len; $i++) {
            $ch = $src[$i];
            if ($inStr) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === '"' || $ch === "'") { $inStr = false; }
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; continue; }
            if ($ch === '(') { $depth++; continue; }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) { return ['sql' => $sql, 'types' => $types, 'args' => null, 'end' => $i]; }
                continue;
            }
        }
        return null;
    }
    // массив аргументов — до парной ]
    $depth = 0;
    $aStart = $i + 1;
    $inStr = false;
    for (; $i < $len; $i++) {
        $ch = $src[$i];
        if ($inStr) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === "'" || $ch === '"') { $inStr = false; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = true; continue; }
        if ($ch === '[' || $ch === '(') { $depth++; continue; }
        if ($ch === ']' || $ch === ')') {
            $depth--;
            if ($depth === 0) { break; }
        }
    }
    if ($i >= $len) {
        return null;
    }
    $args = substr($src, $aStart, $i - $aStart);
    return ['sql' => $sql, 'types' => $types, 'args' => $args, 'end' => $i];
}

/** Число аргументов верхнего уровня в содержимом массива */
function count_top_level(string $args): int
{
    $args = trim($args);
    if ($args === '') {
        return 0;
    }
    $depth = 0;
    $count = 0;
    $inStr = false;
    $ternary = 0;          // открытых тернарных операторов ?:
    $len = strlen($args);
    for ($i = 0; $i < $len; $i++) {
        $ch = $args[$i];
        if ($inStr) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === "'" || $ch === '"') { $inStr = false; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = true; continue; }
        if ($ch === '(' || $ch === '[') { $depth++; continue; }
        if ($ch === ')' || $ch === ']') { $depth--; continue; }
        if ($ch === '?' && ($args[$i + 1] ?? '') !== '?' && ($args[$i - 1] ?? '') !== '?') {
            $ternary++;   // тернарный оператор ?:
            continue;
        }
        if ($ch === ':' && $ternary > 0) {
            $ternary--;
            continue;
        }
        if ($ch === ',' && $depth === 0 && $ternary === 0) { $count++; }
    }
    // висячая запятая в конце массива не считается элементом
    if (substr(rtrim($args), -1) === ',') {
        $count--;
    }
    return $count + 1;
}

/** Разделить по запятым верхнего уровня (скобки и кавычки учитываются). */
function split_top_level(string $s): array
{
    $out = [];
    $buf = '';
    $depth = 0;
    $inStr = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inStr) {
            if ($ch === "'" && ($i + 1 >= $len || $s[$i + 1] !== "'")) { $inStr = false; }
            elseif ($ch === "'" && $i + 1 < $len && $s[$i + 1] === "'") { $i++; }
            $buf .= $ch;
            continue;
        }
        if ($ch === "'") { $inStr = true; $buf .= $ch; continue; }
        if ($ch === '(') { $depth++; }
        if ($ch === ')') { $depth--; }
        if ($ch === ',' && $depth === 0) {
            $out[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}

/** Колонки, к которым идут плейсхолдеры, и таблица. */
function sql_target_columns(string $sql): ?array
{
    $s = preg_replace('/\s+/', ' ', trim($sql));
    if (preg_match('/^INSERT INTO\s+([A-Za-z0-9_]+)\s*\(([^)]*)\)/i', $s, $m)) {
        if (!preg_match('/VALUES\s*\((.*)\)\s*$/is', $s, $v)) {
            return null;
        }
        $cols = array_map('trim', explode(',', $m[2]));
        $out = [];
        foreach (split_top_level($v[1]) as $item) {
            // колонка сдвигается на каждое значение — и на плейсхолдер, и на литерал
            $col = array_shift($cols);
            if ($item === '?' && $col !== null) {
                $out[] = $col;
            }
        }
        return ['table' => $m[1], 'cols' => $out];
    }
    // SELECT ... WHERE col=? — типы плейсхолдеров тоже сверяем
    if (preg_match('/^SELECT\s+(.*?)\s+FROM\s+([A-Za-z0-9_]+)(.*)$/is', $s, $m)) {
        $rest = $m[3];
        $wherePart = $rest;
        if (preg_match('/\sWHERE\s+(.*)$/is', $rest, $w)) {
            $wherePart = $w[1];
        }
        // условия WHERE идут через AND — разбираем по ним
        $conds = preg_split('/\s+AND\s+/i', $wherePart) ?: [];
        $out = [];
        foreach ($conds as $pair) {
            $pair = trim($pair);
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*\?$/i', $pair, $p)) {
                $out[] = $p[1];
            }
        }
        if (!$out) {
            return null;
        }
        return ['table' => $m[2], 'cols' => $out];
    }
    if (preg_match('/^UPDATE\s+([A-Za-z0-9_]+)\s+SET\s+(.*)$/is', $s, $m)) {
        $setPart = $m[2];
        $wherePart = '';
        if (preg_match('/^(.*?)\s+WHERE\s+(.*)$/is', $setPart, $w)) {
            $setPart = $w[1];
            $wherePart = $w[2];
        }
        $out = [];
        foreach (split_top_level($setPart) as $pair) {
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*\?$/i', $pair, $p)) {
                $out[] = $p[1];
            }
        }
        foreach (split_top_level($wherePart) as $pair) {
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*\?$/i', $pair, $p)) {
                $out[] = $p[1];
            }
        }
        return ['table' => $m[1], 'cols' => $out];
    }
    return null;
}

/** Тип колонки в БД (с кэшем). */
function column_type(string $table, string $col): ?string
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $row = null;
    try {
        $db = db();
        $st = $db->prepare("SELECT DATA_TYPE FROM information_schema.columns
                             WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $res = $st->get_result();
        if ($res && $res->num_rows) {
            $r = $res->fetch_assoc();
            $row = strtolower((string)($r['DATA_TYPE'] ?? ''));
        }
    } catch (Throwable $e) {
        $row = null;
    }
    $cache[$key] = $row;
    return $row;
}

$bad = 0;
$checked = 0;
foreach ($files as $file) {
    if (basename($file) === 'check_queries.php') {
        continue;
    }
    $src = file_get_contents($file);
    $offset = 0;
    while (true) {
        // ищем вызовы q(), one(), all(), scalar() — у всех одинаковая сигнатура
        $found = false;
        foreach (['q(', 'one(', 'all(', 'scalar('] as $fn) {
            $p = strpos($src, $fn, $offset);
            if ($p !== false && (!$found || $p < $found)) {
                $found = $p;
                $fnLen = strlen($fn);
            }
        }
        if (!$found) {
            break;
        }
        $pos   = $found;
        $fnLen = $fnLen ?? 2;
        $offset = $pos + $fnLen;
        // пропускаем вызовы внутри идентификаторов (напр. mysql_query) — грубо: предыдущий символ не буква
        $prev = $pos > 0 ? $src[$pos - 1] : ' ';
        if (ctype_alpha($prev) || $prev === '_') {
            continue;
        }
        // '(' находится сразу за именем функции
        $call = parse_call($src, $pos + $fnLen - 1);
        if ($call === null) {
            $offset = $pos + 2;
            continue;
        }
        $offset = $call['end'] + 1;
        if (!preg_match('/^[isdf]+$/', $call['types'])) {
            continue;   // динамические типы — пропускаем
        }
        $sql = $call['types'] === '' ? $call['sql'] : $call['sql'];
        $clean = preg_replace("/'[^']*'/", "''", $sql);
        $n = substr_count((string)$clean, '?');
        $t = strlen($call['types']);
        $a = $call['args'] === null ? -1 : count_top_level($call['args']);
        $checked++;
        if ($n !== $t || ($a >= 0 && $n !== $a)) {
            $bad++;
            $line = substr_count(substr($src, 0, $pos), "\n") + 1;
            echo "MISMATCH " . basename($file) . ":{$line} placeholders={$n} types={$t} args={$a}\n";
            echo "   SQL: " . trim(preg_replace('/\s+/', ' ', $sql)) . "\n";
            continue;
        }
        // буква типа должна соответствовать типу колонки:
        // 'i'/'d' для числовых, 's' для строк. Иначе mysqli молча приводит значение.
        $target = sql_target_columns($sql);
        if ($target !== null && count($target['cols']) === $t) {
            foreach ($target['cols'] as $i => $col) {
                $ctype = column_type($target['table'], (string)$col);
                if ($ctype === null || $ctype === '') {
                    continue;
                }
                $letter = $call['types'][$i];
                $numeric = in_array($ctype, ['int', 'integer', 'bigint', 'smallint', 'tinyint',
                                            'mediumint', 'decimal', 'numeric', 'float', 'double',
                                            'real', 'year', 'bit'], true);
                $letterNum = in_array($letter, ['i', 'd'], true);
                if ($numeric && !$letterNum) {
                    $bad++;
                    $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                    echo "TYPE-MISMATCH " . basename($file) . ":{$line} колонка {$target['table']}.{$col} "
                       . "({$ctype}) получила букву '{$letter}'\n";
                    echo "   SQL: " . trim(preg_replace('/\s+/', ' ', $sql)) . "\n";
                } elseif (!$numeric && $letterNum) {
                    $bad++;
                    $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                    echo "TYPE-MISMATCH " . basename($file) . ":{$line} колонка {$target['table']}.{$col} "
                       . "({$ctype}) получила букву '{$letter}' — значение обрежется до числа\n";
                    echo "   SQL: " . trim(preg_replace('/\s+/', ' ', $sql)) . "\n";
                }
            }
        }
    }
}
echo $bad === 0
    ? "OK: {$checked} запросов согласованы\n"
    : "BAD: {$bad} расхождений из {$checked}\n";
exit($bad === 0 ? 0 : 1);
