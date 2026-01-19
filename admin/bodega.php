<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../lib/product_images.php';
csrf_check();
require_any_role(['superadmin','admin'], '/admin/login.php');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'update_stock') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $stockInput = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
    if (!$productId || $stockInput === false || $stockInput < 0) {
      $err = "Ingresá un stock válido.";
    } else {
      if ($variantId) {
        $pdo->prepare("UPDATE product_variants SET stock_qty=? WHERE id=? AND owner_type='provider'")
          ->execute([$stockInput, $variantId]);
      } else {
        $pdo->prepare("INSERT INTO warehouse_stock(provider_product_id,qty_available,qty_reserved)
          VALUES(?, ?, 0) ON DUPLICATE KEY UPDATE qty_available = VALUES(qty_available)")
          ->execute([$productId, $stockInput]);
      }
      $msg = "Stock actualizado.";
    }
  } else {
    $ppId = (int)($_POST['provider_product_id'] ?? 0);
    $qty = (int)($_POST['qty_received'] ?? 0);
    if (!$ppId || $qty<=0) $err="Elegí producto y cantidad.";
    else {
      $pdo->prepare("INSERT INTO warehouse_receipts(provider_id,provider_product_id,qty_received,note,created_by_user_id)
        SELECT pp.provider_id, pp.id, ?, ?, ? FROM provider_products pp WHERE pp.id=?")
        ->execute([$qty, (string)($_POST['note'] ?? ''), (int)($_SESSION['uid']??0), $ppId]);

      $pdo->prepare("INSERT INTO warehouse_stock(provider_product_id,qty_available,qty_reserved)
        VALUES(?, ?, 0) ON DUPLICATE KEY UPDATE qty_available = qty_available + VALUES(qty_available)")
        ->execute([$ppId,$qty]);
      $msg="Recepción OK.";
    }
  }
}

$pp = $pdo->query("
  SELECT pp.id, pp.title, pp.base_price, p.display_name AS provider_name
  FROM provider_products pp JOIN providers p ON p.id=pp.provider_id
  WHERE pp.status='active' AND p.status='active'
  ORDER BY pp.id DESC LIMIT 200
")->fetchAll();

$products = $pdo->query("
  SELECT pp.id, pp.title, pp.sku, p.display_name AS provider_name, i.filename_base AS cover_image,
         COALESCE(ws.qty_available,0) AS qty_available,
         COALESCE(pv.variant_count,0) AS variant_count
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  LEFT JOIN product_images i ON i.owner_type='provider_product' AND i.owner_id=pp.id AND i.position=1
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id=pp.id
  LEFT JOIN (
    SELECT product_id, owner_id, COUNT(*) AS variant_count
    FROM product_variants
    WHERE owner_type='provider'
    GROUP BY product_id, owner_id
  ) pv ON pv.product_id=pp.id AND pv.owner_id=pp.provider_id
  ORDER BY pp.id DESC LIMIT 200
")->fetchAll();

$variantsByProductId = [];
$productIds = array_map(static fn($row) => (int)$row['id'], $products);
if ($productIds) {
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $variantSt = $pdo->prepare("
    SELECT id, product_id, sku_variant, stock_qty
    FROM product_variants
    WHERE owner_type='provider' AND product_id IN ($placeholders)
    ORDER BY product_id ASC, position ASC, id ASC
  ");
  $variantSt->execute($productIds);
  foreach ($variantSt->fetchAll() as $variant) {
    $productId = (int)$variant['product_id'];
    if (!isset($variantsByProductId[$productId])) {
      $variantsByProductId[$productId] = [];
    }
    $variantsByProductId[$productId][] = $variant;
  }
}

page_header('Bodega');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Producto:
<select name='provider_product_id' style='width:780px'><option value='0'>-- elegir --</option>";
foreach($pp as $r){
  echo "<option value='".h((string)$r['id'])."'>#".h((string)$r['id'])." ".h($r['provider_name'])." | ".h($r['title'])." ($".h((string)$r['base_price']).")</option>";
}
echo "</select></p>
<p>Cantidad: <input name='qty_received' style='width:120px'></p>
<p>Nota: <input name='note' style='width:520px'></p>
<button>Registrar</button>
</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Imagen</th><th>Proveedor</th><th>Título</th><th>Sku</th><th>Stock</th><th>Acciones</th></tr>";
foreach($products as $product){
  $productId = (int)$product['id'];
  if (!empty($product['cover_image'])) {
    $thumb = product_image_with_size($product['cover_image'], 150);
    $thumb_url = "/uploads/provider_products/".h((string)$productId)."/".h($thumb);
    $image_cell = "<img src='".$thumb_url."' alt='' width='50' height='50'>";
  } else {
    $image_cell = "—";
  }

  $variants = $variantsByProductId[$productId] ?? [];
  if ($variants) {
    $rowspan = count($variants);
    $first = array_shift($variants);
    $formId = "stock-form-".$productId."-".(int)$first['id'];
    $stockInput = "<input type='number' name='stock' min='0' style='width:90px' form='".h($formId)."' value='".h((string)$first['stock_qty'])."'>";
    echo "<tr>";
    echo "<td rowspan='".h((string)$rowspan)."'>".$image_cell."</td>";
    echo "<td rowspan='".h((string)$rowspan)."'>".h($product['provider_name'])."</td>";
    echo "<td rowspan='".h((string)$rowspan)."'>".h($product['title'])."</td>";
    echo "<td>".h($first['sku_variant'] ?? '')."</td>";
    echo "<td>".$stockInput."</td>";
    echo "<td><form id='".h($formId)."' method='post'>
      <input type='hidden' name='csrf' value='".h(csrf_token())."'>
      <input type='hidden' name='action' value='update_stock'>
      <input type='hidden' name='product_id' value='".h((string)$productId)."'>
      <input type='hidden' name='variant_id' value='".h((string)$first['id'])."'>
      <button>Guardar</button>
    </form></td>";
    echo "</tr>";
    foreach ($variants as $variant) {
      $variantFormId = "stock-form-".$productId."-".(int)$variant['id'];
      $variantStockInput = "<input type='number' name='stock' min='0' style='width:90px' form='".h($variantFormId)."' value='".h((string)$variant['stock_qty'])."'>";
      echo "<tr><td>".h($variant['sku_variant'] ?? '')."</td><td>".$variantStockInput."</td><td>
        <form id='".h($variantFormId)."' method='post'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='update_stock'>
          <input type='hidden' name='product_id' value='".h((string)$productId)."'>
          <input type='hidden' name='variant_id' value='".h((string)$variant['id'])."'>
          <button>Guardar</button>
        </form>
      </td></tr>";
    }
    continue;
  }

  $productFormId = "stock-form-".$productId;
  $productStockInput = "<input type='number' name='stock' min='0' style='width:90px' form='".h($productFormId)."' value='".h((string)$product['qty_available'])."'>";
  echo "<tr><td>".$image_cell."</td><td>".h($product['provider_name'])."</td><td>".h($product['title'])."</td><td>".h($product['sku'] ?? '')."</td><td>".$productStockInput."</td><td>
    <form id='".h($productFormId)."' method='post'>
      <input type='hidden' name='csrf' value='".h(csrf_token())."'>
      <input type='hidden' name='action' value='update_stock'>
      <input type='hidden' name='product_id' value='".h((string)$productId)."'>
      <button>Guardar</button>
    </form>
  </td></tr>";
}
echo "</table>";
page_footer();
