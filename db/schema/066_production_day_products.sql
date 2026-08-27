CREATE TABLE IF NOT EXISTS y_production_day_products (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    production_day_id BIGINT UNSIGNED NOT NULL,

    product_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    family_id BIGINT UNSIGNED NOT NULL,

    product_name_snapshot VARCHAR(160) NOT NULL,
    category_name_snapshot VARCHAR(100) NOT NULL,
    family_name_snapshot VARCHAR(100) NOT NULL,

    conservation_snapshot VARCHAR(16) NULL,

    product_order_snapshot INT UNSIGNED NOT NULL DEFAULT 1000,
    category_order_snapshot INT UNSIGNED NOT NULL DEFAULT 1000,
    family_order_snapshot INT UNSIGNED NOT NULL DEFAULT 1000,

    is_included TINYINT(1) NOT NULL DEFAULT 1,

    excluded_at TIMESTAMP NULL,
    excluded_by BIGINT UNSIGNED NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_production_day_products_day_product (
        production_day_id,
        product_id
    ),

    KEY ix_y_production_day_products_org (
        org_id
    ),

    KEY ix_y_production_day_products_site (
        site_id
    ),

    KEY ix_y_production_day_products_day (
        production_day_id
    ),

    KEY ix_y_production_day_products_product (
        product_id
    ),

    KEY ix_y_production_day_products_category (
        category_id
    ),

    KEY ix_y_production_day_products_family (
        family_id
    ),

    KEY ix_y_production_day_products_included (
        production_day_id,
        is_included
    ),

    CONSTRAINT fk_y_production_day_products_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_day_products_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_day_products_day
        FOREIGN KEY (production_day_id)
        REFERENCES y_production_days(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_day_products_product
        FOREIGN KEY (product_id)
        REFERENCES y_products(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_production_day_products_category
        FOREIGN KEY (category_id)
        REFERENCES y_product_categories(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_production_day_products_family
        FOREIGN KEY (family_id)
        REFERENCES y_product_families(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_production_day_products_excluded_by
        FOREIGN KEY (excluded_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_production_day_products_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_production_day_products_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;