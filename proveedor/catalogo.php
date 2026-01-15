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
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if (!$title || $price<=0) $err="Completá título y precio base.";
    elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
    else {
      if ($product_id > 0) {
        $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
        $st->execute([$product_id,(int)$p['id']]);
        if ($st->fetch()) {
          $pdo->prepare("UPDATE provider_products SET title=?, sku=?, universal_code=?, description=?, base_price=? WHERE id=? AND provider_id=?")
              ->execute([$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$product_id,(int)$p['id']]);
          $msg="Actualizado.";
          $edit_id = $product_id;
        } else {
          $err="Producto inválido.";
        }
      } else {
        $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,universal_code,description,base_price,status) VALUES(?,?,?,?,?,?,'active')")
            ->execute([(int)$p['id'],$title,$sku?:null,$universalCode?:null,$desc?:null,$price]);
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
  $st = $pdo->prepare("SELECT id,title,sku,universal_code,description,base_price FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
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

$rows = $pdo->prepare("SELECT id,title,sku,universal_code,base_price FROM provider_products WHERE provider_id=? ORDER BY id DESC");
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
echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Base</th></tr>";
foreach($list as $r){
  $url = "/proveedor/catalogo.php?id=".h((string)$r['id']);
  echo "<tr><td>".h((string)$r['id'])."</td><td><a href='".$url."'>".h($r['title'])."</a></td><td>".h($r['sku']??'')."</td><td>".h($r['universal_code']??'')."</td><td>".h((string)$r['base_price'])."</td></tr>";
}
echo "</table>";
}
page_footer();
