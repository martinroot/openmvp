<?php
declare(strict_types=1);
/**
 * Разовая правка: иконка направления FABRA.
 * Эмодзи пишем прямо в файле — шелл их портит.
 */
require __DIR__ . '/../lib/core.php';

$icon = '🏭';
$id = 29;
$n = node($id);
if (!$n) {
    echo "узла $id нет\n";
    exit(1);
}
q("UPDATE projects SET icon=? WHERE id=?", 'si', [$icon, $id]);
$check = node($id);
echo "узел $id: иконка = " . $check['icon'] . " (hex " . bin2hex((string)$check['icon']) . ")\n";
