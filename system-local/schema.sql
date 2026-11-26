SET NAMES utf8mb4;

-- ============================================================
--  SYSTEM — трекер проектов maximhot
--  Дерево: проекты -> подпроекты -> задачи -> подзадачи (до 4 уровней)
-- ============================================================

CREATE TABLE IF NOT EXISTS projects (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id     INT UNSIGNED NULL,              -- NULL = корень; иначе projects.id
  kind          ENUM('project','subproject','task','subtask') NOT NULL DEFAULT 'task',
  title         VARCHAR(255) NOT NULL,
  icon          VARCHAR(16)  NOT NULL DEFAULT '📁',   -- эмодзи-иконка
  agent_code    VARCHAR(64)  NULL,              -- код агента (напр. wifepornhub, edge-147)
  agent_chat_id BIGINT NULL,                    -- TG chat_id агента (может быть отрицательным)
  agent_name    VARCHAR(128) NULL,              -- @username бота агента
  server_ip     VARCHAR(64)  NULL,
  github_url    VARCHAR(255) NULL,              -- ссылка на GitHub-репозитарий проекта
  model         VARCHAR(128) NULL,              -- модель агента
  status        ENUM('planned','active','blocked','review','done','cancelled')
                NOT NULL DEFAULT 'planned',
  priority      TINYINT NOT NULL DEFAULT 0,     -- 0..3
  goal_amount   DECIMAL(14,2) NULL,             -- финансовая цель узла
  goal_date     DATE NULL,
  cost_total    DECIMAL(12,4) NOT NULL DEFAULT 0,
  tok_in        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tok_cache     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tok_out       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  runs_count    INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_run_at   DATETIME NULL,
  opened_at     DATETIME NULL,
  closed_at     DATETIME NULL,
  summary       TEXT NULL,
  pos           INT NOT NULL DEFAULT 0,         -- порядок среди сиблингов
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_parent (parent_id),
  KEY idx_status (status),
  KEY idx_kind (kind),
  CONSTRAINT fk_p FOREIGN KEY (parent_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог событий по узлу (что делал агент) — приклеивается к задаче при закрытии
CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NULL,
  task_id     INT UNSIGNED NULL,               -- задача, к которой относится сообщение
  kind        ENUM('agent','chat','system','metric','file','note') NOT NULL DEFAULT 'agent',
  ts          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  server      VARCHAR(64) NULL,
  level       ENUM('info','ok','warn','error') NOT NULL DEFAULT 'info',
  title       VARCHAR(255) NULL,
  text        MEDIUMTEXT NULL,
  meta        JSON NULL,
  chat_msg_id INT UNSIGNED NULL,               -- id сообщения из chat.php
  PRIMARY KEY (id),
  KEY idx_project (project_id, ts),
  KEY idx_ts (ts),
  CONSTRAINT fk_e FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Метрики запусков агента (in / cache / out, модель, стоимость, время)
CREATE TABLE IF NOT EXISTS agent_runs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NULL,
  task_id      INT UNSIGNED NULL,
  agent_code   VARCHAR(64) NOT NULL,
  server       VARCHAR(64) NULL,
  model        VARCHAR(128) NULL,
  ts           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tok_in       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tok_cache    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tok_out      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_usd     DECIMAL(12,6) NOT NULL DEFAULT 0,
  duration_ms  INT UNSIGNED NOT NULL DEFAULT 0,
  job_id       VARCHAR(64) NULL,
  error        TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_proj (project_id),
  KEY idx_agent_ts (agent_code, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вызовы MLL-советчика: по каждому запуску и по направлению
CREATE TABLE IF NOT EXISTS mll_runs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Учёт вызовов моделей: по каждому вызову и по каждой задаче
CREATE TABLE IF NOT EXISTS llm_calls (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Глобальные цели: финансовая и дневная
CREATE TABLE IF NOT EXISTS goals (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       ENUM('global','day','week','month') NOT NULL DEFAULT 'global',
  period_key  VARCHAR(16) NOT NULL,            -- 'global' | '2026-10-21'
  title       VARCHAR(255) NOT NULL,
  target_usd  DECIMAL(14,2) NOT NULL DEFAULT 0,
  note        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scope (scope, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Настройки
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Задачи можно заводить вручную из UI; здесь очередь на отправку агентам
CREATE TABLE IF NOT EXISTS dispatch_queue (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NULL,
  agent_code  VARCHAR(64) NULL,
  payload     TEXT NOT NULL,
  status      ENUM('new','sent','failed') NOT NULL DEFAULT 'new',
  err         TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Раздел моделей OpenRouter: каталог, тесты, оценки, назначение по направлениям
CREATE TABLE IF NOT EXISTS models (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_tests (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_models (
  project_id  INT UNSIGNED NOT NULL,
  slug        VARCHAR(190) NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Периодические задачи направления: сторож отдаёт их агенту по расписанию
CREATE TABLE IF NOT EXISTS recurring (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NOT NULL,
  every_min   INT UNSIGNED NOT NULL DEFAULT 60,
  title       VARCHAR(255) NOT NULL,
  last_sent   DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
