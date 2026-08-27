-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
--
-- Batch 061 — Production entries
--
-- IMPORTANT:
-- Ce fichier correspond à la table créée en SQL direct dans MySQL Workbench.
-- Il ne contient aucun DROP / DELETE / TRUNCATE et ne modifie aucune table existante.

CREATE TABLE IF NOT EXISTS y_production_entries (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

  org_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,

  production_date DATE NOT NULL,

  stock_previous INT UNSIGNED NULL,
  production INT UNSIGNED NULL,
  reproduction INT UNSIGNED NULL,
  losses INT UNSIGNED NULL,
  sales INT UNSIGNED NULL,
  stock_end INT UNSIGNED NULL,

  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY ux_y_production_entries_site_date_product
    (site_id, production_date, product_id),

  KEY ix_y_production_entries_org_date
    (org_id, production_date),

  KEY ix_y_production_entries_site_date
    (site_id, production_date),

  KEY ix_y_production_entries_product
    (product_id),

  CONSTRAINT fk_y_production_entries_org
    FOREIGN KEY (org_id)
    REFERENCES y_orgs(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_y_production_entries_site
    FOREIGN KEY (site_id)
    REFERENCES y_sites(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_y_production_entries_product
    FOREIGN KEY (product_id)
    REFERENCES y_products(id)
    ON DELETE RESTRICT,

  CONSTRAINT fk_y_production_entries_created_by
    FOREIGN KEY (created_by)
    REFERENCES y_users(id)
    ON DELETE SET NULL,

  CONSTRAINT fk_y_production_entries_updated_by
    FOREIGN KEY (updated_by)
    REFERENCES y_users(id)
    ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
