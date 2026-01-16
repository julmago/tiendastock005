CREATE TABLE IF NOT EXISTS delivery_methods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  delivery_time VARCHAR(190) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
  position INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_status (status),
  KEY idx_delivery_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
