-- Tempo — Fiches techniques produits Production
--
-- Une recette représente la composition nécessaire pour fabriquer UNE unité
-- de y_products. Ses lignes référencent exclusivement y_materials : elles ne
-- doivent jamais dépendre directement d'un fournisseur, d'un article
-- fournisseur ou d'un prix fournisseur.
--
-- IMPORTANT : ce script est destiné à être relu puis appliqué manuellement
-- dans MySQL Workbench sur la base distante partagée. Ne pas l'exécuter
-- automatiquement.
--
-- Les recettes sont versionnées par effective_from. La résolution future est :
--
--   dernière recette
--   WHERE product_id = ?
--     AND effective_from <= date_de_production
--   ORDER BY effective_from DESC
--   LIMIT 1
--
-- Le SQL garantit uniquement l'unicité (product_id, effective_from).
-- L'application devra imposer un fonctionnement append-only : première date
-- libre, versions suivantes strictement postérieures à la dernière version et
-- aucune insertion rétroactive depuis l'interface. Un changement durable crée
-- une version complète.
--
-- Un produit peut exister sans recette : cela signifie « consommation
-- théorique inconnue », jamais consommation zéro.
--
-- L'unité n'est pas stockée sur une ligne de recette : elle vient de
-- y_materials.reference_measure_unit_id. Les cohérences transverses
-- org/site/product/material seront vérifiées par le backend.

CREATE TABLE y_product_recipes (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,

    effective_from DATE NOT NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_product_recipes_product_effective (
        product_id,
        effective_from
    ),

    KEY ix_y_product_recipes_org_site_effective (
        org_id,
        site_id,
        effective_from
    ),

    KEY ix_y_product_recipes_site (
        site_id
    ),

    CONSTRAINT fk_y_product_recipes_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipes_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipes_product
        FOREIGN KEY (product_id)
        REFERENCES y_products(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_product_recipes_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_product_recipes_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_product_recipe_lines (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    recipe_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,

    quantity_in_reference_unit DECIMAL(14,6) NOT NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_product_recipe_lines_recipe_material (
        recipe_id,
        material_id
    ),

    KEY ix_y_product_recipe_lines_material (
        material_id
    ),

    KEY ix_y_product_recipe_lines_org_site (
        org_id,
        site_id
    ),

    KEY ix_y_product_recipe_lines_site (
        site_id
    ),

    CONSTRAINT chk_y_product_recipe_lines_quantity_in_reference_unit
        CHECK (quantity_in_reference_unit > 0),

    CONSTRAINT fk_y_product_recipe_lines_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipe_lines_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipe_lines_recipe
        FOREIGN KEY (recipe_id)
        REFERENCES y_product_recipes(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipe_lines_material
        FOREIGN KEY (material_id)
        REFERENCES y_materials(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_product_recipe_lines_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_product_recipe_lines_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
