-- ============================================================================
-- Project Time Tracker — database schema (MariaDB 10.6+ / MySQL 8.0+)
--
-- This file is idempotent: every statement can be run again safely.
-- It is applied automatically when the web container starts
-- (see bin/init-db.php), so you normally never have to run it by hand.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- Projects
--   * total_seconds is the single source of truth for recorded time. Time is
--     always ADDED atomically (total_seconds = total_seconds + n), so two
--     engineers can never overwrite each other's hours.
--   * Dates are real DATE columns; the API formats them as DD/MM/YYYY, exactly
--     like the previous version did.
--   * last_update_time is NULL when the legacy record only had a date.
--   * sort_order mirrors the old "order" field (NULL = never reordered; such
--     projects are sorted by id, exactly like before).
--   * deleted_at implements soft delete: a deleted project disappears from the
--     application but can still be restored by an administrator.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    title            VARCHAR(500)     NOT NULL,
    institute        VARCHAR(255)     NOT NULL DEFAULT '',
    category         VARCHAR(100)     NOT NULL DEFAULT '',
    status           ENUM('active','finished') NOT NULL DEFAULT 'active',
    submission_date  DATE             NULL,
    start_date       DATE             NULL,
    end_date         DATE             NULL,
    total_seconds    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    last_update_date DATE             NULL,
    last_update_time TIME             NULL,
    sort_order       INT              NULL,
    created_at       DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at       DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    deleted_at       DATETIME(3)      NULL,
    PRIMARY KEY (id),
    KEY idx_projects_deleted_order (deleted_at, sort_order),
    KEY idx_projects_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Researchers and engineers of a project, in the order they were entered.
-- Names are stored exactly as typed (spelling preserved).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_members (
    project_id INT UNSIGNED                   NOT NULL,
    role       ENUM('researcher','engineer')  NOT NULL,
    position   SMALLINT UNSIGNED              NOT NULL,
    name       VARCHAR(255)                   NOT NULL,
    PRIMARY KEY (project_id, role, position),
    KEY idx_members_name (name),
    CONSTRAINT fk_members_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- History of every change (time added, manual corrections, edits, status
-- changes, deletions, imports and the initial JSON migration, which keeps a
-- verbatim copy of each legacy record).
-- No foreign key on purpose: the history must survive anything.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    op_id               VARCHAR(64)     NULL,
    action              VARCHAR(32)     NOT NULL,
    project_id          INT UNSIGNED    NULL,
    seconds_delta       BIGINT          NULL,
    total_seconds_after BIGINT UNSIGNED NULL,
    details             JSON            NULL,
    client_ip           VARCHAR(45)     NULL,
    created_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_activity_project (project_id, created_at),
    KEY idx_activity_op (op_id),
    KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Idempotency keys. Every change sent by the browser carries a unique id; a
-- change that is re-sent (network retry, restore after an expired session,
-- two open tabs) is recognised and applied only once.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS processed_operations (
    op_id      VARCHAR(64) NOT NULL,
    action     VARCHAR(32) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (op_id),
    KEY idx_processed_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Login sessions, stored server-side so they survive container restarts.
-- Only an HMAC of the session token is stored (a database dump cannot be used
-- to hijack a session).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    token_hash CHAR(64)    NOT NULL,
    created_at DATETIME    NOT NULL,
    expires_at DATETIME    NOT NULL,
    PRIMARY KEY (token_hash),
    KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- ----------------------------------------------------------------------------
-- Schema metadata (used to apply future schema upgrades safely).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_meta (
    meta_key   VARCHAR(64)  NOT NULL,
    meta_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_meta (meta_key, meta_value) VALUES ('schema_version', '1');
