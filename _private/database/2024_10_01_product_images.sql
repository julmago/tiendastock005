CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_type ENUM('provider_product','store_product') NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  filename_base VARCHAR(190) NOT NULL,
  position INT NOT NULL,
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pi_owner (owner_type, owner_id),
  KEY idx_pi_owner_position (owner_type, owner_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
