ALTER TABLE y_production_entries

ADD COLUMN production_day_product_id BIGINT UNSIGNED NULL
AFTER product_id,

ADD UNIQUE KEY ux_y_production_entries_day_product (
    production_day_product_id
),

ADD CONSTRAINT fk_y_production_entries_day_product
    FOREIGN KEY (production_day_product_id)
    REFERENCES y_production_day_products(id)
    ON DELETE RESTRICT;