CREATE TABLE IF NOT EXISTS colors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  codigo VARCHAR(4) NULL,
  hex VARCHAR(10) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_colors_name (name),
  KEY idx_colors_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_type ENUM('provider','vendor') NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  color_id BIGINT UNSIGNED NOT NULL,
  sku_variant VARCHAR(120) NULL,
  stock_qty INT NOT NULL,
  image_cover VARCHAR(190) NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_variant (owner_type, owner_id, product_id, color_id),
  KEY idx_variant_owner_product (owner_type, owner_id, product_id),
  KEY idx_variant_color (color_id),
  CONSTRAINT fk_variants_color FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
