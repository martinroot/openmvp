<?php
declare(strict_types=1);
/**
 * Перенос критериев готовности из плана watchdog в трекер.
 * Запуск: php bin/migrate_criteria.php [путь к БД watchdog]
 */
require __DIR__ . '/../lib/core.php';

$wd = $argv[1] ?? '/opt/wintask_watchdog/direction_watchdog.sqlite3';

// 1. колонка критериев в трекере
$cols = array_column(all("SHOW COLUMNS FROM projects"), 'Field');
if (!in_array('criteria', $cols, true)) {
    q("ALTER TABLE projects ADD COLUMN criteria TEXT NULL AFTER summary");
    echo "добавлена колонка projects.criteria\n";
} else {
    echo "колонка projects.criteria уже есть\n";
}

// 2. перенос из watchdog (если он ещё на месте)
if (!is_readable($wd)) {
    echo "БД watchdog недоступна: $wd\n";
    exit(0);
}
$pdo = new PDO('sqlite:' . $wd);
$rows = $pdo->query("SELECT id, title, criteria FROM tasks")->fetchAll(PDO::FETCH_ASSOC);
$n = 0;
foreach ($rows as $r) {
    $crit = json_decode((string)$r['criteria'], true);
    if (!is_array($crit) || !$crit) {
        continue;
    }
    $text = implode("\n", $crit);
    $t = one("SELECT id, criteria FROM projects WHERE id=?", 'i', [(int)$r['id']]);
    if (!$t) {
        echo "в трекере нет задачи {$r['id']} — пропуск\n";
        continue;
    }
    if (trim((string)$t['criteria']) !== '') {
        continue;  // в трекере уже есть свои критерии
    }
    q("UPDATE projects SET criteria=? WHERE id=?", 'si', [$text, (int)$r['id']]);
    echo "задача {$r['id']}: критерии перенесены (" . count($crit) . ")\n";
    $n++;
}
echo "перенесено задач: $n\n";
