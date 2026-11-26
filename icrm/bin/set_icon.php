<?php
declare(strict_types=1);
/**
 * Правка иконки/полей направления без потери юникода (через CLI, а не через шелл).
 * Запуск: php bin/set_icon.php <id проекта> <иконка>
 */
require __DIR__ . '/../lib/core.php';

$id = (int)($argv[1] ?? 0);
$icon = $argv[2] ?? '';
if ($id <= 0 || $icon === '') {
    echo "usage: php bin/set_icon.php <id> <иконка>\n";
    exit(1);
}
$n = node($id);
if (!$n) {
    echo "узел $id не найден\n";
    exit(1);
}
q("UPDATE projects SET icon=? WHERE id=?", 'si', [$icon, $id]);
echo "узел $id: иконка = " . $icon . " (поле icon в трекере: " .
    (string)node($id)['icon'] . ")\n";
