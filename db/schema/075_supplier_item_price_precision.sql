-- À appliquer manuellement dans MySQL Workbench après validation.
-- Le CHECK chk_y_supplier_item_prices_price_excl_tax existant est conservé.

ALTER TABLE y_supplier_item_prices
    MODIFY COLUMN price_excl_tax DECIMAL(14,6) NOT NULL;
