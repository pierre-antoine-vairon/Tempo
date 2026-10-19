-- Tempo — Achats & matières V1
--
-- Catégories fournisseur, provenance légère des mercuriales et articles
-- fournisseur importables avant leur rapprochement avec une matière Tempo.
--
-- IMPORTANT : ce script est destiné à être relu puis appliqué manuellement
-- dans MySQL Workbench sur la base distante partagée. Ne pas l'exécuter
-- automatiquement.

CREATE TABLE y_supplier_categories (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,

    name VARCHAR(160) NOT NULL,
    source_path VARCHAR(500) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_supplier_categories_supplier_source_path (
        supplier_id,
        source_path
    ),

    KEY ix_y_supplier_categories_supplier_parent (
        supplier_id,
        parent_id
    ),

    KEY ix_y_supplier_categories_parent (
        parent_id
    ),

    KEY ix_y_supplier_categories_org_active (
        org_id,
        is_active
    ),

    CONSTRAINT chk_y_supplier_categories_is_active
        CHECK (is_active IN (0, 1)),

    CONSTRAINT fk_y_supplier_categories_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_supplier_categories_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES y_suppliers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_categories_parent
        FOREIGN KEY (parent_id)
        REFERENCES y_supplier_categories(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_categories_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_supplier_categories_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_supplier_catalogs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(160) NOT NULL,
    effective_from DATE NOT NULL,
    source_filename VARCHAR(255) NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_supplier_catalogs_supplier_name_effective (
        supplier_id,
        name,
        effective_from
    ),

    KEY ix_y_supplier_catalogs_org_supplier_effective (
        org_id,
        supplier_id,
        effective_from
    ),

    CONSTRAINT fk_y_supplier_catalogs_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_supplier_catalogs_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES y_suppliers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_catalogs_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_supplier_catalogs_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


ALTER TABLE y_supplier_items
    DROP CONSTRAINT chk_y_supplier_items_quantity_in_reference_unit,
    MODIFY COLUMN material_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN quantity_in_reference_unit DECIMAL(14,3) NULL,
    ADD COLUMN supplier_category_id BIGINT UNSIGNED NULL,
    ADD COLUMN brand VARCHAR(160) NULL,
    ADD KEY ix_y_supplier_items_supplier_category (
        supplier_category_id
    ),
    ADD CONSTRAINT chk_y_supplier_items_quantity_in_reference_unit
        CHECK (
            quantity_in_reference_unit IS NULL
            OR quantity_in_reference_unit > 0
        ),
    ADD CONSTRAINT chk_y_supplier_items_quantity_requires_material
        CHECK (
            material_id IS NOT NULL
            OR quantity_in_reference_unit IS NULL
        ),
    ADD CONSTRAINT fk_y_supplier_items_supplier_category
        FOREIGN KEY (supplier_category_id)
        REFERENCES y_supplier_categories(id)
        ON DELETE RESTRICT;


ALTER TABLE y_supplier_item_prices
    ADD COLUMN supplier_catalog_id BIGINT UNSIGNED NULL,
    ADD KEY ix_y_supplier_item_prices_supplier_catalog (
        supplier_catalog_id
    ),
    ADD CONSTRAINT fk_y_supplier_item_prices_supplier_catalog
        FOREIGN KEY (supplier_catalog_id)
        REFERENCES y_supplier_catalogs(id)
        ON DELETE RESTRICT;
