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

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'new'], true)) $action = 'list';
$listUrl = "productos.php?action=list&store_id=".h((string)$storeId);
$newUrl = "productos.php?action=new&store_id=".h((string)$storeId);

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
      $variantSt = $pdo->prepare("
        SELECT color_id, size_id, sku_variant, stock_qty, image_cover, position
        FROM product_variants
        WHERE owner_type='provider' AND owner_id=? AND product_id=?
        ORDER BY position ASC, id ASC
      ");
      $variantSt->execute([$copy_provider_id, $copy_source_id]);
      $variants = $variantSt->fetchAll();
      if ($variants) {
        $insertVariant = $pdo->prepare("
          INSERT INTO product_variants(owner_type, owner_id, product_id, color_id, size_id, sku_variant, stock_qty, image_cover, position)
          VALUES('vendor', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($variants as $variant) {
          $insertVariant->execute([
            $storeId,
            $productId,
            (int)$variant['color_id'],
            $variant['size_id'] !== null ? (int)$variant['size_id'] : null,
            $variant['sku_variant'],
            (int)$variant['stock_qty'],
            $variant['image_cover'],
            (int)$variant['position'],
          ]);
        }
      }
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

    $msg="Producto creado.";
    if (empty($image_errors)) {
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

  $stp = $pdo->prepare("SELECT * FROM store_products WHERE store_id=? ORDER BY id DESC");
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
  <tr><th>Imagen</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock prov</th><th>Own qty</th><th>Own $</th><th>Manual $</th><th>Precio actual</th></tr>";
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
    </tr>";
  }
  echo "</table>";
}

if ($action === 'new') {
  $providerQuery = trim((string)($_GET['provider_q'] ?? ''));
  $providerProducts = [];
  if ($providerQuery !== '') {
    $like = "%{$providerQuery}%";
    $searchSt = $pdo->prepare("
      SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, pp.description, pp.category_id, p.display_name AS provider_name,
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
      GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, pp.description, pp.category_id, p.display_name
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

  echo "<h3>Crear desde cero</h3>
  <form method='post' id='create-form' enctype='multipart/form-data'>
  <input type='hidden' name='csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='action' value='create'>
  <input type='hidden' name='images_order' id='images_order' value=''>
  <input type='hidden' name='copy_images_payload' id='copy_images_payload' value=''>
  <p>Título: <input id='create-title' name='title' style='width:520px'></p>
  <p>SKU: <input id='create-sku' name='sku' style='width:220px'></p>
  <p>Código universal (8-14 dígitos): <input id='create-universal' name='universal_code' style='width:220px'></p>
  <p>Categoría:
    <select id='create-category' name='category_id'>
      <option value='0'>Sin categoría</option>";
foreach ($flatCategories as $cat) {
  $indent = str_repeat('— ', (int)$cat['depth']);
  echo "<option value='".h((string)$cat['id'])."'>".$indent.h($cat['name'])."</option>";
}
echo "</select>
  </p>
  <p>Descripción:<br><textarea id='create-description' name='description' rows='3' style='width:90%'></textarea></p>
  <fieldset>
  <legend>Imágenes</legend>
  <p><input type='file' name='images[]' id='images-input' multiple accept='image/*'></p>
  <ul id='images-list'>
    <li>No hay imágenes cargadas.</li>
  </ul>
  </fieldset>
  <p id='copy-message' style='color:green; display:none;'></p>
  <button>Crear</button>
  </form><hr>";

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
    <th>SKU</th>
    <th>Código universal</th>
    <th>Stock</th>
    <th>Precio</th>
    <th>Acciones</th>
  </tr>";

  if (!$providerProducts) {
    echo "<tr><td colspan='7'>No se encontraron productos.</td></tr>";
  } else {
    foreach ($providerProducts as $pp) {
      $priceTxt = ($pp['base_price'] !== null && $pp['base_price'] !== '') ? '$'.h(number_format((float)$pp['base_price'],2,',','.')) : '';
      $images = $providerProductImages[(int)$pp['id']] ?? [];
      $imagesJson = h(json_encode($images, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));
      echo "<tr>
        <td>".h($pp['provider_name'])."</td>
        <td>".h($pp['title'])."</td>
        <td>".h($pp['sku'] ?? '')."</td>
        <td>".h($pp['universal_code'] ?? '')."</td>
        <td>".h((string)$pp['stock'])."</td>
        <td>".$priceTxt."</td>
        <td>
          <button type='button' class='copy-provider-btn'
            data-title='".h($pp['title'])."'
            data-sku='".h($pp['sku'] ?? '')."'
            data-universal='".h($pp['universal_code'] ?? '')."'
            data-description='".h($pp['description'] ?? '')."'
            data-category-id='".h((string)($pp['category_id'] ?? 0))."'
            data-provider-id='".h((string)$pp['id'])."'
            data-images='".$imagesJson."'
          >Copiar</button>
        </td>
      </tr>";
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
    var buttons = document.querySelectorAll('.copy-provider-btn');
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
