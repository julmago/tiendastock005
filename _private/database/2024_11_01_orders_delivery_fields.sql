ALTER TABLE orders
  ADD COLUMN delivery_method_id BIGINT UNSIGNED NULL AFTER mp_extra_amount,
  ADD COLUMN delivery_name VARCHAR(190) NULL AFTER delivery_method_id,
  ADD COLUMN delivery_time VARCHAR(190) NULL AFTER delivery_name,
  ADD COLUMN delivery_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER delivery_time;

ALTER TABLE orders
  ADD KEY idx_orders_delivery_method (delivery_method_id),
  ADD CONSTRAINT fk_orders_delivery_method FOREIGN KEY (delivery_method_id) REFERENCES delivery_methods(id) ON DELETE SET NULL;
