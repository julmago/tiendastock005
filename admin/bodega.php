<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_any_role(['superadmin','admin'], '/admin/login.php');

if ($_SERVER['REQUEST_METHOD']==='POST') {
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

$pp = $pdo->query("
  SELECT pp.id, pp.title, pp.base_price, p.display_name AS provider_name
  FROM provider_products pp JOIN providers p ON p.id=pp.provider_id
  WHERE pp.status='active' AND p.status='active'
  ORDER BY pp.id DESC LIMIT 200
")->fetchAll();

$stock = $pdo->query("
  SELECT ws.qty_available, ws.qty_reserved, pp.title, p.display_name AS provider_name
  FROM warehouse_stock ws
  JOIN provider_products pp ON pp.id=ws.provider_product_id
  JOIN providers p ON p.id=pp.provider_id
  ORDER BY ws.qty_available DESC LIMIT 200
")->fetchAll();

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

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Proveedor</th><th>Producto</th><th>Disp</th><th>Res</th></tr>";
foreach($stock as $s){
  echo "<tr><td>".h($s['provider_name'])."</td><td>".h($s['title'])."</td><td>".h((string)$s['qty_available'])."</td><td>".h((string)$s['qty_reserved'])."</td></tr>";
}
echo "</table>";
page_footer();
