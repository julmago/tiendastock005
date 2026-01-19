CREATE TABLE IF NOT EXISTS sizes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(4) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sizes_name (name),
  UNIQUE KEY uq_sizes_code (code),
  KEY idx_sizes_active (active),
  KEY idx_sizes_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE product_variants
  ADD COLUMN size_id BIGINT UNSIGNED NULL AFTER color_id,
  DROP INDEX uq_product_variant,
  ADD UNIQUE KEY uq_product_variant (owner_type, owner_id, product_id, color_id, size_id),
  ADD KEY idx_variant_size (size_id),
  ADD CONSTRAINT fk_variants_size FOREIGN KEY (size_id) REFERENCES sizes(id) ON DELETE RESTRICT;
