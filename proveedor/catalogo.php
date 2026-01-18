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
if ($role === 'superadmin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $variantAction = $_POST['action'] ?? '';
  if (in_array($variantAction, ['add_variant', 'update_variant', 'delete_variant', 'move_variant'], true)) {
    $variantHandled = true;
    $product_id = (int)($_POST['product_id'] ?? 0);
    $edit_id = $product_id;
    if ($product_id <= 0) {
      $err = "Producto inválido.";
    } else {
      $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
      $st->execute([$product_id, $providerId]);
      if (!$st->fetch()) {
        $err = "Producto inválido.";
      }
    }

    if (empty($err)) {
      if ($variantAction === 'add_variant') {
        $colorId = (int)($_POST['color_id'] ?? 0);
        $stockQty = (int)($_POST['stock_qty'] ?? 0);
        $skuVariant = trim((string)($_POST['sku_variant'] ?? ''));
        $imageCover = trim((string)($_POST['image_cover'] ?? ''));
        $imageValue = $imageCover === '' ? null : $imageCover;
        if ($colorId <= 0) {
          $err = "Color inválido.";
        } else {
          $colorSt = $pdo->prepare("SELECT id FROM colors WHERE id=? LIMIT 1");
          $colorSt->execute([$colorId]);
          if (!$colorSt->fetch()) {
            $err = "Color inválido.";
          }
        }

        if (empty($err)) {
          $dupSt = $pdo->prepare("SELECT id FROM product_variants WHERE owner_type='provider' AND owner_id=? AND product_id=? AND color_id=? LIMIT 1");
          $dupSt->execute([$providerId, $product_id, $colorId]);
          if ($dupSt->fetch()) {
            $err = "El color ya está agregado.";
          }
        }

        if (empty($err)) {
          $posSt = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM product_variants WHERE owner_type='provider' AND owner_id=? AND product_id=?");
          $posSt->execute([$providerId, $product_id]);
          $nextPos = (int)$posSt->fetchColumn() + 1;
          $insertSt = $pdo->prepare("
            INSERT INTO product_variants(owner_type, owner_id, product_id, color_id, sku_variant, stock_qty, image_cover, position)
            VALUES('provider', ?, ?, ?, ?, ?, ?, ?)
          ");
          $insertSt->execute([$providerId, $product_id, $colorId, $skuVariant ?: null, $stockQty, $imageValue, $nextPos]);
          $msg = "Variante agregada.";
        }
      } elseif ($variantAction === 'update_variant') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $stockQty = (int)($_POST['stock_qty'] ?? 0);
        $skuVariant = trim((string)($_POST['sku_variant'] ?? ''));
        $imageCover = trim((string)($_POST['image_cover'] ?? ''));
        $imageValue = $imageCover === '' ? null : $imageCover;
        if ($variantId <= 0) {
          $err = "Variante inválida.";
        } else {
          $updateSt = $pdo->prepare("
            UPDATE product_variants
            SET sku_variant=?, stock_qty=?, image_cover=?
            WHERE id=? AND owner_type='provider' AND owner_id=? AND product_id=?
          ");
          $updateSt->execute([$skuVariant ?: null, $stockQty, $imageValue, $variantId, $providerId, $product_id]);
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
      } elseif ($variantAction === 'move_variant') {
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
    SELECT pv.id, pv.color_id, pv.sku_variant, pv.stock_qty, pv.image_cover, pv.position, c.name AS color_name
    FROM product_variants pv
    JOIN colors c ON c.id = pv.color_id
    WHERE pv.owner_type='provider' AND pv.owner_id=? AND pv.product_id=?
    ORDER BY pv.position ASC, pv.id ASC
  ");
  $variantSt->execute([$providerId, $edit_id]);
  $variantRows = $variantSt->fetchAll();
}
if ($role === 'superadmin') {
  $colors = $pdo->query("SELECT id, name, active FROM colors ORDER BY name ASC, id ASC")->fetchAll();
} else {
  $colors = [];
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
<legend>Variantes (Color)</legend>";
if (!$variantRows) {
  echo "<p>Sin variantes.</p>";
} else {
  echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>Color</th><th>SKU</th><th>Stock</th>";
  if ($role === 'superadmin') {
    echo "<th>Imagen</th><th>Orden</th><th>Acciones</th>";
  }
  echo "</tr>";
  foreach ($variantRows as $variant) {
    $skuVariant = $variant['sku_variant'] !== null && $variant['sku_variant'] !== '' ? $variant['sku_variant'] : '—';
    $imageCover = $variant['image_cover'] !== null && $variant['image_cover'] !== '' ? $variant['image_cover'] : '—';
    echo "<tr>
      <td>".h((string)$variant['color_name'])."</td>";
    if ($role === 'superadmin') {
      $formId = "variant-update-".(int)$variant['id'];
      echo "<td><input name='sku_variant' value='".h((string)($variant['sku_variant'] ?? ''))."' style='width:140px' form='".h($formId)."'></td>
      <td><input name='stock_qty' value='".h((string)$variant['stock_qty'])."' style='width:80px' form='".h($formId)."'></td>
      <td><input name='image_cover' value='".h((string)($variant['image_cover'] ?? ''))."' style='width:180px' form='".h($formId)."'></td>
      <td>".h((string)$variant['position'])."</td>
      <td>
        <form method='post' id='".h($formId)."' style='margin:0; display:inline;'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='update_variant'>
          <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
          <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <button>Guardar</button>
        </form>
        <form method='post' style='margin:0; display:inline;'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='move_variant'>
          <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
          <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <input type='hidden' name='direction' value='up'>
          <button>↑</button>
        </form>
        <form method='post' style='margin:0; display:inline;'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='move_variant'>
          <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
          <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <input type='hidden' name='direction' value='down'>
          <button>↓</button>
        </form>
        <form method='post' style='margin:0; display:inline;' onsubmit='return confirm(\"¿Eliminar variante?\")'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='delete_variant'>
          <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
          <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <button>Eliminar</button>
        </form>
      </td>";
    } else {
      echo "<td>".h((string)$skuVariant)."</td>
      <td>".h((string)$variant['stock_qty'])."</td>";
    }
    echo "</tr>";
  }
  echo "</table>";
}
if ($role === 'superadmin') {
  echo "<h4>Agregar variante</h4>
  <form method='post'>
    <input type='hidden' name='csrf' value='".h(csrf_token())."'>
    <input type='hidden' name='action' value='add_variant'>
    <input type='hidden' name='product_id' value='".h((string)$edit_id)."'>
    <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
    <p>Color:
      <select name='color_id'>
        <option value='0'>-- elegir --</option>";
  foreach ($colors as $color) {
    $colorLabel = $color['name'];
    if ((int)$color['active'] !== 1) {
      $colorLabel .= " (inactivo)";
    }
    echo "<option value='".h((string)$color['id'])."'>".h($colorLabel)."</option>";
  }
  echo "</select></p>
    <p>Stock: <input name='stock_qty' style='width:80px'></p>
    <p>SKU: <input name='sku_variant' style='width:180px'></p>
    <p>Imagen: <input name='image_cover' style='width:220px'></p>
    <button>Agregar</button>
  </form>";
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
