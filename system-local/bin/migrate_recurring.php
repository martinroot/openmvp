<?php
declare(strict_types=1);
/**
 * Миграция периодических задач: таблица recurring.
 * Идемпотентна: можно запускать повторно.
 */
require __DIR__ . '/../lib/core.php';

$r = one("SELECT COUNT(*) c FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name='recurring'");
if ((int)($r['c'] ?? 0) > 0) {
    echo "recurring: есть\n";
} else {
    q("CREATE TABLE IF NOT EXISTS recurring (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         project_id  INT UNSIGNED NOT NULL,
         every_min   INT UNSIGNED NOT NULL DEFAULT 60,
         title       VARCHAR(255) NOT NULL,
         last_sent   DATETIME NULL,
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_project (project_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "recurring: создана\n";
}
echo "OK: периодические задачи готовы\n";
