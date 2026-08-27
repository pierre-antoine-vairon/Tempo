-- Tempo / AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
--
-- Batch 060 — Production / Product catalog
--
-- IMPORTANT — shared database safety:
--   - no DROP TABLE
--   - no DELETE
--   - no TRUNCATE
--   - no ALTER on existing tables
-- This file only creates y_products if it does not already exist.
--
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS y_products (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    conservation VARCHAR(16) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_products_site_name (site_id, name),

    KEY ix_y_products_org (org_id),
    KEY ix_y_products_site (site_id),
    KEY ix_y_products_active (site_id, is_active),

    CONSTRAINT fk_y_products_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_products_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_products_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_products_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Verification only:
-- DESCRIBE y_products;
-- SHOW CREATE TABLE y_products;
