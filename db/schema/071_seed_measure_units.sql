-- Tempo — Achats & matières V1
--
-- Unités techniques de référence initiales.
--
-- IMPORTANT : ce script est destiné à être relu puis appliqué manuellement
-- dans MySQL Workbench sur la base distante partagée. Ne pas l'exécuter
-- automatiquement.
--
-- `code` est unique dans y_measure_units. INSERT IGNORE rend ce script
-- idempotent : une unité déjà présente n'est ni dupliquée ni modifiée.

INSERT IGNORE INTO y_measure_units (
    code,
    name,
    symbol,
    dimension,
    to_base_factor,
    is_active
) VALUES
    ('g', 'Gramme', 'g', 'mass', 1, 1),
    ('kg', 'Kilogramme', 'kg', 'mass', 1000, 1),
    ('ml', 'Millilitre', 'ml', 'volume', 1, 1),
    ('l', 'Litre', 'L', 'volume', 1000, 1),
    ('unit', 'Unité', 'u', 'count', 1, 1);
