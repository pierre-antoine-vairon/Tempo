CREATE TABLE y_site_operating_weekly_rules (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    weekday TINYINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    is_open TINYINT(1) NOT NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_site_operating_weekly_rules_site_weekday_effective (
        site_id,
        weekday,
        effective_from
    ),

    CONSTRAINT chk_y_site_operating_weekly_rules_weekday
        CHECK (weekday BETWEEN 1 AND 7),

    CONSTRAINT chk_y_site_operating_weekly_rules_is_open
        CHECK (is_open IN (0, 1)),

    CONSTRAINT fk_y_site_operating_weekly_rules_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_site_operating_weekly_rules_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_site_operating_weekly_rules_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_site_operating_weekly_rules_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE y_site_operating_date_exceptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,

    exception_date DATE NOT NULL,
    is_open TINYINT(1) NOT NULL,

    reason VARCHAR(255) NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY ux_y_site_operating_date_exceptions_site_date (
        site_id,
        exception_date
    ),

    CONSTRAINT chk_y_site_operating_date_exceptions_is_open
        CHECK (is_open IN (0, 1)),

    CONSTRAINT fk_y_site_operating_date_exceptions_org
        FOREIGN KEY (org_id)
        REFERENCES y_orgs(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_site_operating_date_exceptions_site
        FOREIGN KEY (site_id)
        REFERENCES y_sites(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_y_site_operating_date_exceptions_created_by
        FOREIGN KEY (created_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_y_site_operating_date_exceptions_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES y_users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
