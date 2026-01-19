<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../lib/product_images.php';
csrf_check();
$role = $_SESSION['role'] ?? '';
if ($role === 'superadmin') {
  require_role('superadmin', '/admin/login.php');
} else {
  require_role('provider','/proveedor/login.php');
}

$p = null;
if ($role === 'superadmin') {
  $providerId = (int)($_GET['provider_id'] ?? $_POST['provider_id'] ?? 0);
  if ($providerId > 0) {
    $st = $pdo->prepare("SELECT id, status, display_name FROM providers WHERE id=? LIMIT 1");
    $st->execute([$providerId]);
    $p = $st->fetch();
    if (!$p) {
      $providerId = 0;
      $err = "Proveedor inválido.";
    }
  }
} else {
  $st = $pdo->prepare("SELECT id, status FROM providers WHERE user_id=? LIMIT 1");
  $st->execute([(int)$_SESSION['uid']]);
  $p = $st->fetch();
  if (!$p) exit('Proveedor inválido');
  $providerId = (int)$p['id'];
}

$providerId = (int)($p['id'] ?? 0);
$providerStatus = (string)($p['status'] ?? '');

if ($role === 'superadmin' && $providerId === 0) {
  $providers = $pdo->query("SELECT id, display_name FROM providers ORDER BY id DESC")->fetchAll();
  page_header('Proveedor - Catálogo base');
  if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
  echo "<form method='get'>
  <p>Proveedor:
    <select name='provider_id'>
      <option value='0'>-- elegir --</option>";
  foreach ($providers as $provider) {
    echo "<option value='".h((string)$provider['id'])."'>".h((string)$provider['display_name'])."</option>";
  }
  echo "</select>
    <button>Ver</button>
  </p>
  </form>";
  page_footer();
  exit;
}

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_product = null;
$product_images = [];
$image_errors = [];

$categoryRows = $pdo->query("SELECT id, parent_id, name FROM categories ORDER BY name ASC, id ASC")->fetchAll();
$categoriesByParent = [];
foreach ($categoryRows as $cat) {
  $parentId = $cat['parent_id'] ? (int)$cat['parent_id'] : 0;
  $categoriesByParent[$parentId][] = $cat;
}

function flatten_categories(array $byParent, int $parentId, int $depth, array &$flat): void {
  if (empty($byParent[$parentId])) {
    return;
  }
  foreach ($byParent[$parentId] as $cat) {
    $cat['depth'] = $depth;
    $flat[] = $cat;
    flatten_categories($byParent, (int)$cat['id'], $depth + 1, $flat);
  }
}

$flatCategories = [];
flatten_categories($categoriesByParent, 0, 0, $flatCategories);
$categoryIdSet = [];
foreach ($flatCategories as $cat) {
  $categoryIdSet[(int)$cat['id']] = true;
}

$variantHandled = false;
$canManageVariants = in_array($role, ['superadmin', 'provider'], true);
$variant_image_size = 600;

function variant_image_relative_path(int $variantId, string $filename): string {
  return "/uploads/provider_variant_images/".$variantId."/".$filename;
}

function variant_image_disk_path(string $relativePath): string {
  return __DIR__.'/../'.ltrim($relativePath, '/');
}

function variant_image_delete_existing(?string $imageCover): void {
  if (!$imageCover) {
    return;
  }
  if (strpos($imageCover, '/uploads/provider_variant_images/') !== 0) {
    return;
  }
  $diskPath = variant_image_disk_path($imageCover);
  if (is_file($diskPath) && !unlink($diskPath)) {
    error_log("No se pudo borrar la imagen de variante {$diskPath}");
  }
}

function variant_image_process_upload(array $file, int $variantId, int $maxImageSize, int $targetSize, array &$imageErrors): ?string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $imageErrors[] = 'Error al subir la imagen de variante.';
    return null;
  }
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > $maxImageSize) {
    $imageErrors[] = 'La imagen de variante supera el tamaño permitido.';
    return null;
  }
  $tmpPath = $file['tmp_name'] ?? '';
  $info = $tmpPath ? getimagesize($tmpPath) : false;
  if ($info === false) {
    $imageErrors[] = 'El archivo de variante no es una imagen válida.';
    return null;
  }
  $imageType = $info[2];
  if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
    $imageErrors[] = 'Formato no soportado para la imagen de variante.';
    return null;
  }
  if (!function_exists('imagecreatefromjpeg')) {
    $imageErrors[] = 'GD no está disponible para procesar imágenes.';
    return null;
  }
  $ext = $imageType === IMAGETYPE_PNG ? 'png' : 'jpg';
  $baseName = bin2hex(random_bytes(16)).'.'.$ext;
  $uploadDir = __DIR__.'/../uploads/provider_variant_images/'.$variantId;
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
  }
  if ($imageType === IMAGETYPE_PNG) {
    $source = imagecreatefrompng($tmpPath);
  } else {
    $source = imagecreatefromjpeg($tmpPath);
  }
  if (!$source) {
    $imageErrors[] = 'No se pudo procesar la imagen de variante.';
    return null;
  }
  $width = imagesx($source);
  $height = imagesy($source);
  $side = min($width, $height);
  $srcX = (int)(($width - $side) / 2);
  $srcY = (int)(($height - $side) / 2);
  $square = imagecreatetruecolor($side, $side);
  if ($imageType === IMAGETYPE_PNG) {
    prepare_png_canvas($square, $side, $side);
  }
  imagecopyresampled($square, $source, 0, 0, $srcX, $srcY, $side, $side, $side, $side);
  imagedestroy($source);
  $dest = $uploadDir.'/'.$baseName;
  if (!save_resized_square($square, $targetSize, $dest, $imageType)) {
    $imageErrors[] = 'No se pudo guardar la imagen de variante.';
    imagedestroy($square);
    return null;
  }
  imagedestroy($square);
  return variant_image_relative_path($variantId, $baseName);
}

if ($canManageVariants && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $variantAction = $_POST['action'] ?? '';
  $allowedActions = $role === 'superadmin'
    ? ['add_variant', 'update_variant', 'delete_variant', 'move_variant']
    : ['add_variant', 'update_variant', 'delete_variant'];
  if (in_array($variantAction, $allowedActions, true)) {
    $variantHandled = true;
    $product_id = (int)($_POST['product_id'] ?? 0);
    $edit_id = $product_id;
    $productSku = '';
    if ($product_id <= 0) {
      $err = "Producto inválido.";
    } else {
      $st = $pdo->prepare("SELECT id, sku FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
      $st->execute([$product_id, $providerId]);
      $productRow = $st->fetch();
      if (!$productRow) {
        $err = "Producto inválido.";
      } else {
        $productSku = trim((string)($productRow['sku'] ?? ''));
      }
    }

    if (empty($err)) {
      if ($role !== 'superadmin' && $providerStatus !== 'active') {
        $err = "Cuenta pendiente de aprobación.";
      }
    }

    if (empty($err)) {
      if ($variantAction === 'add_variant') {
        $colorId = (int)($_POST['color_id'] ?? 0);
        $sizeId = (int)($_POST['size_id'] ?? 0);
        $stockQty = 0;
        $skuVariant = trim((string)($_POST['sku_variant'] ?? ''));
        $imageValue = null;
        $variantCount = 0;
        if ($colorId <= 0) {
          $err = "Color inválido.";
        } elseif ($sizeId <= 0) {
          $err = "Talle inválido.";
        } else {
          if ($role === 'superadmin') {
            $colorSt = $pdo->prepare("SELECT id, codigo FROM colors WHERE id=? LIMIT 1");
            $colorSt->execute([$colorId]);
            $sizeSt = $pdo->prepare("SELECT id, code FROM sizes WHERE id=? LIMIT 1");
            $sizeSt->execute([$sizeId]);
          } else {
            $colorSt = $pdo->prepare("SELECT id, codigo FROM colors WHERE id=? AND active=1 LIMIT 1");
            $colorSt->execute([$colorId]);
            $sizeSt = $pdo->prepare("SELECT id, code FROM sizes WHERE id=? AND active=1 LIMIT 1");
            $sizeSt->execute([$sizeId]);
          }
          $colorRow = $colorSt->fetch();
          $sizeRow = $sizeSt->fetch();
          if (!$colorRow) {
            $err = "Color inválido.";
          } elseif (!$sizeRow) {
            $err = "Talle inválido.";
          } elseif ($skuVariant === '' && $productSku !== '') {
            $colorCode = trim((string)($colorRow['codigo'] ?? ''));
            $sizeCode = trim((string)($sizeRow['code'] ?? ''));
            if ($colorCode === '' || $sizeCode === '') {
              $err = "El color o el talle no tienen código.";
            } else {
              $skuVariant = $productSku.'-'.$colorCode.'-'.$sizeCode;
            }
          }
        }

        if (empty($err)) {
          if ($skuVariant === '') {
            $err = "SKU de variante obligatorio.";
          }
        }

        if (empty($err)) {
          $countSt = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE owner_type='provider' AND owner_id=? AND product_id=?");
          $countSt->execute([$providerId, $product_id]);
          $variantCount = (int)$countSt->fetchColumn();
          $dupSt = $pdo->prepare("SELECT id FROM product_variants WHERE owner_type='provider' AND owner_id=? AND product_id=? AND color_id=? AND size_id=? LIMIT 1");
          $dupSt->execute([$providerId, $product_id, $colorId, $sizeId]);
          if ($dupSt->fetch()) {
            $err = "Ya existe una variante para ese Color y Talle.";
          }
        }

        if (empty($err)) {
          $posSt = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM product_variants WHERE owner_type='provider' AND owner_id=? AND product_id=?");
          $posSt->execute([$providerId, $product_id]);
          $nextPos = (int)$posSt->fetchColumn() + 1;
          $insertSt = $pdo->prepare("
            INSERT INTO product_variants(owner_type, owner_id, product_id, color_id, size_id, sku_variant, stock_qty, image_cover, position)
            VALUES('provider', ?, ?, ?, ?, ?, ?, ?, ?)
          ");
          $insertSt->execute([$providerId, $product_id, $colorId, $sizeId, $skuVariant, $stockQty, $imageValue, $nextPos]);
          $variantId = (int)$pdo->lastInsertId();
          $uploadedPath = null;
          if (!empty($_FILES['variant_image'])) {
            $uploadedPath = variant_image_process_upload($_FILES['variant_image'], $variantId, $max_image_size_bytes, $variant_image_size, $image_errors);
          }
          if ($uploadedPath !== null) {
            $pdo->prepare("UPDATE product_variants SET image_cover=? WHERE id=? AND owner_type='provider' AND owner_id=? AND product_id=?")
                ->execute([$uploadedPath, $variantId, $providerId, $product_id]);
          }
          if ($variantCount === 0) {
            $pdo->prepare("UPDATE warehouse_stock SET qty_available=0, qty_reserved=0 WHERE provider_product_id=?")
                ->execute([$product_id]);
          }
          $msg = "Variante agregada.";
        }
      } elseif ($variantAction === 'update_variant') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $skuVariant = trim((string)($_POST['sku_variant'] ?? ''));
        $removeImage = ($_POST['remove_variant_image'] ?? '') === '1';
        if ($variantId <= 0) {
          $err = "Variante inválida.";
        } else {
          $currentSt = $pdo->prepare("
            SELECT pv.image_cover, pv.color_id, pv.size_id, c.codigo, s.code AS size_code
            FROM product_variants pv
            JOIN colors c ON c.id = pv.color_id
            LEFT JOIN sizes s ON s.id = pv.size_id
            WHERE pv.id=? AND pv.owner_type='provider' AND pv.owner_id=? AND pv.product_id=?
          ");
          $currentSt->execute([$variantId, $providerId, $product_id]);
          $currentRow = $currentSt->fetch();
          if (!$currentRow) {
            $err = "Variante inválida.";
          } else {
            $currentImage = $currentRow['image_cover'] ?? null;
            if ($skuVariant === '' && $productSku !== '') {
              $colorCode = trim((string)($currentRow['codigo'] ?? ''));
              $sizeCode = trim((string)($currentRow['size_code'] ?? ''));
              if ($colorCode === '' || $sizeCode === '') {
                $err = "El color o el talle no tienen código.";
              } else {
                $skuVariant = $productSku.'-'.$colorCode.'-'.$sizeCode;
              }
            }
          }
        }

        if (empty($err)) {
          if ($skuVariant === '') {
            $err = "SKU de variante obligatorio.";
          }
        }

        if (empty($err)) {
          $imageValue = $currentImage ?: null;
          if ($removeImage && $imageValue) {
            variant_image_delete_existing($imageValue);
            $imageValue = null;
          }
          if (!empty($_FILES['variant_image']) && ($_FILES['variant_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadedPath = variant_image_process_upload($_FILES['variant_image'], $variantId, $max_image_size_bytes, $variant_image_size, $image_errors);
            if ($uploadedPath !== null) {
              if ($imageValue) {
                variant_image_delete_existing($imageValue);
              }
              $imageValue = $uploadedPath;
            }
          }
        }

        if (empty($err)) {
          $updateSt = $pdo->prepare("
            UPDATE product_variants
            SET sku_variant=?, image_cover=?
            WHERE id=? AND owner_type='provider' AND owner_id=? AND product_id=?
          ");
          $updateSt->execute([$skuVariant, $imageValue, $variantId, $providerId, $product_id]);
          if ($updateSt->rowCount() === 0) {
            $err = "Variante inválida.";
          } else {
            $msg = "Variante actualizada.";
          }
        }
      } elseif ($variantAction === 'delete_variant') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        if ($variantId <= 0) {
          $err = "Variante inválida.";
        } else {
          $delSt = $pdo->prepare("DELETE FROM product_variants WHERE id=? AND owner_type='provider' AND owner_id=? AND product_id=?");
          $delSt->execute([$variantId, $providerId, $product_id]);
          if ($delSt->rowCount() === 0) {
            $err = "Variante inválida.";
          } else {
            $msg = "Variante eliminada.";
          }
        }
      } elseif ($variantAction === 'move_variant' && $role === 'superadmin') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        if ($variantId <= 0 || !in_array($direction, ['up', 'down'], true)) {
          $err = "Movimiento inválido.";
        } else {
          $orderSt = $pdo->prepare("
            SELECT id, position
            FROM product_variants
            WHERE owner_type='provider' AND owner_id=? AND product_id=?
            ORDER BY position ASC, id ASC
          ");
          $orderSt->execute([$providerId, $product_id]);
          $variants = $orderSt->fetchAll();
          $index = null;
          foreach ($variants as $i => $variant) {
            if ((int)$variant['id'] === $variantId) {
              $index = $i;
              break;
            }
          }
          if ($index === null) {
            $err = "Variante inválida.";
          } else {
            $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if (!isset($variants[$swapIndex])) {
              $err = "No se puede mover.";
            } else {
              $current = $variants[$index];
              $swap = $variants[$swapIndex];
              $pdo->prepare("UPDATE product_variants SET position=? WHERE id=?")
                  ->execute([(int)$swap['position'], (int)$current['id']]);
              $pdo->prepare("UPDATE product_variants SET position=? WHERE id=?")
                  ->execute([(int)$current['position'], (int)$swap['id']]);
              $msg = "Orden actualizado.";
            }
          }
        }
      }
    }
  }
}

if (!$variantHandled && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'delete_image') {
  $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
  $image_id = isset($_POST['delete_image_id']) ? (int)$_POST['delete_image_id'] : 0;
  if ($product_id <= 0 || $image_id <= 0) {
    $err = "Imagen inválida.";
  } else {
    $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
    $st->execute([$product_id, $providerId]);
    if (!$st->fetch()) {
      $err = "Producto inválido.";
    } else {
      $upload_dir = __DIR__.'/../uploads/provider_products/'.$product_id;
      if (product_images_delete($pdo, 'provider_product', $product_id, $image_id, $upload_dir, $image_sizes)) {
        $msg = "Imagen eliminada.";
      } else {
        $err = "Imagen inválida.";
      }
      $edit_id = $product_id;
    }
  }
} elseif (!$variantHandled && $_SERVER['REQUEST_METHOD']==='POST') {
  if ($role !== 'superadmin' && $providerStatus !== 'active') $err="Cuenta pendiente de aprobación.";
  else {
    $title = trim((string)($_POST['title'] ?? ''));
    $price = (float)($_POST['base_price'] ?? 0);
    $sku = trim((string)($_POST['sku'] ?? ''));
    $universalCode = trim((string)($_POST['universal_code'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $categoryValue = $categoryId > 0 ? $categoryId : null;
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if (!$title || $price<=0) $err="Completá título y precio base.";
    elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
    elseif ($categoryValue !== null && empty($categoryIdSet[$categoryId])) $err = "Categoría inválida.";
    else {
      if ($product_id > 0) {
        $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
        $st->execute([$product_id,$providerId]);
        if ($st->fetch()) {
          $pdo->prepare("UPDATE provider_products SET title=?, sku=?, universal_code=?, description=?, base_price=?, category_id=? WHERE id=? AND provider_id=?")
              ->execute([$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$categoryValue,$product_id,$providerId]);
          $msg="Actualizado.";
          $edit_id = $product_id;
        } else {
          $err="Producto inválido.";
        }
      } else {
        $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,universal_code,description,base_price,category_id,status) VALUES(?,?,?,?,?,?,?,'active')")
            ->execute([$providerId,$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$categoryValue]);
        $msg="Creado.";
        $product_id = (int)$pdo->lastInsertId();
        $edit_id = $product_id;
      }
    }
  }

  if (empty($err) && $product_id > 0) {
    $upload_dir = __DIR__.'/../uploads/provider_products/'.$product_id;
    if (!is_dir($upload_dir)) {
      mkdir($upload_dir, 0775, true);
    }
    product_images_process_uploads($pdo, 'provider_product', $product_id, $_FILES['images'] ?? [], $upload_dir, $image_sizes, $max_image_size_bytes, $image_errors);
    product_images_apply_order($pdo, 'provider_product', $product_id, (string)($_POST['images_order'] ?? ''));
  }
}

if ($edit_id > 0) {
  $st = $pdo->prepare("SELECT id,title,sku,universal_code,description,base_price,category_id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
  $st->execute([$edit_id,$providerId]);
  $edit_product = $st->fetch();
  if (!$edit_product) {
    $err = $err ?? "Producto inválido.";
    $edit_id = 0;
  }
}

if ($edit_id > 0) {
  $product_images = product_images_fetch($pdo, 'provider_product', $edit_id);
}
$variantRows = [];
if ($edit_id > 0) {
  $variantSt = $pdo->prepare("
    SELECT pv.id, pv.color_id, pv.size_id, pv.sku_variant, pv.stock_qty, pv.image_cover, pv.position,
           c.name AS color_name, s.name AS size_name
    FROM product_variants pv
    JOIN colors c ON c.id = pv.color_id
    LEFT JOIN sizes s ON s.id = pv.size_id
    WHERE pv.owner_type='provider' AND pv.owner_id=? AND pv.product_id=?
    ORDER BY pv.position ASC, pv.id ASC
  ");
  $variantSt->execute([$providerId, $edit_id]);
  $variantRows = $variantSt->fetchAll();
}
if ($role === 'superadmin') {
  $colors = $pdo->query("SELECT id, name, codigo, active FROM colors ORDER BY name ASC, id ASC")->fetchAll();
  $sizes = $pdo->query("SELECT id, name, code, active FROM sizes ORDER BY position ASC, name ASC, id ASC")->fetchAll();
} else {
  $colors = $pdo->query("SELECT id, name, codigo, active FROM colors WHERE active=1 ORDER BY name ASC, id ASC")->fetchAll();
  $sizes = $pdo->query("SELECT id, name, code, active FROM sizes WHERE active=1 ORDER BY position ASC, name ASC, id ASC")->fetchAll();
}

$rows = $pdo->prepare("SELECT p.id, p.title, p.sku, p.universal_code, p.base_price, i.filename_base AS cover_image,
    CASE
      WHEN pv.variant_count > 0 THEN pv.variant_stock
      ELSE COALESCE(ws.qty_available,0)
    END AS qty_available,
    CASE
      WHEN pv.variant_count > 0 THEN 0
      ELSE COALESCE(ws.qty_reserved,0)
    END AS qty_reserved,
    COALESCE(SUM(oa.qty_allocated),0) AS qty_sold
  FROM provider_products p
  LEFT JOIN product_images i ON i.owner_type='provider_product' AND i.owner_id=p.id AND i.position=1
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id=p.id
  LEFT JOIN (
    SELECT product_id, owner_id, COUNT(*) AS variant_count, COALESCE(SUM(stock_qty),0) AS variant_stock
    FROM product_variants
    WHERE owner_type='provider'
    GROUP BY product_id, owner_id
  ) pv ON pv.product_id = p.id AND pv.owner_id = p.provider_id
  LEFT JOIN order_allocations oa ON oa.provider_product_id=p.id
  WHERE p.provider_id=?
  GROUP BY p.id, p.title, p.sku, p.universal_code, p.base_price, i.filename_base, ws.qty_available, ws.qty_reserved, pv.variant_count, pv.variant_stock
  ORDER BY p.id DESC");
$rows->execute([$providerId]);
$list = $rows->fetchAll();

$view = 'list';
if (($_GET['view'] ?? '') === 'new') {
  $view = 'new';
}
if ($edit_id > 0) {
  $view = 'edit';
}

page_header('Proveedor - Catálogo base');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
if (!empty($image_errors)) {
  echo "<p style='color:#b00'>".h(implode(' ', $image_errors))."</p>";
}
$providerQuery = $role === 'superadmin' ? '&provider_id='.h((string)$providerId) : '';
$providerQueryPrefix = $role === 'superadmin' ? '?provider_id='.h((string)$providerId) : '';
$newUrl = "/proveedor/catalogo.php?view=new".$providerQuery;
$listUrl = "/proveedor/catalogo.php".$providerQueryPrefix;
echo "<p><a href='".$newUrl."'>Nuevo</a> | <a href='".$listUrl."'>Listado</a></p>";
echo "<hr>";

if ($view === 'new' || $view === 'edit') {
echo "<form method='post' enctype='multipart/form-data'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' id='product_action' value='save_product'>
<input type='hidden' name='product_id' value='".h((string)($edit_product['id'] ?? ''))."'>
<input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
<input type='hidden' name='delete_image_id' id='delete_image_id' value=''>
<p>Título: <input name='title' style='width:520px' value='".h($edit_product['title'] ?? '')."'></p>
<p>SKU: <input name='sku' style='width:220px' value='".h($edit_product['sku'] ?? '')."'></p>
<p>Código universal (8-14 dígitos): <input name='universal_code' style='width:220px' value='".h($edit_product['universal_code'] ?? '')."'></p>
<p>Precio base: <input name='base_price' style='width:160px' value='".h((string)($edit_product['base_price'] ?? ''))."'></p>
<p>Categoría:
  <select name='category_id'>
    <option value='0'".(empty($edit_product['category_id']) ? ' selected' : '').">Sin categoría</option>";
foreach ($flatCategories as $cat) {
  $indent = str_repeat('— ', (int)$cat['depth']);
  $selected = ((int)($edit_product['category_id'] ?? 0) === (int)$cat['id']) ? ' selected' : '';
  echo "<option value='".h((string)$cat['id'])."'".$selected.">".$indent.h($cat['name'])."</option>";
}
echo "</select>
</p>
<p>Descripción:<br><textarea name='description' rows='3' style='width:90%'>".h($edit_product['description'] ?? '')."</textarea></p>
<fieldset>
<legend>Imágenes</legend>
<p><input type='file' name='images[]' multiple accept='image/*'></p>
<input type='hidden' name='images_order' id='images_order' value=''>
<ul id='images-list'>";
if ($edit_product && $product_images) {
  foreach ($product_images as $index => $image) {
    $thumb = product_image_with_size($image['filename_base'], 150);
    $thumb_url = "/uploads/provider_products/".h((string)$edit_product['id'])."/".h($thumb);
    $cover_label = $index === 0 ? "Portada" : "";
    echo "<li data-id='".h((string)$image['id'])."'>
<img src='".$thumb_url."' alt='' width='80' height='80'>
 <span class='cover-label'>".h($cover_label)."</span>
 <button type='button' class='move-up'>↑</button>
 <button type='button' class='move-down'>↓</button>
 <button type='button' class='img-delete' data-image-id='".h((string)$image['id'])."'>X</button>
</li>";
  }
} else {
  echo "<li>No hay imágenes cargadas.</li>";
}
echo "</ul>
</fieldset>
<button>".($edit_product ? "Guardar cambios" : "Crear")."</button>";
if ($edit_product) {
  echo " <a href='".$listUrl."'>Cancelar edición</a>";
}
echo "
</form>
<script>
(function() {
  var list = document.getElementById('images-list');
  var orderInput = document.getElementById('images_order');
  if (!list || !orderInput) return;

  function updateOrder() {
    var ids = [];
    var items = list.querySelectorAll('li[data-id]');
    items.forEach(function(item, index) {
      ids.push(item.getAttribute('data-id'));
      var label = item.querySelector('.cover-label');
      if (label) {
        label.textContent = index === 0 ? 'Portada' : '';
      }
    });
    orderInput.value = ids.join(',');
  }

  var deleteInput = document.getElementById('delete_image_id');
  var actionInput = document.getElementById('product_action');
  var form = list.closest('form');

  list.addEventListener('click', function(event) {
    if (event.target.classList.contains('img-delete')) {
      var imageId = event.target.getAttribute('data-image-id');
      if (!imageId) return;
      if (!confirm('¿Eliminar esta imagen?')) return;
      if (deleteInput) deleteInput.value = imageId;
      if (actionInput) actionInput.value = 'delete_image';
      if (form) form.submit();
      return;
    }
    if (event.target.classList.contains('move-up') || event.target.classList.contains('move-down')) {
      var item = event.target.closest('li');
      if (!item) return;
      if (event.target.classList.contains('move-up')) {
        var prev = item.previousElementSibling;
        if (prev && prev.hasAttribute('data-id')) {
          list.insertBefore(item, prev);
        }
      } else {
        var next = item.nextElementSibling;
        if (next) {
          list.insertBefore(next, item);
        }
      }
      updateOrder();
    }
  });

  updateOrder();
})();
</script>
<hr>";
}

if ($view === 'new' || $view === 'edit') {
echo "<fieldset>
<legend>Variantes (Color + Talle)</legend>";
if (!$variantRows) {
  echo "<p>Sin variantes.</p>
  <p><button type='button' id='variant-toggle'>Crear variante</button></p>
  <p>Si crea una variante el stock principal desaparecerá.</p>";
} else {
  echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>Color</th><th>Talle</th><th>SKU</th><th>Imagen</th><th>Acciones</th></tr>";
  foreach ($variantRows as $variant) {
    $skuVariant = $variant['sku_variant'] !== null && $variant['sku_variant'] !== '' ? $variant['sku_variant'] : '—';
    echo "<tr>
      <td>".h((string)$variant['color_name'])."</td>
      <td>".h((string)($variant['size_name'] ?? '—'))."</td>";
    $formId = "variant-update-".(int)$variant['id'];
    $imageCover = (string)($variant['image_cover'] ?? '');
    $imagePreview = '';
    if ($imageCover !== '') {
      $imagePreview = "<img src='".h($imageCover)."' alt='' width='50' height='50'> ";
    }
    $removeCheckbox = $imageCover !== '' ? "<label style='margin-left:8px;'><input type='checkbox' name='remove_variant_image' value='1' form='".h($formId)."'> Eliminar</label>" : '';
    echo "<td><input name='sku_variant' value='".h((string)($variant['sku_variant'] ?? ''))."' style='width:160px' form='".h($formId)."' required></td>
      <td>".$imagePreview."<input type='file' name='variant_image' accept='image/*' form='".h($formId)."'>".$removeCheckbox."</td>
      <td>
        <form method='post' enctype='multipart/form-data' id='".h($formId)."' style='margin:0; display:inline;'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='update_variant'>
          <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
          <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <button>Guardar</button>
        </form>
        <form method='post' style='margin:0; display:inline;' onsubmit='return confirm(\"¿Eliminar variante?\")'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='delete_variant'>
          <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
          <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <button>Eliminar</button>
        </form>
      </td>
    </tr>";
  }
  echo "</table>";
}
if ($role === 'superadmin' || $role === 'provider') {
  $variantFormStyle = $variantRows ? '' : " style='display:none;'";
  $variantFormId = 'variant-form';
  echo "<h4>Agregar variante</h4>
  <form method='post' enctype='multipart/form-data' id='".h($variantFormId)."'".$variantFormStyle.">
    <input type='hidden' name='csrf' value='".h(csrf_token())."'>
    <input type='hidden' name='action' value='add_variant'>
    <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
    <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
    <p>Color:
      <select name='color_id' id='variant-color-select'>
        <option value='0'>-- elegir --</option>";
  foreach ($colors as $color) {
    $colorLabel = $color['name'];
    if ($role === 'superadmin' && (int)$color['active'] !== 1) {
      $colorLabel .= " (inactivo)";
    }
    $colorCode = trim((string)($color['codigo'] ?? ''));
    $colorCodeAttr = $colorCode !== '' ? " data-code='".h($colorCode)."'" : "";
    echo "<option value='".h((string)$color['id'])."'".$colorCodeAttr.">".h($colorLabel)."</option>";
  }
  echo "</select></p>
    <p>Talle:
      <select name='size_id' id='variant-size-select'>
        <option value='0'>-- elegir --</option>";
  foreach ($sizes as $size) {
    $sizeLabel = $size['name'];
    if ($role === 'superadmin' && (int)$size['active'] !== 1) {
      $sizeLabel .= " (inactivo)";
    }
    $sizeCode = trim((string)($size['code'] ?? ''));
    $sizeCodeAttr = $sizeCode !== '' ? " data-code='".h($sizeCode)."'" : "";
    echo "<option value='".h((string)$size['id'])."'".$sizeCodeAttr.">".h($sizeLabel)."</option>";
  }
  echo "</select></p>
    <p>SKU: <input name='sku_variant' id='variant-sku-input' style='width:200px' required></p>
    <p>Imagen: <input type='file' name='variant_image' accept='image/*'></p>";
  echo "
    <button>Agregar</button>
  </form>";
}
echo "<script>
(function() {
  var toggle = document.getElementById('variant-toggle');
  var form = document.getElementById('variant-form');
  if (!toggle || !form) return;
  toggle.addEventListener('click', function() {
    form.style.display = '';
    toggle.style.display = 'none';
  });
})();
(function() {
  var form = document.getElementById('variant-form');
  if (!form) return;
  var colorSelect = form.querySelector('select[name=\"color_id\"]');
  var sizeSelect = form.querySelector('select[name=\"size_id\"]');
  var skuInput = form.querySelector('input[name=\"sku_variant\"]');
  var productSkuInput = document.querySelector('input[name=\"sku\"]');
  if (!colorSelect || !sizeSelect || !skuInput || !productSkuInput) return;

  function updateVariantSku() {
    var baseSku = productSkuInput.value.trim();
    var selectedOption = colorSelect.options[colorSelect.selectedIndex];
    var sizeOption = sizeSelect.options[sizeSelect.selectedIndex];
    if (!selectedOption || colorSelect.value === '0' || !sizeOption || sizeSelect.value === '0') {
      skuInput.value = '';
      return;
    }
    if (baseSku === '') {
      skuInput.value = '';
      return;
    }
    var colorCode = (selectedOption.getAttribute('data-code') || '').trim();
    var sizeCode = (sizeOption.getAttribute('data-code') || '').trim();
    if (colorCode === '' || sizeCode === '') {
      skuInput.value = '';
      alert('El color o el talle no tienen código');
      return;
    }
    skuInput.value = baseSku + '-' + colorCode + '-' + sizeCode;
  }

  colorSelect.addEventListener('change', updateVariantSku);
  sizeSelect.addEventListener('change', updateVariantSku);
})();
</script>
</fieldset>
<hr>";
}

if ($view === 'list') {
echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Imagen</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Base</th><th>Disp</th><th>Res</th><th>Ventas</th><th>Acciones</th></tr>";
foreach($list as $r){
  $url = "/proveedor/catalogo.php?id=".h((string)$r['id']).$providerQuery;
  $ventasUrl = "/proveedor/stock_ventas.php?id=".h((string)$r['id']);
  if (!empty($r['cover_image'])) {
    $thumb = product_image_with_size($r['cover_image'], 150);
    $thumb_url = "/uploads/provider_products/".h((string)$r['id'])."/".h($thumb);
    $image_cell = "<img src='".$thumb_url."' alt='' width='50' height='50'>";
  } else {
    $image_cell = "—";
  }
  echo "<tr><td>".$image_cell."</td><td><a href='".$url."'>".h($r['title'])."</a></td><td>".h($r['sku']??'')."</td><td>".h($r['universal_code']??'')."</td><td>".h((string)$r['base_price'])."</td><td>".h((string)$r['qty_available'])."</td><td>".h((string)$r['qty_reserved'])."</td><td>".h((string)$r['qty_sold'])."</td><td><a href='".$url."'>Modificar</a> | <a href='".$ventasUrl."'>Ventas</a></td></tr>";
}
echo "</table>";
}
page_footer();
