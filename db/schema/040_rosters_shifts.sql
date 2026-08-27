-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
-- Re-run safe: DROP IF EXISTS included where useful.

-- Batch 040 — Rosters & Shifts

DROP TABLE IF EXISTS y_shift_skill_requirements;
DROP TABLE IF EXISTS y_shifts;
DROP TABLE IF EXISTS y_rosters;

CREATE TABLE IF NOT EXISTS y_rosters (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('draft','proposed','under_review','approved','published','locked') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_rosters_org_site_period (org_id, site_id, period_start),
  CONSTRAINT fk_y_rosters_org        FOREIGN KEY (org_id)     REFERENCES y_orgs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_rosters_site       FOREIGN KEY (site_id)    REFERENCES y_sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_rosters_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_rosters_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_shifts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  roster_id BIGINT UNSIGNED NOT NULL,
  demand_id BIGINT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  required_count SMALLINT UNSIGNED NOT NULL,
  status ENUM('draft','proposed','published','locked') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_shifts_site_start (site_id, starts_at),
  KEY ix_y_shifts_org_start (org_id, starts_at),
  KEY ix_y_shifts_roster (roster_id),
  CONSTRAINT fk_y_shifts_org        FOREIGN KEY (org_id)     REFERENCES y_orgs(id)     ON DELETE CASCADE,
  CONSTRAINT fk_y_shifts_site       FOREIGN KEY (site_id)    REFERENCES y_sites(id)    ON DELETE CASCADE,
  CONSTRAINT fk_y_shifts_roster     FOREIGN KEY (roster_id)  REFERENCES y_rosters(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_shifts_demand     FOREIGN KEY (demand_id)  REFERENCES y_demands(id)  ON DELETE SET NULL,
  CONSTRAINT fk_y_shifts_created_by FOREIGN KEY (created_by) REFERENCES y_users(id)    ON DELETE SET NULL,
  CONSTRAINT fk_y_shifts_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_shift_skill_requirements (
  shift_id  BIGINT UNSIGNED NOT NULL,
  skill_id  BIGINT UNSIGNED NOT NULL,
  min_count SMALLINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shift_id, skill_id),
  KEY ix_y_ssr_skill (skill_id),
  CONSTRAINT fk_y_ssr_shift      FOREIGN KEY (shift_id)  REFERENCES y_shifts(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_ssr_skill      FOREIGN KEY (skill_id)  REFERENCES y_skills(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_ssr_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_ssr_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
