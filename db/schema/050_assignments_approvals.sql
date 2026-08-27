-- AppPlanning SQL
-- MariaDB-compatible, ENGINE=InnoDB, utf8mb4_unicode_ci, UTC session recommended
-- Usage:
--   USE ta_base;
--   SET NAMES utf8mb4;
--   SET time_zone = '+00:00';
-- Re-run safe: DROP IF EXISTS included where useful.

-- Batch 050 — Assignments & Approvals

DROP TABLE IF EXISTS y_assignment_ack;
DROP TABLE IF EXISTS y_roster_approvals;
DROP TABLE IF EXISTS y_assignments;

CREATE TABLE IF NOT EXISTS y_assignments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  org_id BIGINT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NOT NULL,
  worker_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(64) NULL,
  status ENUM('planned','confirmed','absent','replaced') NOT NULL DEFAULT 'planned',
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_y_assignments_shift_worker (shift_id, worker_id),
  KEY ix_y_assignments_worker (worker_id),
  KEY ix_y_assignments_org (org_id),
  CONSTRAINT fk_y_asg_org        FOREIGN KEY (org_id)     REFERENCES y_orgs(id)     ON DELETE CASCADE,
  CONSTRAINT fk_y_asg_shift      FOREIGN KEY (shift_id)   REFERENCES y_shifts(id)   ON DELETE CASCADE,
  CONSTRAINT fk_y_asg_worker     FOREIGN KEY (worker_id)  REFERENCES y_workers(id)  ON DELETE CASCADE,
  CONSTRAINT fk_y_asg_created_by FOREIGN KEY (created_by) REFERENCES y_users(id)    ON DELETE SET NULL,
  CONSTRAINT fk_y_asg_updated_by FOREIGN KEY (updated_by) REFERENCES y_users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_assignment_ack (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL,
  worker_id BIGINT UNSIGNED NOT NULL,
  ack_status ENUM('accepted','declined') NOT NULL,
  ack_at DATETIME NOT NULL,
  comment TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_y_ack_assignment (assignment_id, ack_at),
  KEY ix_y_ack_worker (worker_id, ack_at),
  CONSTRAINT fk_y_ack_assignment FOREIGN KEY (assignment_id) REFERENCES y_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_ack_worker     FOREIGN KEY (worker_id)    REFERENCES y_workers(id)     ON DELETE CASCADE,
  CONSTRAINT fk_y_ack_created_by FOREIGN KEY (created_by)   REFERENCES y_users(id)       ON DELETE SET NULL,
  CONSTRAINT fk_y_ack_updated_by FOREIGN KEY (updated_by)   REFERENCES y_users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS y_roster_approvals (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  roster_id BIGINT UNSIGNED NOT NULL,
  required_user_id BIGINT UNSIGNED NULL,
  required_role ENUM('admin','manager','employee') NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  comment TEXT NULL,
  `order` SMALLINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_y_ra_roster_order (roster_id, `order`),
  KEY ix_y_ra_roster (roster_id),
  KEY ix_y_ra_status (status),
  CONSTRAINT fk_y_ra_roster      FOREIGN KEY (roster_id)        REFERENCES y_rosters(id) ON DELETE CASCADE,
  CONSTRAINT fk_y_ra_req_user    FOREIGN KEY (required_user_id) REFERENCES y_users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_y_ra_approved_by FOREIGN KEY (approved_by)      REFERENCES y_users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_y_ra_created_by  FOREIGN KEY (created_by)       REFERENCES y_users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_y_ra_updated_by  FOREIGN KEY (updated_by)       REFERENCES y_users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
