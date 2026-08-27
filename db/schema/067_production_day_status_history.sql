CREATE TABLE IF NOT EXISTS y_production_day_status_history (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    production_day_id BIGINT UNSIGNED NOT NULL,

    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,

    reason VARCHAR(255) NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    KEY ix_y_production_day_status_history_org (
        org_id
    ),

    KEY ix_y_production_day_status_history_site (
        site_id
    ),

    KEY ix_y_production_day_status_history_day (
        production_day_id
    ),

    KEY ix_y_production_day_status_history_date (
        production_day_id,
        created_at
    ),

    CONSTRAINT fk_y_production_day_status_history_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_day_status_history_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_day_status_history_day
        FOREIGN KEY (production_day_id)
        REFERENCES y_production_days(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_production_day_status_history_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;