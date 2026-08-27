-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
-- Re-run safe: DROP IF EXISTS included where useful.

-- Batch 010 — Referentials (skills, rulesets, reason_codes)

DROP TABLE IF EXISTS y_skills;
CREATE TABLE IF NOT EXISTS y_skills (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  label VARCHAR(160) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_y_skills_org_code (org_id, code),
  KEY ix_y_skills_org (org_id),
  CONSTRAINT fk_y_skills_org FOREIGN KEY (org_id) REFERENCES y_orgs(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_skills_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_skills_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS y_rulesets;
CREATE TABLE IF NOT EXISTS y_rulesets (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NULL,
  min_rest_hours_between_shifts DECIMAL(4,1) NULL,
  weekly_days_off TINYINT NULL,
  max_consecutive_days TINYINT NULL,
  weekly_hours_target_default DECIMAL(5,2) NULL,
  params_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from DATE NULL,
  effective_to DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_rulesets_org_site (org_id, site_id, is_active),
  CONSTRAINT fk_y_rulesets_org FOREIGN KEY (org_id) REFERENCES y_orgs(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_rulesets_site FOREIGN KEY (site_id) REFERENCES y_sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_rulesets_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_rulesets_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS y_reason_codes;
CREATE TABLE IF NOT EXISTS y_reason_codes (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NULL,
  internal_reason VARCHAR(80) NOT NULL,
  external_standard VARCHAR(40) NOT NULL,
  external_code VARCHAR(80) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_y_reason_map (org_id, internal_reason, external_standard),
  KEY ix_y_reason_org (org_id),
  CONSTRAINT fk_y_reason_org        FOREIGN KEY (org_id)     REFERENCES y_orgs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_reason_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_reason_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
