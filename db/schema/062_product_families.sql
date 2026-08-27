CREATE TABLE IF NOT EXISTS y_product_families (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(100) NOT NULL,

    display_order INT UNSIGNED NOT NULL DEFAULT 1000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_product_families_site_name (
        site_id,
        name
    ),

    KEY ix_y_product_families_org (
        org_id
    ),

    KEY ix_y_product_families_site (
        site_id
    ),

    KEY ix_y_product_families_active (
        site_id,
        is_active
    ),

    KEY ix_y_product_families_order (
        site_id,
        display_order
    ),

    CONSTRAINT fk_y_product_families_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_families_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_families_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_product_families_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- Récupération automatique des familles déjà présentes
-- dans y_products : Salé, Sucré, etc.

INSERT IGNORE INTO y_product_families (
    org_id,
    site_id,
    name,
    display_order,
    is_active
)
SELECT
    org_id,
    site_id,
    TRIM(family),
    1000,
    1
FROM y_products
WHERE family IS NOT NULL
  AND TRIM(family) <> ''
GROUP BY
    org_id,
    site_id,
    TRIM(family);

/*Après exécution, tu devrais retrouver notamment : Salé Sucré*/