-- Tempo — Achats & matières V1
--
-- Fondation : unités techniques, fournisseurs, matières, articles fournisseur
-- et historique des prix d'achat.
--
-- IMPORTANT : ce script est destiné à être relu puis appliqué manuellement
-- dans MySQL Workbench sur la base distante partagée. Ne pas l'exécuter
-- automatiquement.

CREATE TABLE y_measure_units (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    code VARCHAR(32) NOT NULL,
    name VARCHAR(100) NOT NULL,
    symbol VARCHAR(16) NOT NULL,
    dimension VARCHAR(32) NOT NULL,
    to_base_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_measure_units_code (
        code
    ),

    CONSTRAINT chk_y_measure_units_to_base_factor
        CHECK (to_base_factor > 0),

    CONSTRAINT chk_y_measure_units_is_active
        CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_suppliers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_suppliers_org_name (
        org_id,
        name
    ),

    KEY ix_y_suppliers_org_active (
        org_id,
        is_active
    ),

    CONSTRAINT chk_y_suppliers_is_active
        CHECK (is_active IN (0, 1)),

    CONSTRAINT fk_y_suppliers_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_suppliers_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_suppliers_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_materials (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(160) NOT NULL,
    reference_measure_unit_id BIGINT UNSIGNED NOT NULL,
    reference_unit_label VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_materials_org_name (
        org_id,
        name
    ),

    KEY ix_y_materials_org_active (
        org_id,
        is_active
    ),

    KEY ix_y_materials_reference_measure_unit (
        reference_measure_unit_id
    ),

    CONSTRAINT chk_y_materials_is_active
        CHECK (is_active IN (0, 1)),

    CONSTRAINT fk_y_materials_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_materials_reference_measure_unit
        FOREIGN KEY (reference_measure_unit_id)
        REFERENCES y_measure_units(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_materials_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_materials_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_supplier_items (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,

    supplier_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(160) NOT NULL,
    supplier_sku VARCHAR(100) NULL,
    purchase_unit_label VARCHAR(50) NULL,
    pack_label VARCHAR(160) NULL,
    quantity_in_reference_unit DECIMAL(14,3) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_supplier_items_supplier_sku (
        supplier_id,
        supplier_sku
    ),

    KEY ix_y_supplier_items_supplier (
        supplier_id
    ),

    KEY ix_y_supplier_items_org_active (
        org_id,
        is_active
    ),

    KEY ix_y_supplier_items_material (
        material_id
    ),

    CONSTRAINT chk_y_supplier_items_quantity_in_reference_unit
        CHECK (quantity_in_reference_unit > 0),

    CONSTRAINT chk_y_supplier_items_is_active
        CHECK (is_active IN (0, 1)),

    CONSTRAINT fk_y_supplier_items_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_supplier_items_supplier
        FOREIGN KEY (supplier_id)
        REFERENCES y_suppliers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_items_material
        FOREIGN KEY (material_id)
        REFERENCES y_materials(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_items_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_supplier_items_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_supplier_item_prices (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    supplier_item_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NULL,

    price_excl_tax DECIMAL(12,4) NOT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'EUR',
    effective_from DATE NOT NULL,

    -- NULL denotes the organisation-wide price. The generated value 0 is a
    -- sentinel because y_sites identifiers are positive AUTO_INCREMENT values.
    price_scope_site_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (COALESCE(site_id, 0)) STORED,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_supplier_item_prices_item_scope_effective (
        supplier_item_id,
        price_scope_site_id,
        effective_from
    ),

    KEY ix_y_supplier_item_prices_item_site_effective (
        supplier_item_id,
        site_id,
        effective_from
    ),

    KEY ix_y_supplier_item_prices_org_site_effective (
        org_id,
        site_id,
        effective_from
    ),

    KEY ix_y_supplier_item_prices_site (
        site_id
    ),

    CONSTRAINT chk_y_supplier_item_prices_price_excl_tax
        CHECK (price_excl_tax >= 0),

    CONSTRAINT fk_y_supplier_item_prices_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_supplier_item_prices_supplier_item
        FOREIGN KEY (supplier_item_id)
        REFERENCES y_supplier_items(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_item_prices_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_supplier_item_prices_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_supplier_item_prices_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
