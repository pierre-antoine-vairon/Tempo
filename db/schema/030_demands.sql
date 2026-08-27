-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
-- Re-run safe: DROP IF EXISTS included where useful.

-- Batch 030 — Demands

DROP TABLE IF EXISTS y_demand_skill_requirements;
DROP TABLE IF EXISTS y_demands;

CREATE TABLE IF NOT EXISTS y_demands (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  recurrence_rule TEXT NULL,
  valid_from DATE NULL,
  valid_to DATE NULL,
  required_count SMALLINT UNSIGNED NOT NULL,
  notes TEXT NULL,
  source VARCHAR(32) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_demands_org_site (org_id, site_id),
  KEY ix_y_demands_site_start (site_id, starts_at),
  CONSTRAINT fk_y_demands_org        FOREIGN KEY (org_id)     REFERENCES y_orgs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_demands_site       FOREIGN KEY (site_id)    REFERENCES y_sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_demands_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_demands_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_demand_skill_requirements (
  demand_id BIGINT UNSIGNED NOT NULL,
  skill_id  BIGINT UNSIGNED NOT NULL,
  min_count SMALLINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (demand_id, skill_id),
  KEY ix_y_dsr_skill (skill_id),
  CONSTRAINT fk_y_dsr_demand     FOREIGN KEY (demand_id) REFERENCES y_demands(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_dsr_skill      FOREIGN KEY (skill_id)  REFERENCES y_skills(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_dsr_created_by FOREIGN KEY (created_by) REFERENCES y_users(id)  ON DELETE SET NULL,
  CONSTRAINT fk_y_dsr_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
