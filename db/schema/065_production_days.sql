CREATE TABLE IF NOT EXISTS y_production_days (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    production_date DATE NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'in_progress',

    started_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    started_by BIGINT UNSIGNED NULL,

    finished_at TIMESTAMP NULL,
    finished_by BIGINT UNSIGNED NULL,

    closed_at TIMESTAMP NULL,
    closed_by BIGINT UNSIGNED NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_production_days_site_date (
        site_id,
        production_date
    ),

    KEY ix_y_production_days_org_date (
        org_id,
        production_date
    ),

    KEY ix_y_production_days_site_date (
        site_id,
        production_date
    ),

    KEY ix_y_production_days_site_status (
        site_id,
        status
    ),

    CONSTRAINT fk_y_production_days_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_days_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_days_started_by
        FOREIGN KEY (started_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_production_days_finished_by
        FOREIGN KEY (finished_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_production_days_closed_by
        FOREIGN KEY (closed_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_production_days_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_production_days_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;