ALTER TABLE product_images
  DROP FOREIGN KEY fk_pi_product,
  CHANGE product_id owner_id BIGINT UNSIGNED NOT NULL,
  ADD COLUMN owner_type ENUM('provider_product','store_product') NOT NULL DEFAULT 'provider_product' AFTER id;

ALTER TABLE product_images
  DROP INDEX idx_pi_product,
  DROP INDEX idx_pi_product_position,
  ADD KEY idx_pi_owner (owner_type, owner_id),
  ADD KEY idx_pi_owner_position (owner_type, owner_id, position);
