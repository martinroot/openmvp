<?php
declare(strict_types=1);
/**
 * Миграция раздела моделей: каталог OpenRouter, тесты, назначение по направлениям.
 * Идемпотентна: можно запускать повторно.
 */
require __DIR__ . '/../lib/core.php';

function mm_has_table(string $t): bool
{
    $r = one("SELECT COUNT(*) c FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name=?", 's', [$t]);
    return (int)($r['c'] ?? 0) > 0;
}

if (!mm_has_table('models')) {
    q("CREATE TABLE IF NOT EXISTS models (
         slug        VARCHAR(190) NOT NULL,
         name        VARCHAR(255) NOT NULL DEFAULT '',
         ctx         INT UNSIGNED NOT NULL DEFAULT 0,
         p_in        DOUBLE NOT NULL DEFAULT 0,
         p_out       DOUBLE NOT NULL DEFAULT 0,
         is_free     TINYINT(1) NOT NULL DEFAULT 0,
         modality    VARCHAR(64) NOT NULL DEFAULT '',
         created     INT UNSIGNED NOT NULL DEFAULT 0,
         fetched_at  DATETIME NOT NULL,
         PRIMARY KEY (slug),
         KEY idx_price (p_out),
         KEY idx_free (is_free)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "models: создана\n";
} else {
    echo "models: есть\n";
}

if (!mm_has_table('model_tests')) {
    q("CREATE TABLE IF NOT EXISTS model_tests (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         slug        VARCHAR(190) NOT NULL,
         prompt      MEDIUMTEXT NULL,
         ok          TINYINT(1) NOT NULL DEFAULT 0,
         latency_ms  INT UNSIGNED NOT NULL DEFAULT 0,
         tok_in      INT UNSIGNED NOT NULL DEFAULT 0,
         tok_out     INT UNSIGNED NOT NULL DEFAULT 0,
         cost_usd    DECIMAL(12,6) NOT NULL DEFAULT 0,
         response    MEDIUMTEXT NULL,
         error       TEXT NULL,
         score       TINYINT NULL,
         note        VARCHAR(255) NULL,
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         KEY idx_slug (slug),
         KEY idx_created (created_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "model_tests: создана\n";
} else {
    echo "model_tests: есть\n";
}

if (!mm_has_table('project_models')) {
    q("CREATE TABLE IF NOT EXISTS project_models (
         project_id  INT UNSIGNED NOT NULL,
         slug        VARCHAR(190) NOT NULL,
         updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (project_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "project_models: создана\n";
} else {
    echo "project_models: есть\n";
}

// --- добавленные колонки (идемпотентно) ---
function mm_has_column(string $t, string $c): bool
{
    $r = one("SELECT COUNT(*) c FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", 'ss', [$t, $c]);
    return (int)($r['c'] ?? 0) > 0;
}

if (mm_has_table('model_tests') && !mm_has_column('model_tests', 'kind')) {
    q("ALTER TABLE model_tests ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'mll'");
    echo "model_tests.kind: добавлена\n";
}
foreach (array('p_min_in' => 'DOUBLE NOT NULL DEFAULT 0',
                 'p_min_out' => 'DOUBLE NOT NULL DEFAULT 0',
                 'p_max_out' => 'DOUBLE NOT NULL DEFAULT 0',
                 'p_avg_out' => 'DOUBLE NOT NULL DEFAULT 0',
                 'providers'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
                 'p_best_provider' => "VARCHAR(64) NOT NULL DEFAULT ''",
                 'pop_score' => 'DOUBLE NOT NULL DEFAULT 0',
                 'pop_rank'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
                 'pop_requests' => 'BIGINT NOT NULL DEFAULT 0',
                 'pop_quality'  => 'DOUBLE NOT NULL DEFAULT 0',
                 'p_cache_read'  => 'DOUBLE NOT NULL DEFAULT 0',
                 'p_cache_write' => 'DOUBLE NOT NULL DEFAULT 0') as $col => $def) {
    if (mm_has_table('models') && !mm_has_column('models', $col)) {
        q("ALTER TABLE models ADD COLUMN $col $def");
        echo "models.$col: добавлена\n";
    }
}
if (mm_has_table('projects')) {
    foreach ([
        'plan_id'   => "INT UNSIGNED NULL",
        'goal'      => "TEXT NULL",
        'deadline'  => "VARCHAR(64) NOT NULL DEFAULT ''",
        'stage_goal' => "TEXT NULL",
        'result_note' => "MEDIUMTEXT NULL",
    ] as $col => $type) {
        if (!mm_has_column('projects', $col)) {
            q("ALTER TABLE projects ADD COLUMN $col $type");
            echo "projects.$col: добавлена\n";
        }
    }
}
if (mm_has_table('global_plans')) {
    echo "global_plans: есть\n";
} else {
    q("CREATE TABLE IF NOT EXISTS global_plans (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         day         DATE NOT NULL,
         project_id  INT UNSIGNED NOT NULL,
         goal        TEXT NULL,
         metric      VARCHAR(255) NOT NULL DEFAULT '',
         why         TEXT NULL,
         first_move  VARCHAR(500) NOT NULL DEFAULT '',
         prio        TINYINT NOT NULL DEFAULT 2,
         source      VARCHAR(64) NOT NULL DEFAULT 'global-mll',
         status      VARCHAR(16) NOT NULL DEFAULT 'active',
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (id),
         UNIQUE KEY uniq_day_project (day, project_id),
         KEY idx_day (day)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "global_plans: создана\n";
}
if (mm_has_table('projects')) {
    foreach ([
        'dispatch_window_from' => "VARCHAR(5) NOT NULL DEFAULT ''",
        'dispatch_window_to'   => "VARCHAR(5) NOT NULL DEFAULT ''",
        'dispatch_min_gap_min' => "INT NOT NULL DEFAULT 0",
        'dispatch_max_gap_min' => "INT NOT NULL DEFAULT 0",
        'dispatch_adaptive'    => "TINYINT NOT NULL DEFAULT 1",
        'dispatch_auto_next'   => "TINYINT NOT NULL DEFAULT 1",
        'dispatch_auto_next_delay_min' => "INT NOT NULL DEFAULT 0",
        'dispatch_batch_window' => "TINYINT NOT NULL DEFAULT 1",
    ] as $col => $type) {
        if (!mm_has_column('projects', $col)) {
            q("ALTER TABLE projects ADD COLUMN $col $type");
            echo "projects.$col: добавлена\n";
        }
    }
    if (!mm_has_column('projects', 'ready_at')) {
        q("ALTER TABLE projects ADD COLUMN ready_at DATETIME NULL");
        echo "projects.ready_at: добавлена\n";
    }
    if (!mm_has_column('projects', 'auto_chain')) {
        q("ALTER TABLE projects ADD COLUMN auto_chain VARCHAR(64) NOT NULL DEFAULT ''");
        echo "projects.auto_chain: добавлена\n";
    }
    if (!mm_has_column('projects', 'auto_step')) {
        q("ALTER TABLE projects ADD COLUMN auto_step INT NOT NULL DEFAULT 0");
        echo "projects.auto_step: добавлена\n";
    }
}
if (mm_has_table('projects') && !mm_has_column('projects', 'auto_roadmap')) {
    q("ALTER TABLE projects ADD COLUMN auto_roadmap TINYINT(1) NOT NULL DEFAULT 1 AFTER income_usd");
    echo "projects.auto_roadmap: добавлена (по умолчанию 1)\n";
}
if (mm_has_table('projects')) {
    $t = one("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='title'");
    if ($t && strtoupper((string)$t['DATA_TYPE']) === 'VARCHAR') {
        q("ALTER TABLE projects MODIFY title TEXT NULL");
        echo "projects.title: расширен до TEXT\n";
    }
}
if (mm_has_table('projects') && !mm_has_column('projects', 'task_desc')) {
    q("ALTER TABLE projects ADD COLUMN task_desc TEXT NULL");
    echo "projects.task_desc: добавлена\n";
}
if (mm_has_table('projects') && !mm_has_column('projects', 'income_usd')) {
    q("ALTER TABLE projects ADD COLUMN income_usd DECIMAL(12,4) NOT NULL DEFAULT 0");
    echo "projects.income_usd: добавлена\n";
}

if (!mm_has_table('chat_commands')) {
    q("CREATE TABLE IF NOT EXISTS chat_commands (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         project_id  INT UNSIGNED NOT NULL,
         cmd         TEXT NOT NULL,
         note        VARCHAR(255) NULL,
         created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         sent_at     DATETIME NULL,
         PRIMARY KEY (id),
         KEY idx_pending (sent_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "chat_commands: создана\n";
} else {
    echo "chat_commands: есть\n";
}

echo "OK: раздел моделей готов\n";
