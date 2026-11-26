<?php
declare(strict_types=1);
/**
 * Миграция учёта вызовов: llm_calls + task_id в agent_runs + calls_count в projects.
 * Идемпотентна: можно запускать повторно.
 */
require __DIR__ . '/../lib/core.php';

function has_col(string $table, string $col): bool
{
    $r = one("SELECT COUNT(*) c FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name=? AND column_name=?",
             'ss', [$table, $col]);
    return (int)($r['c'] ?? 0) > 0;
}

function has_table(string $t): bool
{
    $r = one("SELECT COUNT(*) c FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name=?", 's', [$t]);
    return (int)($r['c'] ?? 0) > 0;
}

// 1. таблица вызовов
if (!has_table('llm_calls')) {
    q("CREATE TABLE IF NOT EXISTS llm_calls (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         project_id  INT UNSIGNED NULL,
         task_id     INT UNSIGNED NULL,
         agent_code  VARCHAR(64) NOT NULL,
         server      VARCHAR(64) NULL,
         model       VARCHAR(128) NULL,
         turn_id     VARCHAR(64) NULL,
         call_no     INT UNSIGNED NOT NULL DEFAULT 0,
         ts          DATETIME NOT NULL,
         tok_in      BIGINT UNSIGNED NOT NULL DEFAULT 0,
         tok_cache   BIGINT UNSIGNED NOT NULL DEFAULT 0,
         tok_out     BIGINT UNSIGNED NOT NULL DEFAULT 0,
         tok_think   BIGINT UNSIGNED NOT NULL DEFAULT 0,
         cost_usd    DECIMAL(12,6) NOT NULL DEFAULT 0,
         duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
         error       TEXT NULL,
         PRIMARY KEY (id),
         KEY idx_task (task_id),
         KEY idx_proj_ts (project_id, ts),
         KEY idx_turn (turn_id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "создана таблица llm_calls\n";
} else {
    echo "таблица llm_calls уже есть\n";
}

// 2. task_id в agent_runs
if (!has_col('agent_runs', 'task_id')) {
    q("ALTER TABLE agent_runs ADD COLUMN task_id INT UNSIGNED NULL AFTER project_id");
    echo "добавлена колонка agent_runs.task_id\n";
} else {
    echo "agent_runs.task_id уже есть\n";
}

// 3. calls_count в projects
if (!has_col('projects', 'calls_count')) {
    q("ALTER TABLE projects ADD COLUMN calls_count INT UNSIGNED NOT NULL DEFAULT 0");
    echo "добавлена колонка projects.calls_count\n";
} else {
    echo "projects.calls_count уже есть\n";
}

// 4. таблица вызовов MLL
if (!has_table('mll_runs')) {
    q("CREATE TABLE IF NOT EXISTS mll_runs (
         id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
         project_id  INT UNSIGNED NULL,
         model       VARCHAR(128) NULL,
         ts          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         tok_in      BIGINT UNSIGNED NOT NULL DEFAULT 0,
         tok_out     BIGINT UNSIGNED NOT NULL DEFAULT 0,
         cost_usd    DECIMAL(12,6) NOT NULL DEFAULT 0,
         steps       INT UNSIGNED NOT NULL DEFAULT 0,
         PRIMARY KEY (id),
         KEY idx_proj (project_id, ts)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "создана таблица mll_runs\n";
} else {
    echo "mll_runs уже есть\n";
}

// 5. task_id в events — чтобы лог собирался по задаче
if (!has_col('events', 'task_id')) {
    q("ALTER TABLE events ADD COLUMN task_id INT UNSIGNED NULL AFTER project_id");
    echo "добавлена колонка events.task_id\n";
} else {
    echo "events.task_id уже есть\n";
}
$idx = one("SELECT COUNT(*) c FROM information_schema.statistics
            WHERE table_schema=DATABASE() AND table_name='events' AND index_name='idx_task'");
if ((int)($idx['c'] ?? 0) === 0) {
    q("ALTER TABLE events ADD KEY idx_task (task_id)");
    echo "добавлен индекс events.idx_task\n";
}

// 5. ключ ingest на месте
$k = one("SELECT v FROM settings WHERE k='ingest_key'");
if (!$k || trim((string)$k['v']) === '') {
    q("INSERT INTO settings (k, v) VALUES ('ingest_key', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
      's', ['K' . bin2hex(random_bytes(16))]);
    echo "создан ingest_key\n";
} else {
    echo "ingest_key на месте\n";
}

echo "миграция учёта вызовов завершена\n";
