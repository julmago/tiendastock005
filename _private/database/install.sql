SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin','provider','seller') NOT NULL,
  status ENUM('active','pending','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(120) NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings(`key`,`value`) VALUES
('seller_fee_percent','3.00'),
('provider_fee_percent','1.00'),
('mp_extra_percent','6.00');

CREATE TABLE IF NOT EXISTS providers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_user (user_id),
  KEY idx_provider_status (status),
  CONSTRAINT fk_providers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS provider_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  sku VARCHAR(120) NULL,
  universal_code VARCHAR(14) NULL,
  description TEXT NULL,
  base_price DECIMAL(12,2) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pp_provider (provider_id),
  CONSTRAINT fk_pp_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS warehouse_stock (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_product_id BIGINT UNSIGNED NOT NULL,
  qty_available INT NOT NULL DEFAULT 0,
  qty_reserved INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wh_pp (provider_product_id),
  CONSTRAINT fk_wh_pp FOREIGN KEY (provider_product_id) REFERENCES provider_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS warehouse_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  provider_product_id BIGINT UNSIGNED NOT NULL,
  qty_received INT NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_wr_pp (provider_product_id),
  CONSTRAINT fk_wr_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wr_pp FOREIGN KEY (provider_product_id) REFERENCES provider_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_wr_admin FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sellers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  account_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  wholesale_status ENUM('not_requested','pending','approved','rejected') NOT NULL DEFAULT 'not_requested',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seller_user (user_id),
  CONSTRAINT fk_sellers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  seller_id BIGINT UNSIGNED NOT NULL,
  store_type ENUM('retail','wholesale') NOT NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(60) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  markup_percent DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_slug (slug),
  UNIQUE KEY uq_seller_storetype (seller_id, store_type),
  CONSTRAINT fk_store_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_payment_methods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  method ENUM('mercadopago','transfer','cash_pickup') NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  extra_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_method (store_id, method),
  CONSTRAINT fk_spm_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS store_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  sku VARCHAR(120) NULL,
  universal_code VARCHAR(14) NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  own_stock_qty INT NOT NULL DEFAULT 0,
  own_stock_price DECIMAL(12,2) NULL,
  manual_price DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sp_store (store_id),
  CONSTRAINT fk_sp_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS store_product_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_product_id BIGINT UNSIGNED NOT NULL,
  provider_product_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sp_source (store_product_id, provider_product_id),
  CONSTRAINT fk_sps_sp FOREIGN KEY (store_product_id) REFERENCES store_products(id) ON DELETE CASCADE,
  CONSTRAINT fk_sps_pp FOREIGN KEY (provider_product_id) REFERENCES provider_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id BIGINT UNSIGNED NOT NULL,
  status ENUM('created','awaiting_payment','paid','packing','shipped','delivered','cancelled') NOT NULL DEFAULT 'created',
  payment_method ENUM('mercadopago','transfer','cash_pickup') NOT NULL,
  payment_status ENUM('pending','approved','rejected','refunded') NOT NULL DEFAULT 'pending',
  items_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  seller_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  provider_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  mp_extra_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orders_store (store_id),
  CONSTRAINT fk_orders_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  store_product_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  unit_sell_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  unit_base_price DECIMAL(12,2) NULL,
  PRIMARY KEY (id),
  KEY idx_oi_order (order_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_sp FOREIGN KEY (store_product_id) REFERENCES store_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id BIGINT UNSIGNED NOT NULL,
  source_type ENUM('provider','seller_own') NOT NULL,
  provider_product_id BIGINT UNSIGNED NULL,
  qty_allocated INT NOT NULL,
  unit_base_price DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_alloc_item (order_item_id),
  CONSTRAINT fk_alloc_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_pp FOREIGN KEY (provider_product_id) REFERENCES provider_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  method ENUM('mercadopago','transfer','cash_pickup') NOT NULL,
  status ENUM('pending','approved','rejected','refunded') NOT NULL DEFAULT 'pending',
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_order (order_id),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fees_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  seller_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  fee_type ENUM('seller_fee','provider_fee','mp_extra') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fee_order (order_id),
  CONSTRAINT fk_fee_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO users(email,password_hash,role,status)
VALUES ('julmago@gmail.com', '$2b$10$Xn4sM7tFfGQ/Oah2JJrGC.a37MJq1dOivn4YYWvQudpFLDzvIfsPq', 'superadmin', 'active');
