-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
-- Re-run safe: DROP IF EXISTS included where useful.

-- Batch 000 — Base (orgs, users, sites)

-- orgs
DROP TABLE IF EXISTS y_orgs;
CREATE TABLE IF NOT EXISTS y_orgs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
  country_code CHAR(2) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_orgs_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users
DROP TABLE IF EXISTS y_users;
CREATE TABLE IF NOT EXISTS y_users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','employee') NOT NULL DEFAULT 'manager',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_y_users_org_email (org_id, email),
  KEY ix_y_users_org (org_id),
  CONSTRAINT fk_y_users_org        FOREIGN KEY (org_id)     REFERENCES y_orgs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_users_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_users_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sites
DROP TABLE IF EXISTS y_sites;
CREATE TABLE IF NOT EXISTS y_sites (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(64) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_sites_org_name (org_id, name),
  CONSTRAINT fk_y_sites_org FOREIGN KEY (org_id) REFERENCES y_orgs(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_sites_created_by FOREIGN KEY (created_by) REFERENCES y_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_y_sites_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
