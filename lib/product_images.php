<?php

$max_image_size_bytes = 10 * 1024 * 1024;
$image_sizes = [1200, 600, 150];

function product_image_with_size(string $base, int $size): string {
  $dot = strrpos($base, '.');
  if ($dot === false) {
    return $base.'_'.$size;
  }
  return substr($base, 0, $dot).'_'.$size.substr($base, $dot);
}

function prepare_png_canvas($image, int $width, int $height): void {
  imagealphablending($image, false);
  imagesavealpha($image, true);
  $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
  imagefilledrectangle($image, 0, 0, $width, $height, $transparent);
}

function save_resized_square($square, int $size, string $dest, int $image_type): bool {
  $resized = imagecreatetruecolor($size, $size);
  if ($image_type === IMAGETYPE_PNG) {
    prepare_png_canvas($resized, $size, $size);
  }
  $square_size = imagesx($square);
  imagecopyresampled($resized, $square, 0, 0, 0, 0, $size, $size, $square_size, $square_size);
  if ($image_type === IMAGETYPE_PNG) {
    $result = imagepng($resized, $dest, 6);
  } else {
    $result = imagejpeg($resized, $dest, 85);
  }
  imagedestroy($resized);
  return $result;
}

function product_images_fetch(PDO $pdo, string $owner_type, int $owner_id): array {
  $st = $pdo->prepare("SELECT id, filename_base, position FROM product_images WHERE owner_type=? AND owner_id=? ORDER BY position ASC");
  $st->execute([$owner_type, $owner_id]);
  return $st->fetchAll();
}

function product_images_apply_order(PDO $pdo, string $owner_type, int $owner_id, string $images_order_raw): void {
  $images_order_raw = trim($images_order_raw);
  if ($images_order_raw === '') return;
  $ids = array_filter(array_map('intval', explode(',', $images_order_raw)));
  $st = $pdo->prepare("SELECT id FROM product_images WHERE owner_type=? AND owner_id=?");
  $st->execute([$owner_type, $owner_id]);
  $existing_ids = $st->fetchAll(PDO::FETCH_COLUMN);
  $existing_ids = array_map('intval', $existing_ids);
  $ordered = [];
  foreach ($ids as $id) {
    if (in_array($id, $existing_ids, true) && !in_array($id, $ordered, true)) {
      $ordered[] = $id;
    }
  }
  foreach ($existing_ids as $id) {
    if (!in_array($id, $ordered, true)) {
      $ordered[] = $id;
    }
  }
  $pdo->beginTransaction();
  $position = 1;
  foreach ($ordered as $id) {
    $pdo->prepare("UPDATE product_images SET position=?, is_cover=? WHERE id=? AND owner_type=? AND owner_id=?")
        ->execute([$position, $position === 1 ? 1 : 0, $id, $owner_type, $owner_id]);
    $position++;
  }
  $pdo->commit();
}

function product_images_resequence(PDO $pdo, string $owner_type, int $owner_id): void {
  $st = $pdo->prepare("SELECT id FROM product_images WHERE owner_type=? AND owner_id=? ORDER BY position ASC, id ASC");
  $st->execute([$owner_type, $owner_id]);
  $ids = $st->fetchAll(PDO::FETCH_COLUMN);
  $pdo->beginTransaction();
  $position = 1;
  foreach ($ids as $id) {
    $pdo->prepare("UPDATE product_images SET position=?, is_cover=? WHERE id=? AND owner_type=? AND owner_id=?")
        ->execute([$position, $position === 1 ? 1 : 0, $id, $owner_type, $owner_id]);
    $position++;
  }
  $pdo->commit();
}

function product_images_delete(PDO $pdo, string $owner_type, int $owner_id, int $image_id, string $upload_dir, array $image_sizes): bool {
  $st = $pdo->prepare("SELECT filename_base FROM product_images WHERE id=? AND owner_type=? AND owner_id=? LIMIT 1");
  $st->execute([$image_id, $owner_type, $owner_id]);
  $filename_base = $st->fetchColumn();
  if (!$filename_base) {
    return false;
  }

  $pdo->prepare("DELETE FROM product_images WHERE id=? AND owner_type=? AND owner_id=? LIMIT 1")
      ->execute([$image_id, $owner_type, $owner_id]);

  $files = [];
  foreach ($image_sizes as $size) {
    $files[] = $upload_dir.'/'.product_image_with_size($filename_base, $size);
  }
  $files[] = $upload_dir.'/'.$filename_base;

  foreach ($files as $file_path) {
    if (is_file($file_path) && !unlink($file_path)) {
      error_log("No se pudo borrar el archivo {$file_path}");
    }
  }

  product_images_resequence($pdo, $owner_type, $owner_id);
  return true;
}

function product_images_sort_indices(array $files, ?array $order, bool $append_remaining = true): array {
  $total = isset($files['name']) ? count($files['name']) : 0;
  if ($total === 0) return [];
  $ordered = [];
  if ($order) {
    foreach ($order as $index) {
      $index = (int)$index;
      if ($index >= 0 && $index < $total && !in_array($index, $ordered, true)) {
        $ordered[] = $index;
      }
    }
  }
  if ($append_remaining) {
    for ($i = 0; $i < $total; $i++) {
      if (!in_array($i, $ordered, true)) {
        $ordered[] = $i;
      }
    }
  }
  return $ordered;
}

function product_images_process_uploads(PDO $pdo, string $owner_type, int $owner_id, array $files, string $upload_dir, array $image_sizes, int $max_image_size_bytes, array &$image_errors, ?array $upload_order = null, ?int &$next_position = null, bool $append_remaining = true): int {
  if (empty($files['name'][0])) return $next_position ?? 0;
  if (!function_exists('imagecreatefromjpeg')) {
    $image_errors[] = 'GD no está disponible para procesar imágenes.';
    return $next_position ?? 0;
  }

  if ($next_position === null) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM product_images WHERE owner_type=? AND owner_id=?");
    $st->execute([$owner_type, $owner_id]);
    $next_position = (int)$st->fetchColumn();
  }

  $ordered_indices = product_images_sort_indices($files, $upload_order, $append_remaining);
  foreach ($ordered_indices as $idx) {
    $name = $files['name'][$idx] ?? '';
    $error = $files['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
      if ($error !== UPLOAD_ERR_NO_FILE) {
        $image_errors[] = "Error al subir {$name}.";
      }
      continue;
    }
    $tmp_path = $files['tmp_name'][$idx] ?? '';
    $size = (int)($files['size'][$idx] ?? 0);
    if ($size <= 0 || $size > $max_image_size_bytes) {
      $image_errors[] = "La imagen {$name} supera el tamaño permitido.";
      continue;
    }
    $info = getimagesize($tmp_path);
    if ($info === false) {
      $image_errors[] = "El archivo {$name} no es una imagen válida.";
      continue;
    }
    $image_type = $info[2];
    if (!in_array($image_type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
      $image_errors[] = "Formato no soportado para {$name}.";
      continue;
    }

    $ext = $image_type === IMAGETYPE_PNG ? 'png' : 'jpg';
    $base_name = bin2hex(random_bytes(16)).'.'.$ext;
    if ($image_type === IMAGETYPE_PNG) {
      $source = imagecreatefrompng($tmp_path);
    } else {
      $source = imagecreatefromjpeg($tmp_path);
    }
    if (!$source) {
      $image_errors[] = "No se pudo procesar {$name}.";
      continue;
    }
    $width = imagesx($source);
    $height = imagesy($source);
    $side = min($width, $height);
    $src_x = (int)(($width - $side) / 2);
    $src_y = (int)(($height - $side) / 2);
    $square = imagecreatetruecolor($side, $side);
    if ($image_type === IMAGETYPE_PNG) {
      prepare_png_canvas($square, $side, $side);
    }
    imagecopyresampled($square, $source, 0, 0, $src_x, $src_y, $side, $side, $side, $side);
    imagedestroy($source);

    $saved = true;
    foreach ($image_sizes as $target_size) {
      $dest = $upload_dir.'/'.product_image_with_size($base_name, $target_size);
      if (!save_resized_square($square, $target_size, $dest, $image_type)) {
        $saved = false;
        break;
      }
    }
    imagedestroy($square);

    if (!$saved) {
      $image_errors[] = "No se pudo guardar {$name}.";
      continue;
    }
    $next_position++;
    $is_cover = $next_position === 1 ? 1 : 0;
    $pdo->prepare("INSERT INTO product_images(owner_type, owner_id, filename_base, position, is_cover) VALUES(?,?,?,?,?)")
        ->execute([$owner_type, $owner_id, $base_name, $next_position, $is_cover]);
  }

  return $next_position;
}

function product_images_copy_files(string $source_dir, string $target_dir, string $base_name, array $image_sizes, array &$image_errors): bool {
  if (!function_exists('imagecreatefromjpeg')) {
    $image_errors[] = 'GD no está disponible para procesar imágenes.';
    return false;
  }
  if (!is_dir($target_dir)) {
    mkdir($target_dir, 0775, true);
  }

  $extension = strtolower(pathinfo($base_name, PATHINFO_EXTENSION));
  $image_type = ($extension === 'png') ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
  $sizes_desc = $image_sizes;
  rsort($sizes_desc);
  $source_path = '';
  foreach ($sizes_desc as $size) {
    $candidate = $source_dir.'/'.product_image_with_size($base_name, $size);
    if (is_file($candidate)) {
      $source_path = $candidate;
      break;
    }
  }
  if ($source_path === '') {
    $image_errors[] = "No se encontró la imagen {$base_name} para copiar.";
    return false;
  }

  if ($image_type === IMAGETYPE_PNG) {
    $source = imagecreatefrompng($source_path);
  } else {
    $source = imagecreatefromjpeg($source_path);
  }
  if (!$source) {
    $image_errors[] = "No se pudo leer {$base_name} para copiar.";
    return false;
  }

  foreach ($image_sizes as $size) {
    $source_file = $source_dir.'/'.product_image_with_size($base_name, $size);
    $target_file = $target_dir.'/'.product_image_with_size($base_name, $size);
    if (is_file($source_file)) {
      if (!copy($source_file, $target_file)) {
        $image_errors[] = "No se pudo copiar {$base_name}.";
        imagedestroy($source);
        return false;
      }
    } else {
      if (!save_resized_square($source, $size, $target_file, $image_type)) {
        $image_errors[] = "No se pudo generar {$base_name}.";
        imagedestroy($source);
        return false;
      }
    }
  }

  imagedestroy($source);
  return true;
}

function product_images_copy_from_provider(PDO $pdo, int $source_product_id, int $target_product_id, array $images, string $source_dir, string $target_dir, array $image_sizes, array &$image_errors, ?int &$next_position = null): int {
  if (!$images) return $next_position ?? 0;

  if ($next_position === null) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM product_images WHERE owner_type=? AND owner_id=?");
    $st->execute(['store_product', $target_product_id]);
    $next_position = (int)$st->fetchColumn();
  }

  $seen = [];

  foreach ($images as $image) {
    $base_name = $image['filename_base'] ?? '';
    if ($base_name === '' || isset($seen[$base_name])) {
      continue;
    }
    $seen[$base_name] = true;
    if (!product_images_copy_files($source_dir, $target_dir, $base_name, $image_sizes, $image_errors)) {
      continue;
    }
    $next_position++;
    $is_cover = $next_position === 1 ? 1 : 0;
    $pdo->prepare("INSERT INTO product_images(owner_type, owner_id, filename_base, position, is_cover) VALUES(?,?,?,?,?)")
        ->execute(['store_product', $target_product_id, $base_name, $next_position, $is_cover]);
  }

  return $next_position;
}
