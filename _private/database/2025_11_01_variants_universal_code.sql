ALTER TABLE product_variants
  ADD COLUMN universal_code VARCHAR(14) NULL AFTER sku_variant;

CREATE INDEX idx_variants_universal_code ON product_variants (universal_code);
