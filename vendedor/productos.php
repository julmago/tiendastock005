<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
require __DIR__.'/../lib/product_images.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');
$image_errors = [];

$storesSt = $pdo->prepare("SELECT id, name, slug, store_type, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC");
$storesSt->execute([(int)$seller['id']]);
$myStores = $storesSt->fetchAll();

$storeId = (int)($_GET['store_id'] ?? 0);
if (!$storeId && $myStores) $storeId = (int)$myStores[0]['id'];

$currentStore = null;
foreach($myStores as $ms){ if ((int)$ms['id'] === $storeId) $currentStore = $ms; }
if (!$currentStore) { page_header('Productos'); echo "<p>Primero creá una tienda.</p>"; page_footer(); exit; }

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

$variant_image_size = 600;

function variant_draft_relative_path(string $token, string $filename): string {
  return "/uploads/store_variant_drafts/".$token."/".$filename;
}

function variant_draft_disk_path(string $relativePath): string {
  return __DIR__.'/../'.ltrim($relativePath, '/');
}

function variant_draft_delete_image(?string $relativePath): void {
  if (!$relativePath) {
    return;
  }
  if (strpos($relativePath, '/uploads/store_variant_drafts/') !== 0) {
    return;
  }
  $diskPath = variant_draft_disk_path($relativePath);
  if (is_file($diskPath) && !unlink($diskPath)) {
    error_log("No se pudo borrar la imagen temporal {$diskPath}");
  }
}

function variant_draft_process_upload(array $file, string $token, int $maxImageSize, int $targetSize, array &$imageErrors): ?string {
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
  $uploadDir = __DIR__.'/../uploads/store_variant_drafts/'.$token;
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
  return variant_draft_relative_path($token, $baseName);
}

function variant_final_relative_path(int $variantId, string $filename): string {
  return "/uploads/store_variant_images/".$variantId."/".$filename;
}

function variant_draft_move_to_final(?string $relativePath, string $token, int $variantId, array &$imageErrors): ?string {
  if (!$relativePath) {
    return null;
  }
  $prefix = '/uploads/store_variant_drafts/'.$token.'/';
  if (strpos($relativePath, $prefix) !== 0) {
    return null;
  }
  $sourcePath = variant_draft_disk_path($relativePath);
  if (!is_file($sourcePath)) {
    $imageErrors[] = 'No se encontró la imagen de variante.';
    return null;
  }
  $filename = basename($relativePath);
  $targetDir = __DIR__.'/../uploads/store_variant_images/'.$variantId;
  if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
  }
  $targetPath = $targetDir.'/'.$filename;
  if (!rename($sourcePath, $targetPath)) {
    $imageErrors[] = 'No se pudo mover la imagen de variante.';
    return null;
  }
  return variant_final_relative_path($variantId, $filename);
}

function variant_draft_cleanup(string $token): void {
  $dir = __DIR__.'/../uploads/store_variant_drafts/'.$token;
  if (!is_dir($dir)) {
    return;
  }
  $files = glob($dir.'/*');
  if ($files) {
    foreach ($files as $file) {
      if (is_file($file)) {
        unlink($file);
      }
    }
  }
  @rmdir($dir);
}

function store_variant_delete_images(array $variants): void {
  foreach ($variants as $variant) {
    $imagePath = (string)($variant['image_cover'] ?? '');
    if ($imagePath === '') {
      continue;
    }
    if (strpos($imagePath, '/uploads/store_variant_images/') !== 0) {
      continue;
    }
    $diskPath = __DIR__.'/../'.ltrim($imagePath, '/');
    if (is_file($diskPath) && !unlink($diskPath)) {
      error_log("No se pudo borrar la imagen de variante {$diskPath}");
    }
    $dir = dirname($diskPath);
    $files = is_dir($dir) ? glob($dir.'/*') : [];
    if ($files === [] && is_dir($dir)) {
      @rmdir($dir);
    }
  }
}

function store_product_delete_images(int $productId, array $imageBases, array $imageSizes): void {
  if (!$imageBases) {
    return;
  }
  $uploadDir = __DIR__.'/../uploads/store_products/'.$productId;
  foreach ($imageBases as $baseName) {
    if ($baseName === '') {
      continue;
    }
    foreach ($imageSizes as $size) {
      $filePath = $uploadDir.'/'.product_image_with_size($baseName, $size);
      if (is_file($filePath) && !unlink($filePath)) {
        error_log("No se pudo borrar el archivo {$filePath}");
      }
    }
    $originalPath = $uploadDir.'/'.$baseName;
    if (is_file($originalPath) && !unlink($originalPath)) {
      error_log("No se pudo borrar el archivo {$originalPath}");
    }
  }
  $files = is_dir($uploadDir) ? glob($uploadDir.'/*') : [];
  if ($files === [] && is_dir($uploadDir)) {
    @rmdir($uploadDir);
  }
}

function reset_variant_drafts(int $storeId): void {
  $existing = $_SESSION['store_variant_drafts'][$storeId] ?? null;
  $existingToken = is_array($existing) ? (string)($existing['token'] ?? '') : '';
  if ($existingToken !== '') {
    variant_draft_cleanup($existingToken);
  }
  unset($_SESSION['store_variant_drafts'][$storeId]);
}

function variant_copy_from_provider(?string $relativePath, int $variantId, array &$imageErrors): ?string {
  if (!$relativePath) {
    return null;
  }
  if (strpos($relativePath, '/uploads/provider_variant_images/') !== 0) {
    return null;
  }
  $sourcePath = __DIR__.'/../'.ltrim($relativePath, '/');
  if (!is_file($sourcePath)) {
    return null;
  }
  $filename = basename($relativePath);
  $targetDir = __DIR__.'/../uploads/store_variant_images/'.$variantId;
  if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
  }
  $targetPath = $targetDir.'/'.$filename;
  if (!copy($sourcePath, $targetPath)) {
    $imageErrors[] = 'No se pudo copiar la imagen de variante.';
    return null;
  }
  return variant_final_relative_path($variantId, $filename);
}

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'new'], true)) $action = 'list';
$listUrl = "productos.php?action=list&store_id=".h((string)$storeId);
$newUrl = "productos.php?action=new&store_id=".h((string)$storeId);

$colors = [];
$sizes = [];
$variantDrafts = [];
$variantDraftToken = '';
$variantDraftState = null;
if ($action === 'new' || ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add_variant_draft', 'delete_variant_draft', 'create'], true))) {
  $colors = $pdo->query("SELECT id, name, codigo FROM colors WHERE active=1 ORDER BY name ASC, id ASC")->fetchAll();
  $sizes = $pdo->query("SELECT id, name, code FROM sizes WHERE active=1 ORDER BY position ASC, name ASC, id ASC")->fetchAll();
  $variantDraftState = $_SESSION['store_variant_drafts'][$storeId] ?? [];
  $variantDraftToken = (string)($variantDraftState['token'] ?? '');
  if ($variantDraftToken === '') {
    $variantDraftToken = bin2hex(random_bytes(8));
    $variantDraftState = ['token' => $variantDraftToken, 'variants' => []];
    $_SESSION['store_variant_drafts'][$storeId] = $variantDraftState;
  }
  $variantDrafts = $variantDraftState['variants'] ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_product') {
  $action = 'list';
  $productId = (int)($_POST['product_id'] ?? 0);
  if ($productId <= 0) {
    $err = "Producto inválido.";
  } else {
    $productSt = $pdo->prepare("SELECT id, status FROM store_products WHERE id=? AND store_id=? LIMIT 1");
    $productSt->execute([$productId, $storeId]);
    $product = $productSt->fetch();
    if (!$product) {
      $err = "Producto inválido.";
    } else {
      $variantSt = $pdo->prepare("SELECT id, image_cover FROM product_variants WHERE owner_type='vendor' AND owner_id=? AND product_id=?");
      $variantSt->execute([$storeId, $productId]);
      $variants = $variantSt->fetchAll();
      $variantIds = array_map('intval', array_column($variants, 'id'));
      $imageSt = $pdo->prepare("SELECT filename_base FROM product_images WHERE owner_type='store_product' AND owner_id=?");
      $imageSt->execute([$productId]);
      $productImages = $imageSt->fetchAll(PDO::FETCH_COLUMN);
      $orderItemSt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE store_product_id=?");
      $orderItemSt->execute([$productId]);
      $orderItems = (int)$orderItemSt->fetchColumn();
      $pdo->beginTransaction();
      try {
        if (!empty($variantIds)) {
          $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
          $pdo->prepare("DELETE FROM store_variant_sources WHERE variant_id IN ({$placeholders})")
              ->execute($variantIds);
        }
        $pdo->prepare("DELETE FROM product_variants WHERE owner_type='vendor' AND owner_id=? AND product_id=?")
            ->execute([$storeId, $productId]);
        $pdo->prepare("DELETE FROM product_images WHERE owner_type='store_product' AND owner_id=?")
            ->execute([$productId]);
        $pdo->prepare("DELETE FROM store_product_sources WHERE store_product_id=?")
            ->execute([$productId]);
        if ($orderItems > 0) {
          $pdo->prepare("UPDATE store_products SET status='inactive' WHERE id=? AND store_id=?")
              ->execute([$productId, $storeId]);
        } else {
          $pdo->prepare("DELETE FROM store_products WHERE id=? AND store_id=?")
              ->execute([$productId, $storeId]);
        }
        $pdo->commit();
        store_variant_delete_images($variants);
        store_product_delete_images($productId, $productImages, $image_sizes);
        if ($orderItems > 0) {
          $msg = "Producto archivado porque tiene pedidos asociados.";
        } else {
          $msg = "Producto eliminado.";
        }
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log(sprintf('[delete_product] store_id=%d product_id=%d error=%s', $storeId, $productId, $e->getMessage()));
        $err = "No se pudo eliminar el producto: ".$e->getMessage();
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add_variant_draft', 'delete_variant_draft'], true)) {
  $action = 'new';
  $variantAction = $_POST['action'] ?? '';
  $colorsById = [];
  foreach ($colors as $color) {
    $colorsById[(int)$color['id']] = $color;
  }
  $sizesById = [];
  foreach ($sizes as $size) {
    $sizesById[(int)$size['id']] = $size;
  }

  if ($variantAction === 'add_variant_draft') {
    $colorInput = (int)($_POST['color_id'] ?? 0);
    $sizeInput = (int)($_POST['size_id'] ?? 0);
    $colorId = $colorInput > 0 ? $colorInput : null;
    $sizeId = $sizeInput > 0 ? $sizeInput : null;
    $skuVariant = trim((string)($_POST['sku_variant'] ?? ''));
    $productSku = trim((string)($_POST['product_sku'] ?? ''));
    $universalCode = trim((string)($_POST['universal_code'] ?? ''));
    if ($colorId === null && $sizeId === null) {
      $err = "Elegí un color y/o un talle.";
    }
    if (empty($err) && $colorId !== null && empty($colorsById[$colorId])) {
      $err = "Color inválido.";
    }
    if (empty($err) && $sizeId !== null && empty($sizesById[$sizeId])) {
      $err = "Talle inválido.";
    }
    if (empty($err) && $skuVariant === '' && $productSku !== '') {
      $pieces = [];
      if ($colorId !== null) {
        $colorCode = trim((string)($colorsById[$colorId]['codigo'] ?? ''));
        $pieces[] = $colorCode !== '' ? $colorCode : 'C'.$colorId;
      }
      if ($sizeId !== null) {
        $sizeCode = trim((string)($sizesById[$sizeId]['code'] ?? ''));
        $pieces[] = $sizeCode !== '' ? $sizeCode : 'T'.$sizeId;
      }
      if ($pieces) {
        $skuVariant = $productSku.'-'.implode('-', $pieces);
      }
    }
    if (empty($err) && $skuVariant === '') {
      $err = "SKU de variante obligatorio.";
    }
    if (empty($err) && $universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) {
      $err = "El código universal de la variante debe tener entre 8 y 14 números.";
    }
    if (empty($err)) {
      foreach ($variantDrafts as $draft) {
        $draftColor = $draft['color_id'] ?? null;
        $draftSize = $draft['size_id'] ?? null;
        if ($draftColor === $colorId && $draftSize === $sizeId) {
          $err = "Ya existe una variante para esa combinación.";
          break;
        }
      }
    }
    if (empty($err)) {
      $imagePath = null;
      if (!empty($_FILES['variant_image'])) {
        $imagePath = variant_draft_process_upload($_FILES['variant_image'], $variantDraftToken, $max_image_size_bytes, $variant_image_size, $image_errors);
      }
      $variantDrafts[] = [
        'id' => bin2hex(random_bytes(8)),
        'color_id' => $colorId,
        'size_id' => $sizeId,
        'sku_variant' => $skuVariant,
        'universal_code' => $universalCode !== '' ? $universalCode : null,
        'image_path' => $imagePath,
      ];
      $_SESSION['store_variant_drafts'][$storeId]['variants'] = $variantDrafts;
      $msg = "Variante agregada.";
    }
  }

  if ($variantAction === 'delete_variant_draft') {
    $draftId = (string)($_POST['draft_id'] ?? '');
    if ($draftId === '') {
      $err = "Variante inválida.";
    } else {
      $found = false;
      $nextDrafts = [];
      foreach ($variantDrafts as $draft) {
        if (($draft['id'] ?? '') === $draftId) {
          $found = true;
          if (!empty($draft['image_path'])) {
            variant_draft_delete_image((string)$draft['image_path']);
          }
          continue;
        }
        $nextDrafts[] = $draft;
      }
      if (!$found) {
        $err = "Variante inválida.";
      } else {
        $variantDrafts = $nextDrafts;
        $_SESSION['store_variant_drafts'][$storeId]['variants'] = $variantDrafts;
        $msg = "Variante eliminada.";
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_provider_all') {
  $providerProductId = (int)($_POST['provider_product_id'] ?? 0);
  if ($providerProductId <= 0) {
    $err = "Producto inválido.";
  } else {
    $providerSt = $pdo->prepare("SELECT id, provider_id, title, sku, universal_code, base_price, description, category_id FROM provider_products WHERE id=? AND status='active' LIMIT 1");
    $providerSt->execute([$providerProductId]);
    $providerProduct = $providerSt->fetch();
    if (!$providerProduct) {
      $err = "Producto inválido.";
    } else {
      $providerVariantsSt = $pdo->prepare("
        SELECT pv.id, pv.color_id, pv.size_id, pv.sku_variant, pv.universal_code, pv.image_cover
        FROM product_variants pv
        WHERE pv.owner_type='provider' AND pv.owner_id=? AND pv.product_id=?
        ORDER BY pv.position ASC, pv.id ASC
      ");
      $providerVariantsSt->execute([(int)$providerProduct['provider_id'], $providerProductId]);
      $providerVariants = $providerVariantsSt->fetchAll();
      if (!$providerVariants) {
        $err = "El producto no tiene variantes.";
      } else {
        $imageCopySt = $pdo->prepare("SELECT filename_base FROM product_images WHERE owner_type='provider_product' AND owner_id=? ORDER BY position ASC");
        $imageCopySt->execute([$providerProductId]);
        $providerImages = $imageCopySt->fetchAll(PDO::FETCH_COLUMN);
        $imagesToCopy = [];
        foreach ($providerImages as $baseName) {
          if ($baseName !== '') {
            $imagesToCopy[] = ['filename_base' => $baseName];
          }
        }

        reset_variant_drafts($storeId);
        $variantDraftToken = bin2hex(random_bytes(8));
        $variantDrafts = [];
        foreach ($providerVariants as $variant) {
          $variantDrafts[] = [
            'id' => bin2hex(random_bytes(8)),
            'color_id' => $variant['color_id'] !== null ? (int)$variant['color_id'] : null,
            'size_id' => $variant['size_id'] !== null ? (int)$variant['size_id'] : null,
            'sku_variant' => $variant['sku_variant'] ?: null,
            'universal_code' => $variant['universal_code'] ?: null,
            'image_path' => null,
            'provider_image_path' => $variant['image_cover'] ?? null,
          ];
        }
        $_SESSION['store_variant_drafts'][$storeId] = [
          'token' => $variantDraftToken,
          'variants' => $variantDrafts,
        ];

        $_SESSION['store_product_drafts'][$storeId] = [
          'title' => $providerProduct['title'] ?? '',
          'sku' => $providerProduct['sku'] ?? '',
          'universal_code' => $providerProduct['universal_code'] ?? '',
          'description' => $providerProduct['description'] ?? '',
          'category_id' => $providerProduct['category_id'] ? (int)$providerProduct['category_id'] : 0,
          'provider_product_id' => $providerProductId,
          'copy_images' => $imagesToCopy,
          'copied' => true,
        ];

        header("Location: productos.php?action=new&store_id=".$storeId."&copied=1");
        exit;
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_provider_variant') {
  $providerProductId = (int)($_POST['provider_product_id'] ?? 0);
  $providerVariantId = (int)($_POST['provider_variant_id'] ?? 0);
  if ($providerProductId <= 0 || $providerVariantId <= 0) {
    $err = "Variante inválida.";
  } else {
    $variantSt = $pdo->prepare("
      SELECT pp.id, pp.provider_id, pp.title, pp.description, pp.category_id,
             pv.color_id, pv.size_id, pv.sku_variant, pv.universal_code, pv.image_cover
      FROM provider_products pp
      JOIN product_variants pv
        ON pv.product_id = pp.id
       AND pv.owner_type = 'provider'
       AND pv.owner_id = pp.provider_id
      WHERE pp.id=? AND pv.id=? AND pp.status='active'
      LIMIT 1
    ");
    $variantSt->execute([$providerProductId, $providerVariantId]);
    $providerVariant = $variantSt->fetch();
    if (!$providerVariant) {
      $err = "Variante inválida.";
    } else {
      $imageCopySt = $pdo->prepare("SELECT filename_base FROM product_images WHERE owner_type='provider_product' AND owner_id=? ORDER BY position ASC");
      $imageCopySt->execute([$providerProductId]);
      $providerImages = $imageCopySt->fetchAll(PDO::FETCH_COLUMN);
      $imagesToCopy = [];
      foreach ($providerImages as $baseName) {
        if ($baseName !== '') {
          $imagesToCopy[] = ['filename_base' => $baseName];
        }
      }

      reset_variant_drafts($storeId);
      $variantDraftToken = bin2hex(random_bytes(8));
      $variantDrafts = [[
        'id' => bin2hex(random_bytes(8)),
        'color_id' => $providerVariant['color_id'] !== null ? (int)$providerVariant['color_id'] : null,
        'size_id' => $providerVariant['size_id'] !== null ? (int)$providerVariant['size_id'] : null,
        'sku_variant' => $providerVariant['sku_variant'] ?: null,
        'universal_code' => $providerVariant['universal_code'] ?: null,
        'image_path' => null,
        'provider_image_path' => $providerVariant['image_cover'] ?? null,
      ]];
      $_SESSION['store_variant_drafts'][$storeId] = [
        'token' => $variantDraftToken,
        'variants' => $variantDrafts,
      ];

      $_SESSION['store_product_drafts'][$storeId] = [
        'title' => $providerVariant['title'] ?? '',
        'sku' => $providerVariant['sku_variant'] ?? '',
        'universal_code' => $providerVariant['universal_code'] ?? '',
        'description' => $providerVariant['description'] ?? '',
        'category_id' => $providerVariant['category_id'] ? (int)$providerVariant['category_id'] : 0,
        'provider_product_id' => $providerProductId,
        'copy_images' => $imagesToCopy,
        'copied' => true,
      ];

      header("Location: productos.php?action=new&store_id=".$storeId."&copied=1");
      exit;
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'create') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  $categoryId = (int)($_POST['category_id'] ?? 0);
  $categoryValue = $categoryId > 0 ? $categoryId : null;
  if (!$title) $err="Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  elseif ($categoryValue !== null && empty($categoryIdSet[$categoryId])) $err = "Categoría inválida.";
  else {
    $pdo->prepare("INSERT INTO store_products(store_id,title,sku,universal_code,description,category_id,status,own_stock_qty,own_stock_price,manual_price)
                   VALUES(?,?,?,?,?,?,'active',0,NULL,NULL)")
        ->execute([$storeId,$title,$sku?:null,$universalCode?:null,($_POST['description']??'')?:null,$categoryValue]);
    $productId = (int)$pdo->lastInsertId();
    $upload_dir = __DIR__.'/../uploads/store_products/'.$productId;
    if (!is_dir($upload_dir)) {
      mkdir($upload_dir, 0775, true);
    }

    $copy_payload_raw = trim((string)($_POST['copy_images_payload'] ?? ''));
    $copy_payload = $copy_payload_raw !== '' ? json_decode($copy_payload_raw, true) : [];
    if (!is_array($copy_payload)) {
      $copy_payload = [];
    }
    $copy_source_id = (int)($copy_payload['product_id'] ?? 0);
    $copy_images = is_array($copy_payload['images'] ?? null) ? $copy_payload['images'] : [];
    $copy_provider_id = 0;
    if ($copy_source_id > 0) {
      $providerIdSt = $pdo->prepare("SELECT provider_id FROM provider_products WHERE id=?");
      $providerIdSt->execute([$copy_source_id]);
      $copy_provider_id = (int)($providerIdSt->fetchColumn() ?? 0);
    }

    $images_order_raw = trim((string)($_POST['images_order'] ?? ''));
    $order_tokens = $images_order_raw !== '' ? array_filter(array_map('trim', explode(',', $images_order_raw))) : [];
    $next_position = 0;
    $processed_uploads = [];
    $processed_copy = [];

    $valid_copy_images = [];
    if ($copy_source_id > 0 && $copy_images) {
      $st = $pdo->prepare("SELECT filename_base FROM product_images WHERE owner_type=? AND owner_id=?");
      $st->execute(['provider_product', $copy_source_id]);
      $existing_bases = $st->fetchAll(PDO::FETCH_COLUMN);
      $existing_bases = array_fill_keys($existing_bases, true);
      foreach ($copy_images as $image) {
        $base_name = $image['filename_base'] ?? '';
        if ($base_name !== '' && isset($existing_bases[$base_name])) {
          $valid_copy_images[] = ['filename_base' => $base_name];
        }
      }
    }

    if ($copy_source_id > 0 && $copy_provider_id > 0) {
      // Variantes desde proveedor se omiten en esta etapa.
    }

    foreach ($order_tokens as $token) {
      if (strpos($token, 'upload:') === 0) {
        $index = (int)substr($token, 7);
        if (isset($processed_uploads[$index])) {
          continue;
        }
        product_images_process_uploads($pdo, 'store_product', $productId, $_FILES['images'] ?? [], $upload_dir, $image_sizes, $max_image_size_bytes, $image_errors, [$index], $next_position, false);
        $processed_uploads[$index] = true;
        continue;
      }
      if (strpos($token, 'copy:') === 0) {
        $base_name = substr($token, 5);
        if ($base_name === '' || isset($processed_copy[$base_name])) {
          continue;
        }
        $candidate = [];
        foreach ($valid_copy_images as $image) {
          if (($image['filename_base'] ?? '') === $base_name) {
            $candidate[] = $image;
            break;
          }
        }
        if ($copy_source_id > 0 && $candidate) {
          $source_dir = __DIR__.'/../uploads/provider_products/'.$copy_source_id;
          $target_dir = __DIR__.'/../uploads/store_products/'.$productId;
          product_images_copy_from_provider($pdo, $copy_source_id, $productId, $candidate, $source_dir, $target_dir, $image_sizes, $image_errors, $next_position);
          $processed_copy[$base_name] = true;
        }
      }
    }

    $remaining_copy = [];
    foreach ($valid_copy_images as $image) {
      $base_name = $image['filename_base'] ?? '';
      if ($base_name !== '' && !isset($processed_copy[$base_name])) {
        $remaining_copy[] = $image;
      }
    }
    if ($copy_source_id > 0 && $remaining_copy) {
      $source_dir = __DIR__.'/../uploads/provider_products/'.$copy_source_id;
      $target_dir = __DIR__.'/../uploads/store_products/'.$productId;
      product_images_copy_from_provider($pdo, $copy_source_id, $productId, $remaining_copy, $source_dir, $target_dir, $image_sizes, $image_errors, $next_position);
    }

    $upload_files = $_FILES['images'] ?? [];
    $upload_indices = product_images_sort_indices($upload_files, null);
    $remaining_upload_indices = [];
    foreach ($upload_indices as $index) {
      if (!isset($processed_uploads[$index])) {
        $remaining_upload_indices[] = $index;
      }
    }
    if ($remaining_upload_indices) {
      product_images_process_uploads($pdo, 'store_product', $productId, $upload_files, $upload_dir, $image_sizes, $max_image_size_bytes, $image_errors, $remaining_upload_indices, $next_position, false);
    }

    if ($variantDrafts) {
      $insertVariant = $pdo->prepare("
        INSERT INTO product_variants(owner_type, owner_id, product_id, color_id, size_id, sku_variant, universal_code, stock_qty, image_cover, position)
        VALUES('vendor', ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $position = 0;
      foreach ($variantDrafts as $draft) {
        $position++;
        $colorId = isset($draft['color_id']) && $draft['color_id'] !== null ? (int)$draft['color_id'] : null;
        $sizeId = isset($draft['size_id']) && $draft['size_id'] !== null ? (int)$draft['size_id'] : null;
        $skuVariant = trim((string)($draft['sku_variant'] ?? ''));
        $universalCode = trim((string)($draft['universal_code'] ?? ''));
        $insertVariant->execute([
          $storeId,
          $productId,
          $colorId,
          $sizeId,
          $skuVariant ?: null,
          $universalCode !== '' ? $universalCode : null,
          0,
          null,
          $position,
        ]);
        $variantId = (int)$pdo->lastInsertId();
        $imagePath = (string)($draft['image_path'] ?? '');
        $providerImagePath = (string)($draft['provider_image_path'] ?? '');
        if ($imagePath !== '') {
          $finalPath = variant_draft_move_to_final($imagePath, $variantDraftToken, $variantId, $image_errors);
          if ($finalPath !== null) {
            $pdo->prepare("UPDATE product_variants SET image_cover=? WHERE id=? AND owner_type='vendor' AND owner_id=? AND product_id=?")
                ->execute([$finalPath, $variantId, $storeId, $productId]);
          }
        } elseif ($providerImagePath !== '') {
          $finalPath = variant_copy_from_provider($providerImagePath, $variantId, $image_errors);
          if ($finalPath !== null) {
            $pdo->prepare("UPDATE product_variants SET image_cover=? WHERE id=? AND owner_type='vendor' AND owner_id=? AND product_id=?")
                ->execute([$finalPath, $variantId, $storeId, $productId]);
          }
        }
      }
      unset($_SESSION['store_variant_drafts'][$storeId]);
      if ($variantDraftToken !== '') {
        variant_draft_cleanup($variantDraftToken);
      }
    }

    $msg="Producto creado.";
    if (empty($image_errors)) {
      unset($_SESSION['store_product_drafts'][$storeId]);
      header("Location: producto.php?id=".$productId."&store_id=".$storeId."&created=1");
      exit;
    }
  }
}

page_header('Vendedor - Productos');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
if (!empty($image_errors)) {
  echo "<p style='color:#b00'>".h(implode(' ', $image_errors))."</p>";
}

echo "<div style='display:flex; align-items:center; justify-content:flex-end; gap:12px;'>
  <div><a href='".$newUrl."'>Nuevo</a> | <a href='".$listUrl."'>Listado</a></div>
</div>";

if ($action === 'list') {
  echo "<hr>";

  $stp = $pdo->prepare("SELECT * FROM store_products WHERE store_id=? AND status='active' ORDER BY id DESC");
  $stp->execute([$storeId]);
  $storeProducts = $stp->fetchAll();

  echo "<h3>Listado</h3>";
  if (!$storeProducts) { echo "<p>Sin productos.</p>"; page_footer(); exit; }

  $productIds = array_map('intval', array_column($storeProducts, 'id'));
  $coverImages = [];
  if ($productIds) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stCover = $pdo->prepare("SELECT owner_id, filename_base FROM product_images WHERE owner_type='store_product' AND is_cover=1 AND owner_id IN ($placeholders)");
    $stCover->execute($productIds);
    foreach ($stCover->fetchAll() as $row) {
      $coverImages[(int)$row['owner_id']] = $row['filename_base'];
    }
  }

  echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>Imagen</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock prov</th><th>Own qty</th><th>Own $</th><th>Manual $</th><th>Precio actual</th><th>Acciones</th></tr>";
  foreach($storeProducts as $sp){
    $provStock = provider_stock_sum($pdo, (int)$sp['id']);
    $sell = current_sell_price($pdo, $currentStore, $sp);
    $stockTotal = store_product_stock_total($pdo, $storeId, $sp);
    $sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';

    $editUrl = "producto.php?id=".h((string)$sp['id'])."&store_id=".h((string)$storeId);
    $coverBase = $coverImages[(int)$sp['id']] ?? '';
    if ($coverBase !== '') {
      $thumb = product_image_with_size($coverBase, 150);
      $thumbUrl = "/uploads/store_products/".h((string)$sp['id'])."/".h($thumb);
      $coverCell = "<img src='".$thumbUrl."' alt='' width='50' height='50' style='object-fit:cover;'>";
    } else {
      $coverCell = "—";
    }
    $deleteForm = "<form method='post' action='".$listUrl."' style='margin:0;' onsubmit='return confirm(\"¿Eliminar producto?\")'>
      <input type='hidden' name='action' value='delete_product'>
      <input type='hidden' name='product_id' value='".h((string)$sp['id'])."'>
      <input type='hidden' name='csrf' value='".h(csrf_token())."'>
      <button type='submit'>Eliminar</button>
    </form>";
    echo "<tr>
      <td>".$coverCell."</td>
      <td><a href='".$editUrl."'>".h($sp['title'])."</a></td>
      <td>".h($sp['sku']??'')."</td>
      <td>".h($sp['universal_code']??'')."</td>
      <td>".h((string)$provStock)."</td>
      <td>".h((string)$sp['own_stock_qty'])."</td>
      <td>".h((string)($sp['own_stock_price']??''))."</td>
      <td>".h((string)($sp['manual_price']??''))."</td>
      <td>".h($sellTxt)." (total: ".h((string)$stockTotal).")</td>
      <td>".$deleteForm."</td>
    </tr>";
  }
  echo "</table>";
}

if ($action === 'new') {
  $draftData = $_SESSION['store_product_drafts'][$storeId] ?? [];
  $formValues = [
    'title' => (string)($_POST['title'] ?? ($draftData['title'] ?? '')),
    'sku' => (string)($_POST['sku'] ?? ($draftData['sku'] ?? '')),
    'universal_code' => (string)($_POST['universal_code'] ?? ($draftData['universal_code'] ?? '')),
    'description' => (string)($_POST['description'] ?? ($draftData['description'] ?? '')),
    'category_id' => (int)($_POST['category_id'] ?? ($draftData['category_id'] ?? 0)),
  ];
  $copyPayloadValue = (string)($_POST['copy_images_payload'] ?? '');
  if ($copyPayloadValue === '' && !empty($draftData['provider_product_id'])) {
    $copyPayloadValue = json_encode([
      'product_id' => (int)$draftData['provider_product_id'],
      'images' => $draftData['copy_images'] ?? [],
    ]);
  }
  $copyPayloadDecoded = $copyPayloadValue !== '' ? json_decode($copyPayloadValue, true) : [];
  if (!is_array($copyPayloadDecoded)) {
    $copyPayloadDecoded = [];
  }
  $copyPayloadProductId = (int)($copyPayloadDecoded['product_id'] ?? 0);
  $copyPayloadImages = is_array($copyPayloadDecoded['images'] ?? null) ? $copyPayloadDecoded['images'] : [];
  $imagesOrderValue = (string)($_POST['images_order'] ?? '');
  if ($imagesOrderValue === '' && $copyPayloadImages) {
    $tokens = [];
    foreach ($copyPayloadImages as $image) {
      $baseName = $image['filename_base'] ?? '';
      if ($baseName !== '') {
        $tokens[] = 'copy:'.$baseName;
      }
    }
    $imagesOrderValue = implode(',', $tokens);
  }
  $showCopyMessage = !empty($draftData['copied']) || !empty($_GET['copied']);

  $providerQuery = trim((string)($_GET['provider_q'] ?? ''));
  $providerProducts = [];
  if ($providerQuery !== '') {
    $like = "%{$providerQuery}%";
    $searchSt = $pdo->prepare("
      SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, pp.description, pp.category_id, p.display_name AS provider_name,
             COALESCE(pv.variant_count,0) AS variant_count,
             COALESCE(SUM(
               CASE
                 WHEN pv.variant_count > 0 THEN pv.variant_stock
                 ELSE GREATEST(ws.qty_available - ws.qty_reserved,0)
               END
             ),0) AS stock
      FROM provider_products pp
      JOIN providers p ON p.id=pp.provider_id
      LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
      LEFT JOIN (
        SELECT product_id, owner_id, COUNT(*) AS variant_count, COALESCE(SUM(stock_qty),0) AS variant_stock
        FROM product_variants
        WHERE owner_type='provider'
        GROUP BY product_id, owner_id
      ) pv ON pv.product_id = pp.id AND pv.owner_id = pp.provider_id
      WHERE pp.status='active' AND p.status='active'
        AND (pp.title LIKE ? OR pp.sku LIKE ? OR pp.universal_code LIKE ?)
      GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, pp.description, pp.category_id, p.display_name, pv.variant_count
      HAVING stock > 0
      ORDER BY pp.id DESC
      LIMIT 20
    ");
    $searchSt->execute([$like, $like, $like]);
    $providerProducts = $searchSt->fetchAll();
  }
  $providerProductImages = [];
  if ($providerProducts) {
    $providerIds = array_map('intval', array_column($providerProducts, 'id'));
    $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
    $imgSt = $pdo->prepare("SELECT owner_id, filename_base, position FROM product_images WHERE owner_type='provider_product' AND owner_id IN ($placeholders) ORDER BY position ASC");
    $imgSt->execute($providerIds);
    $rows = $imgSt->fetchAll();
    foreach ($rows as $row) {
      $pid = (int)$row['owner_id'];
      if (!isset($providerProductImages[$pid])) {
        $providerProductImages[$pid] = [];
      }
      $providerProductImages[$pid][] = [
        'filename_base' => $row['filename_base'] ?? ''
      ];
    }
  }
  $providerVariantsByProduct = [];
  if ($providerProducts) {
    $providerIds = array_map('intval', array_column($providerProducts, 'id'));
    $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
    $variantSt = $pdo->prepare("
      SELECT pv.id, pv.product_id, pv.color_id, pv.size_id, pv.sku_variant, pv.universal_code, pv.stock_qty, pv.own_stock_price, pv.manual_price,
             c.name AS color_name, s.name AS size_name
      FROM product_variants pv
      JOIN provider_products pp ON pp.id = pv.product_id AND pv.owner_type='provider' AND pv.owner_id = pp.provider_id
      LEFT JOIN colors c ON c.id = pv.color_id
      LEFT JOIN sizes s ON s.id = pv.size_id
      WHERE pp.id IN ($placeholders)
      ORDER BY pv.position ASC, pv.id ASC
    ");
    $variantSt->execute($providerIds);
    foreach ($variantSt->fetchAll() as $variant) {
      $productId = (int)$variant['product_id'];
      if (!isset($providerVariantsByProduct[$productId])) {
        $providerVariantsByProduct[$productId] = [];
      }
      $providerVariantsByProduct[$productId][] = $variant;
    }
  }

  echo "<h3>Crear desde cero</h3>
  <form method='post' id='create-form' enctype='multipart/form-data'>
  <input type='hidden' name='csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='action' value='create'>
  <input type='hidden' name='images_order' id='images_order' value='".h($imagesOrderValue)."'>
  <input type='hidden' name='copy_images_payload' id='copy_images_payload' value='".h($copyPayloadValue)."'>
  <p>Título: <input id='create-title' name='title' style='width:520px' value='".h($formValues['title'])."'></p>
  <p>SKU: <input id='create-sku' name='sku' style='width:220px' value='".h($formValues['sku'])."'></p>
  <p>Código universal (8-14 dígitos): <input id='create-universal' name='universal_code' style='width:220px' value='".h($formValues['universal_code'])."'></p>
  <p>Categoría:
    <select id='create-category' name='category_id'>
      <option value='0'>Sin categoría</option>";
foreach ($flatCategories as $cat) {
  $indent = str_repeat('— ', (int)$cat['depth']);
  $selected = (int)$cat['id'] === $formValues['category_id'] ? " selected" : "";
  echo "<option value='".h((string)$cat['id'])."'".$selected.">".$indent.h($cat['name'])."</option>";
}
echo "</select>
  </p>
  <p>Descripción:<br><textarea id='create-description' name='description' rows='3' style='width:90%'>".h($formValues['description'])."</textarea></p>
  <fieldset>
  <legend>Imágenes</legend>
  <p><input type='file' name='images[]' id='images-input' multiple accept='image/*'></p>
  <ul id='images-list'>
    ".($copyPayloadImages ? '' : '<li>No hay imágenes cargadas.</li>');
  if ($copyPayloadImages && $copyPayloadProductId > 0) {
    foreach ($copyPayloadImages as $image) {
      $baseName = $image['filename_base'] ?? '';
      if ($baseName === '') {
        continue;
      }
      $thumb = preg_replace('/(\\.[^.]+)$/', '_150$1', $baseName);
      $thumbUrl = "/uploads/provider_products/".h((string)$copyPayloadProductId)."/".h($thumb);
      echo "<li data-kind='copy' data-key='".h($baseName)."'>
        <img src='".$thumbUrl."' alt='' width='80' height='80'> <span class='cover-label'></span>
        <button type='button' class='move-up'>↑</button>
        <button type='button' class='move-down'>↓</button>
      </li>";
    }
  }
  echo "
  </ul>
  </fieldset>
  <p id='copy-message' style='color:green; ".($showCopyMessage ? "" : "display:none;")."'>Datos cargados desde proveedor. Revisá y presioná Crear para publicar.</p>
  <button>Crear</button>
  </form><hr>";

  $colorsById = [];
  foreach ($colors as $color) {
    $colorsById[(int)$color['id']] = $color;
  }
  $sizesById = [];
  foreach ($sizes as $size) {
    $sizesById[(int)$size['id']] = $size;
  }

  echo "<fieldset>
  <legend>Variantes (Color y/o Talle)</legend>";
  if (!$variantDrafts) {
    echo "<p>Sin variantes.</p>
    <p><button type='button' id='variant-toggle'>Crear variante</button></p>
    <p>Si crea una variante el stock principal desaparecerá.</p>";
  } else {
    echo "<table border='1' cellpadding='6' cellspacing='0'>
    <tr><th>Color</th><th>Talle</th><th>SKU</th><th>Código universal</th><th>Imagen</th><th>Acciones</th></tr>";
    foreach ($variantDrafts as $draft) {
      $colorName = '—';
      $sizeName = '—';
      if (!empty($draft['color_id']) && isset($colorsById[(int)$draft['color_id']])) {
        $colorName = (string)$colorsById[(int)$draft['color_id']]['name'];
      }
      if (!empty($draft['size_id']) && isset($sizesById[(int)$draft['size_id']])) {
        $sizeName = (string)$sizesById[(int)$draft['size_id']]['name'];
      }
      $skuVariant = $draft['sku_variant'] !== null && $draft['sku_variant'] !== '' ? $draft['sku_variant'] : '—';
      $imageCover = (string)($draft['image_path'] ?? '');
      $providerImageCover = (string)($draft['provider_image_path'] ?? '');
      $universalCode = $draft['universal_code'] !== null && $draft['universal_code'] !== '' ? $draft['universal_code'] : '—';
      $previewSource = $imageCover !== '' ? $imageCover : $providerImageCover;
      $imagePreview = $previewSource !== '' ? "<img src='".h($previewSource)."' alt='' width='50' height='50'> " : '—';
      echo "<tr>
        <td>".h($colorName)."</td>
        <td>".h($sizeName)."</td>
        <td>".h((string)$skuVariant)."</td>
        <td>".h((string)$universalCode)."</td>
        <td>".$imagePreview."</td>
        <td>
          <form method='post' style='margin:0; display:inline;' onsubmit='return confirm(\"¿Eliminar variante?\")'>
            <input type='hidden' name='csrf' value='".h(csrf_token())."'>
            <input type='hidden' name='action' value='delete_variant_draft'>
            <input type='hidden' name='draft_id' value='".h((string)($draft['id'] ?? ''))."'>
            <button>Eliminar</button>
          </form>
        </td>
      </tr>";
    }
    echo "</table>";
  }
  $variantFormStyle = $variantDrafts ? '' : " style='display:none;'";
  echo "<h4>Agregar variante</h4>
  <form method='post' enctype='multipart/form-data' id='variant-form'".$variantFormStyle.">
    <input type='hidden' name='csrf' value='".h(csrf_token())."'>
    <input type='hidden' name='action' value='add_variant_draft'>
    <input type='hidden' name='product_sku' id='variant-product-sku' value=''>
    <p>Color:
      <select name='color_id' id='variant-color-select'>
        <option value='0'>— elegir —</option>";
  foreach ($colors as $color) {
    $colorCode = trim((string)($color['codigo'] ?? ''));
    $colorCodeAttr = $colorCode !== '' ? " data-code='".h($colorCode)."'" : "";
    echo "<option value='".h((string)$color['id'])."'".$colorCodeAttr.">".h((string)$color['name'])."</option>";
  }
  echo "</select></p>
    <p>Talle:
      <select name='size_id' id='variant-size-select'>
        <option value='0'>— elegir —</option>";
  foreach ($sizes as $size) {
    $sizeCode = trim((string)($size['code'] ?? ''));
    $sizeCodeAttr = $sizeCode !== '' ? " data-code='".h($sizeCode)."'" : "";
    echo "<option value='".h((string)$size['id'])."'".$sizeCodeAttr.">".h((string)$size['name'])."</option>";
  }
  echo "</select></p>
    <p>SKU: <input name='sku_variant' id='variant-sku-input' style='width:200px' required></p>
    <p>Código universal (8-14 dígitos): <input name='universal_code' style='width:200px' maxlength='14'></p>
    <p>Imagen: <input type='file' name='variant_image' accept='image/*'></p>
    <button>Agregar</button>
  </form>
  <script>
  (function() {
    var toggle = document.getElementById('variant-toggle');
    var form = document.getElementById('variant-form');
    if (toggle && form) {
      toggle.addEventListener('click', function() {
        form.style.display = '';
        toggle.style.display = 'none';
      });
    }
  })();
  (function() {
    var form = document.getElementById('variant-form');
    if (!form) return;
    var colorSelect = form.querySelector('select[name=\"color_id\"]');
    var sizeSelect = form.querySelector('select[name=\"size_id\"]');
    var skuInput = form.querySelector('input[name=\"sku_variant\"]');
    var productSkuInput = document.getElementById('create-sku');
    var productSkuHidden = document.getElementById('variant-product-sku');
    if (!colorSelect || !sizeSelect || !skuInput || !productSkuInput || !productSkuHidden) return;

    function syncProductSku() {
      productSkuHidden.value = productSkuInput.value.trim();
    }

    function updateVariantSku() {
      var baseSku = productSkuInput.value.trim();
      var selectedOption = colorSelect.options[colorSelect.selectedIndex];
      var sizeOption = sizeSelect.options[sizeSelect.selectedIndex];
      if (!selectedOption || !sizeOption) {
        skuInput.value = '';
        return;
      }
      var colorValue = colorSelect.value;
      var sizeValue = sizeSelect.value;
      if (colorValue === '0' && sizeValue === '0') {
        skuInput.value = '';
        return;
      }
      if (baseSku === '') {
        skuInput.value = '';
        return;
      }
      var pieces = [];
      if (colorValue !== '0') {
        var colorCode = (selectedOption.getAttribute('data-code') || '').trim();
        pieces.push(colorCode !== '' ? colorCode : 'C' + colorValue);
      }
      if (sizeValue !== '0') {
        var sizeCode = (sizeOption.getAttribute('data-code') || '').trim();
        pieces.push(sizeCode !== '' ? sizeCode : 'T' + sizeValue);
      }
      if (!pieces.length) {
        skuInput.value = '';
        return;
      }
      skuInput.value = baseSku + '-' + pieces.join('-');
    }

    syncProductSku();
    colorSelect.addEventListener('change', updateVariantSku);
    sizeSelect.addEventListener('change', updateVariantSku);
    productSkuInput.addEventListener('input', function() {
      syncProductSku();
      updateVariantSku();
    });
    form.addEventListener('submit', function() {
      syncProductSku();
    });
  })();
  </script>
  </fieldset>
  <hr>";

  echo "<h3>Copiar desde proveedor</h3>
  <form method='get' action='productos.php'>
  <input type='hidden' name='action' value='new'>
  <input type='hidden' name='store_id' value='".h((string)$storeId)."'>
  <input name='provider_q' placeholder='Buscar producto del proveedor...' value='".h($providerQuery)."' style='width:420px'>
  <button>Buscar</button>
  </form>";

  echo "<table border='1' cellpadding='6' cellspacing='0' style='margin-top:10px; width:100%; max-width:1200px;'>
  <tr>
    <th>Proveedor</th>
    <th>Título</th>
    <th>Variante</th>
    <th>SKU</th>
    <th>Código universal</th>
    <th>Stock</th>
    <th>Precio</th>
    <th>Acciones</th>
  </tr>";

  if (!$providerProducts) {
    echo "<tr><td colspan='8'>No se encontraron productos.</td></tr>";
  } else {
    foreach ($providerProducts as $pp) {
      $productId = (int)$pp['id'];
      $images = $providerProductImages[$productId] ?? [];
      $imagesJson = h(json_encode($images, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));
      $variants = $providerVariantsByProduct[$productId] ?? [];
      $variantCount = count($variants);
      $hasVariants = $variantCount > 0;
      $priceTxt = ($pp['base_price'] !== null && $pp['base_price'] !== '') ? '$'.h(number_format((float)$pp['base_price'],2,',','.')) : '-';
      $skuText = $hasVariants ? '-' : h($pp['sku'] ?? '');
      $universalText = $hasVariants ? '-' : h($pp['universal_code'] ?? '');
      $variantText = $hasVariants ? h((string)$variantCount) : '-';
      $priceText = $hasVariants ? '-' : $priceTxt;

      if ($hasVariants) {
        $actionButtons = "<form method='post' action='productos.php?action=new&store_id=".h((string)$storeId)."' style='margin:0 0 6px 0;'>
            <input type='hidden' name='csrf' value='".h(csrf_token())."'>
            <input type='hidden' name='action' value='copy_provider_all'>
            <input type='hidden' name='provider_product_id' value='".h((string)$productId)."'>
            <button type='submit'>Copiar todo</button>
          </form>
          <button type='button' class='toggle-variants-btn' data-product-id='".h((string)$productId)."' data-expanded='0'>Mostrar variantes</button>";
      } else {
        $actionButtons = "<button type='button' class='copy-provider-fill'
            data-title='".h($pp['title'])."'
            data-sku='".h($pp['sku'] ?? '')."'
            data-universal='".h($pp['universal_code'] ?? '')."'
            data-description='".h($pp['description'] ?? '')."'
            data-category-id='".h((string)($pp['category_id'] ?? 0))."'
            data-provider-id='".h((string)$productId)."'
            data-images='".$imagesJson."'
          >Copiar</button>";
      }

      echo "<tr data-product-id='".h((string)$productId)."'>
        <td>".h($pp['provider_name'])."</td>
        <td>".h($pp['title'])."</td>
        <td>".$variantText."</td>
        <td>".$skuText."</td>
        <td>".$universalText."</td>
        <td>".h((string)$pp['stock'])."</td>
        <td>".$priceText."</td>
        <td>".$actionButtons."</td>
      </tr>";

      if ($hasVariants) {
        foreach ($variants as $variant) {
          $variantId = (int)$variant['id'];
          $colorName = trim((string)($variant['color_name'] ?? ''));
          $sizeName = trim((string)($variant['size_name'] ?? ''));
          if ($colorName !== '' && $sizeName !== '') {
            $variantLabel = $colorName.' / '.$sizeName;
          } elseif ($colorName !== '') {
            $variantLabel = $colorName;
          } elseif ($sizeName !== '') {
            $variantLabel = $sizeName;
          } else {
            $variantLabel = '—';
          }
          $variantSku = $variant['sku_variant'] !== null && $variant['sku_variant'] !== '' ? $variant['sku_variant'] : '-';
          $variantUniversal = $variant['universal_code'] !== null && $variant['universal_code'] !== '' ? $variant['universal_code'] : '-';
          $variantPrice = '-';
          if ($variant['own_stock_price'] !== null && $variant['own_stock_price'] !== '') {
            $variantPrice = '$'.h(number_format((float)$variant['own_stock_price'],2,',','.'));
          } elseif ($variant['manual_price'] !== null && $variant['manual_price'] !== '') {
            $variantPrice = '$'.h(number_format((float)$variant['manual_price'],2,',','.'));
          } elseif ($pp['base_price'] !== null && $pp['base_price'] !== '') {
            $variantPrice = '$'.h(number_format((float)$pp['base_price'],2,',','.'));
          }
          echo "<tr class='provider-variant-row' data-parent-id='".h((string)$productId)."' style='display:none;'>
            <td></td>
            <td></td>
            <td>".h($variantLabel)."</td>
            <td>".h($variantSku)."</td>
            <td>".h($variantUniversal)."</td>
            <td>".h((string)$variant['stock_qty'])."</td>
            <td>".$variantPrice."</td>
            <td>
              <form method='post' action='productos.php?action=new&store_id=".h((string)$storeId)."' style='margin:0;'>
                <input type='hidden' name='csrf' value='".h(csrf_token())."'>
                <input type='hidden' name='action' value='copy_provider_variant'>
                <input type='hidden' name='provider_product_id' value='".h((string)$productId)."'>
                <input type='hidden' name='provider_variant_id' value='".h((string)$variantId)."'>
                <button type='submit'>Copiar</button>
              </form>
            </td>
          </tr>";
        }
      }
    }
  }
  echo "</table><hr>
  <script>
  (function() {
    var titleInput = document.getElementById('create-title');
    var skuInput = document.getElementById('create-sku');
    var universalInput = document.getElementById('create-universal');
    var descriptionInput = document.getElementById('create-description');
    var categorySelect = document.getElementById('create-category');
    var message = document.getElementById('copy-message');
    var form = document.getElementById('create-form');
    var buttons = document.querySelectorAll('.copy-provider-fill');
    var imagesList = document.getElementById('images-list');
    var orderInput = document.getElementById('images_order');
    var copyPayloadInput = document.getElementById('copy_images_payload');
    var imagesInput = document.getElementById('images-input');

    function setPlaceholderIfEmpty() {
      if (!imagesList) return;
      var items = imagesList.querySelectorAll('li[data-kind]');
      if (items.length === 0) {
        imagesList.innerHTML = '<li>No hay imágenes cargadas.</li>';
      }
    }

    function updateOrder() {
      if (!imagesList || !orderInput) return;
      var tokens = [];
      var items = imagesList.querySelectorAll('li[data-kind]');
      items.forEach(function(item, index) {
        var kind = item.getAttribute('data-kind');
        var key = item.getAttribute('data-key');
        if (kind && key) {
          tokens.push(kind + ':' + key);
        }
        var label = item.querySelector('.cover-label');
        if (label) {
          label.textContent = index === 0 ? 'Portada' : '';
        }
      });
      orderInput.value = tokens.join(',');
    }

    function addImageItem(kind, key, src) {
      if (!imagesList) return;
      var item = document.createElement('li');
      item.setAttribute('data-kind', kind);
      item.setAttribute('data-key', key);
      var img = document.createElement('img');
      img.src = src;
      img.alt = '';
      img.width = 80;
      img.height = 80;
      var label = document.createElement('span');
      label.className = 'cover-label';
      var btnUp = document.createElement('button');
      btnUp.type = 'button';
      btnUp.className = 'move-up';
      btnUp.textContent = '↑';
      var btnDown = document.createElement('button');
      btnDown.type = 'button';
      btnDown.className = 'move-down';
      btnDown.textContent = '↓';
      item.appendChild(img);
      item.appendChild(document.createTextNode(' '));
      item.appendChild(label);
      item.appendChild(document.createTextNode(' '));
      item.appendChild(btnUp);
      item.appendChild(document.createTextNode(' '));
      item.appendChild(btnDown);
      imagesList.appendChild(item);
    }

    function clearCopyItems() {
      if (!imagesList) return;
      var items = imagesList.querySelectorAll('li[data-kind=\"copy\"]');
      items.forEach(function(item) { item.remove(); });
      setPlaceholderIfEmpty();
    }

    function clearUploadItems() {
      if (!imagesList) return;
      var items = imagesList.querySelectorAll('li[data-kind=\"upload\"]');
      items.forEach(function(item) { item.remove(); });
      setPlaceholderIfEmpty();
    }

    if (imagesList) {
      imagesList.addEventListener('click', function(event) {
        if (event.target.classList.contains('move-up') || event.target.classList.contains('move-down')) {
          var item = event.target.closest('li[data-kind]');
          if (!item) return;
          if (event.target.classList.contains('move-up')) {
            var prev = item.previousElementSibling;
            if (prev && prev.hasAttribute('data-kind')) {
              imagesList.insertBefore(item, prev);
            }
          } else {
            var next = item.nextElementSibling;
            if (next && next.hasAttribute('data-kind')) {
              imagesList.insertBefore(next, item);
            }
          }
          updateOrder();
        }
      });
    }

    buttons.forEach(function(button) {
      button.addEventListener('click', function() {
        if (titleInput) titleInput.value = button.dataset.title || '';
        if (skuInput) skuInput.value = button.dataset.sku || '';
        if (universalInput) universalInput.value = button.dataset.universal || '';
        if (descriptionInput) descriptionInput.value = button.dataset.description || '';
        if (categorySelect) categorySelect.value = button.dataset.categoryId || '0';
        var providerId = button.dataset.providerId || '';
        var images = [];
        try {
          images = JSON.parse(button.dataset.images || '[]');
        } catch (e) {
          images = [];
        }
        if (copyPayloadInput) {
          copyPayloadInput.value = JSON.stringify({
            product_id: providerId,
            images: images
          });
        }
        if (imagesList) {
          if (imagesList.querySelectorAll('li[data-kind]').length === 0) {
            imagesList.innerHTML = '';
          }
        }
        clearCopyItems();
        if (imagesList && images && images.length) {
          images.forEach(function(image) {
            var base = image.filename_base || '';
            if (!base || !providerId) return;
            addImageItem('copy', base, '/uploads/provider_products/' + providerId + '/' + base.replace(/(\\.[^.]+)$/, '_150$1'));
          });
        }
        setPlaceholderIfEmpty();
        updateOrder();
        if (message) {
          message.textContent = 'Datos cargados desde proveedor. Revisá y presioná Crear para publicar.';
          message.style.display = 'block';
        }
        if (form && form.scrollIntoView) {
          form.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
        if (titleInput && titleInput.focus) titleInput.focus();
      });
    });

    var toggleButtons = document.querySelectorAll('.toggle-variants-btn');
    toggleButtons.forEach(function(button) {
      button.addEventListener('click', function() {
        var productId = button.dataset.productId;
        if (!productId) return;
        var rows = document.querySelectorAll('.provider-variant-row[data-parent-id=\"' + productId + '\"]');
        var expanded = button.dataset.expanded === '1';
        rows.forEach(function(row) {
          row.style.display = expanded ? 'none' : 'table-row';
        });
        button.dataset.expanded = expanded ? '0' : '1';
        button.textContent = expanded ? 'Mostrar variantes' : 'Ocultar variantes';
      });
    });

    if (imagesInput) {
      imagesInput.addEventListener('change', function() {
        clearUploadItems();
        if (!imagesList) return;
        var files = Array.prototype.slice.call(imagesInput.files || []);
        if (files.length === 0) {
          setPlaceholderIfEmpty();
          updateOrder();
          return;
        }
        if (imagesList.querySelectorAll('li[data-kind]').length === 0) {
          imagesList.innerHTML = '';
        }
        files.forEach(function(file, index) {
          var url = URL.createObjectURL(file);
          addImageItem('upload', String(index), url);
        });
        updateOrder();
      });
    }

    setPlaceholderIfEmpty();
    updateOrder();
  })();
  </script>";
}
page_footer();
