<?php
declare(strict_types=1);
/**
 * Миграция RoadMap-конструктора: чат и версии плана. Идемпотентна.
 */
require_once __DIR__ . '/../lib/core.php';

function rm_has_table(string $t): bool
{
    $r = one("SELECT COUNT(*) c FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name=?", 's', [$t]);
    return (int)($r['c'] ?? 0) > 0;
}

if (!rm_has_table('roadmap_chats')) {
    q("CREATE TABLE IF NOT EXISTS roadmap_chats (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         project_id  INT UNSIGNED NOT NULL,
         role        VARCHAR(16) NOT NULL DEFAULT 'user',
         text        MEDIUMTEXT NULL,
         model       VARCHAR(190) NOT NULL DEFAULT '',
         version_id  INT UNSIGNED NOT NULL DEFAULT 0,
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_proj (project_id, id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "roadmap_chats: создана\n";
} else {
    echo "roadmap_chats: есть\n";
}

if (!rm_has_table('roadmaps')) {
    q("CREATE TABLE IF NOT EXISTS roadmaps (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         project_id  INT UNSIGNED NOT NULL,
         version     INT UNSIGNED NOT NULL DEFAULT 1,
         title       VARCHAR(255) NOT NULL DEFAULT '',
         content     MEDIUMTEXT NULL,
         note        VARCHAR(255) NULL,
         status      VARCHAR(16) NOT NULL DEFAULT 'draft',
         model       VARCHAR(190) NOT NULL DEFAULT '',
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_proj (project_id, version),
         KEY idx_status (project_id, status)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "roadmaps: создана\n";
} else {
    echo "roadmaps: есть\n";
}

echo "OK: RoadMap готов\n";
