<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../lib/product_images.php';
csrf_check();
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id, status FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();
if (!$p) exit('Proveedor inválido');

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

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'delete_image') {
  $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
  $image_id = isset($_POST['delete_image_id']) ? (int)$_POST['delete_image_id'] : 0;
  if ($product_id <= 0 || $image_id <= 0) {
    $err = "Imagen inválida.";
  } else {
    $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
    $st->execute([$product_id, (int)$p['id']]);
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
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($p['status'] ?? '') !== 'active') $err="Cuenta pendiente de aprobación.";
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
        $st->execute([$product_id,(int)$p['id']]);
        if ($st->fetch()) {
          $pdo->prepare("UPDATE provider_products SET title=?, sku=?, universal_code=?, description=?, base_price=?, category_id=? WHERE id=? AND provider_id=?")
              ->execute([$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$categoryValue,$product_id,(int)$p['id']]);
          $msg="Actualizado.";
          $edit_id = $product_id;
        } else {
          $err="Producto inválido.";
        }
      } else {
        $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,universal_code,description,base_price,category_id,status) VALUES(?,?,?,?,?,?,?,'active')")
            ->execute([(int)$p['id'],$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$categoryValue]);
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
  $st->execute([$edit_id,(int)$p['id']]);
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
    SELECT pv.color_id, pv.sku_variant, pv.stock_qty, c.name AS color_name
    FROM product_variants pv
    JOIN colors c ON c.id = pv.color_id
    WHERE pv.owner_type='provider' AND pv.owner_id=? AND pv.product_id=?
    ORDER BY pv.position ASC, pv.id ASC
  ");
  $variantSt->execute([(int)$p['id'], $edit_id]);
  $variantRows = $variantSt->fetchAll();
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
$rows->execute([(int)$p['id']]);
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
echo "<p><a href='/proveedor/catalogo.php?view=new'>Nuevo</a> | <a href='/proveedor/catalogo.php'>Listado</a></p>";
echo "<hr>";

if ($view === 'new' || $view === 'edit') {
echo "<form method='post' enctype='multipart/form-data'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' id='product_action' value='save_product'>
<input type='hidden' name='product_id' value='".h((string)($edit_product['id'] ?? ''))."'>
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
<legend>Variantes (Color)</legend>";
if (!$variantRows) {
  echo "<p>Sin variantes.</p>";
} else {
  echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>Color</th><th>SKU</th><th>Stock</th></tr>";
  foreach ($variantRows as $variant) {
    $skuVariant = $variant['sku_variant'] !== null && $variant['sku_variant'] !== '' ? $variant['sku_variant'] : '—';
    echo "<tr>
      <td>".h((string)$variant['color_name'])."</td>
      <td>".h((string)$skuVariant)."</td>
      <td>".h((string)$variant['stock_qty'])."</td>
    </tr>";
  }
  echo "</table>";
}
echo "</fieldset>
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
  echo " <a href='/proveedor/catalogo.php'>Cancelar edición</a>";
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

if ($view === 'list') {
echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Imagen</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Base</th><th>Disp</th><th>Res</th><th>Ventas</th><th>Acciones</th></tr>";
foreach($list as $r){
  $url = "/proveedor/catalogo.php?id=".h((string)$r['id']);
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
