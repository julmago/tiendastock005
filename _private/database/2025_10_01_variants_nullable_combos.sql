ALTER TABLE product_variants
  MODIFY COLUMN color_id BIGINT UNSIGNED NULL,
  ADD COLUMN color_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(color_id,0)) STORED AFTER size_id,
  ADD COLUMN size_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(size_id,0)) STORED AFTER color_key,
  DROP INDEX uq_product_variant,
  ADD UNIQUE KEY uq_product_variant (owner_type, owner_id, product_id, color_key, size_key);
