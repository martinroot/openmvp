<?php
declare(strict_types=1);
/**
 * Миграция глобального MLL: чат главного управляющего и пояснения по направлениям.
 */
require_once __DIR__ . '/../lib/core.php';

function gm_has_table(string $t): bool
{
    $r = one("SELECT COUNT(*) c FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name=?", 's', [$t]);
    return (int)($r['c'] ?? 0) > 0;
}

if (!gm_has_table('global_chats')) {
    q("CREATE TABLE IF NOT EXISTS global_chats (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         role        VARCHAR(16) NOT NULL DEFAULT 'user',
         text        MEDIUMTEXT NULL,
         model       VARCHAR(190) NOT NULL DEFAULT '',
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_created (created_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "global_chats: создана\n";
} else {
    echo "global_chats: есть\n";
}

$cols = all("SHOW COLUMNS FROM global_chats");
if (!in_array('payload', array_column($cols, 'Field'), true)) {
    q("ALTER TABLE global_chats ADD COLUMN payload MEDIUMTEXT NULL AFTER model");
    echo "global_chats.payload: добавлена\n";
}

if (!gm_has_table('global_notes')) {
    q("CREATE TABLE IF NOT EXISTS global_notes (
         project_id  INT UNSIGNED NOT NULL,
         text        MEDIUMTEXT NULL,
         updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (project_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "global_notes: создана\n";
} else {
    echo "global_notes: есть\n";
}

echo "OK: глобальный MLL готов\n";
