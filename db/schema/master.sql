-- AppPlanning — master schema loader
-- Usage: adjust database name then run.
USE ta_base;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

SOURCE db/schema/000_base.sql;
SOURCE db/schema/010_ref_skills_rulesets_reason.sql;
SOURCE db/schema/020_workers.sql;
SOURCE db/schema/030_demands.sql;
SOURCE db/schema/040_rosters_shifts.sql;
SOURCE db/schema/050_assignments_approvals.sql;