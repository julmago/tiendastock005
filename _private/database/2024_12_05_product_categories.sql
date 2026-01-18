ALTER TABLE provider_products
  ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER description,
  ADD KEY idx_pp_category (category_id),
  ADD CONSTRAINT fk_pp_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;

ALTER TABLE store_products
  ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER description,
  ADD KEY idx_sp_category (category_id),
  ADD CONSTRAINT fk_sp_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
