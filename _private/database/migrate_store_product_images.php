<?php
require __DIR__.'/../../config.php';
require __DIR__.'/../../lib/product_images.php';

$legacy_dir = __DIR__.'/../../uploads/products';
$provider_dir = __DIR__.'/../../uploads/provider_products';
$store_dir = __DIR__.'/../../uploads/store_products';

function copy_directory_files(string $source, string $target): int {
  if (!is_dir($source)) {
    return 0;
  }
  if (!is_dir($target)) {
    mkdir($target, 0775, true);
  }
  $count = 0;
  foreach (glob(rtrim($source, '/').'/*') as $file) {
    if (!is_file($file)) {
      continue;
    }
    $dest = rtrim($target, '/').'/'.basename($file);
    if (copy($file, $dest)) {
      $count++;
    }
  }
  return $count;
}

$image_errors = [];

$provider_ids = $pdo->query("SELECT DISTINCT owner_id FROM product_images WHERE owner_type='provider_product'")
  ->fetchAll(PDO::FETCH_COLUMN);
foreach ($provider_ids as $provider_id) {
  $provider_id = (int)$provider_id;
  if ($provider_id <= 0) {
    continue;
  }
  $source = $legacy_dir.'/'.$provider_id;
  $target = $provider_dir.'/'.$provider_id;
  copy_directory_files($source, $target);
}

$store_ids = $pdo->query("SELECT id FROM store_products")
  ->fetchAll(PDO::FETCH_COLUMN);

foreach ($store_ids as $store_id) {
  $store_id = (int)$store_id;
  if ($store_id <= 0) {
    continue;
  }
  $existing = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE owner_type='store_product' AND owner_id=?");
  $existing->execute([$store_id]);
  if ((int)$existing->fetchColumn() > 0) {
    continue;
  }

  $images_st = $pdo->prepare("SELECT filename_base, position FROM product_images WHERE owner_type='provider_product' AND owner_id=? ORDER BY position ASC");
  $images_st->execute([$store_id]);
  $images = $images_st->fetchAll();
  if (!$images) {
    continue;
  }

  $source_dir = $legacy_dir.'/'.$store_id;
  if (!is_dir($source_dir)) {
    $source_dir = $provider_dir.'/'.$store_id;
  }
  $target_dir = $store_dir.'/'.$store_id;

  $next_position = 0;
  foreach ($images as $image) {
    $base_name = $image['filename_base'] ?? '';
    if ($base_name === '') {
      continue;
    }
    if (!product_images_copy_files($source_dir, $target_dir, $base_name, $image_sizes, $image_errors)) {
      continue;
    }
    $next_position++;
    $is_cover = $next_position === 1 ? 1 : 0;
    $pdo->prepare("INSERT INTO product_images(owner_type, owner_id, filename_base, position, is_cover) VALUES(?,?,?,?,?)")
        ->execute(['store_product', $store_id, $base_name, $next_position, $is_cover]);
  }
}

if ($image_errors) {
  echo "Errores de migración: ".implode(' ', $image_errors)."\n";
} else {
  echo "Migración completada.\n";
}
