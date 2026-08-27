CREATE TABLE IF NOT EXISTS y_product_categories (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    family_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(100) NOT NULL,

    display_order INT UNSIGNED NOT NULL DEFAULT 1000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_product_categories_family_name (
        family_id,
        name
    ),

    KEY ix_y_product_categories_org (
        org_id
    ),

    KEY ix_y_product_categories_site (
        site_id
    ),

    KEY ix_y_product_categories_family (
        family_id
    ),

    KEY ix_y_product_categories_active (
        site_id,
        is_active
    ),

    KEY ix_y_product_categories_order (
        family_id,
        display_order
    ),

    CONSTRAINT fk_y_product_categories_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_categories_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_categories_family
        FOREIGN KEY (family_id)
        REFERENCES y_product_families(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_product_categories_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_product_categories_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- Transformation automatique des anciennes catégories texte
-- en vraies catégories reliées à leur famille.

INSERT IGNORE INTO y_product_categories (
    org_id,
    site_id,
    family_id,
    name,
    display_order,
    is_active
)
SELECT
    p.org_id,
    p.site_id,
    f.id,
    TRIM(p.category),
    1000,
    1
FROM y_products p

INNER JOIN y_product_families f
    ON f.org_id = p.org_id
   AND f.site_id = p.site_id
   AND f.name = TRIM(p.family)

WHERE p.category IS NOT NULL
  AND TRIM(p.category) <> ''

GROUP BY
    p.org_id,
    p.site_id,
    f.id,
    TRIM(p.category);