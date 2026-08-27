-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
-- Re-run safe: DROP IF EXISTS included where useful.

-- Batch 020 — Workers

DROP TABLE IF EXISTS y_worker_skills;
DROP TABLE IF EXISTS y_unavailabilities;
DROP TABLE IF EXISTS y_workers;

CREATE TABLE IF NOT EXISTS y_workers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(191) NULL,
  weekly_hours_target DECIMAL(5,2) NULL,
  contract_type VARCHAR(32) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(32) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_y_workers_org_email (org_id, email),
  KEY ix_y_workers_org (org_id),
  KEY ix_y_workers_site (site_id),
  CONSTRAINT fk_y_workers_org FOREIGN KEY (org_id) REFERENCES y_orgs(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_workers_site FOREIGN KEY (site_id) REFERENCES y_sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_workers_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_workers_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_worker_skills (
  worker_id BIGINT UNSIGNED NOT NULL,
  skill_id  BIGINT UNSIGNED NOT NULL,
  level TINYINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (worker_id, skill_id),
  KEY ix_y_ws_skill (skill_id),
  CONSTRAINT fk_y_ws_worker     FOREIGN KEY (worker_id) REFERENCES y_workers(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_ws_skill      FOREIGN KEY (skill_id)  REFERENCES y_skills(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_ws_created_by FOREIGN KEY (created_by) REFERENCES y_users(id)  ON DELETE SET NULL,
  CONSTRAINT fk_y_ws_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_unavailabilities (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  worker_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reason VARCHAR(80) NOT NULL,
  recurrence_rule TEXT NULL,
  notes TEXT NULL,
  source VARCHAR(32) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_unav_worker_start (worker_id, starts_at),
  CONSTRAINT fk_y_unav_worker     FOREIGN KEY (worker_id)  REFERENCES y_workers(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_unav_created_by FOREIGN KEY (created_by) REFERENCES y_users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_y_unav_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
