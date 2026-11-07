-- Tempo — Corrections contrôlées des fiches techniques produits
--
-- Une nouvelle version métier de recette reste créée dans
-- y_product_recipes avec une nouvelle effective_from. Cette table journalise
-- uniquement la correction d'une erreur de saisie sur une version existante.
-- Le backend limite cette correction à la dernière version du produit.
--
-- before_lines_json et after_lines_json conservent les compositions complètes
-- avant et après correction. Une recette ne peut pas être supprimée tant que
-- son audit de correction existe.
--
-- IMPORTANT : ce script doit être relu puis appliqué manuellement dans MySQL
-- Workbench sur la base distante partagée. Ne pas l'exécuter automatiquement.

CREATE TABLE y_product_recipe_corrections (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,

    correction_reason VARCHAR(500) NOT NULL,
    before_lines_json JSON NOT NULL,
    after_lines_json JSON NOT NULL,

    corrected_by BIGINT UNSIGNED NULL,
    corrected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY ix_y_product_recipe_corrections_recipe (
        recipe_id
    ),

    KEY ix_y_product_recipe_corrections_org_site (
        org_id,
        site_id
    ),

    CONSTRAINT fk_y_product_recipe_corrections_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipe_corrections_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_product_recipe_corrections_recipe
        FOREIGN KEY (recipe_id)
        REFERENCES y_product_recipes(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_y_product_recipe_corrections_corrected_by
        FOREIGN KEY (corrected_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
