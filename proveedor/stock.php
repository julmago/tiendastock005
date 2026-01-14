<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();
if (!$p) exit('Proveedor inválido');

$rows = $pdo->prepare("
  SELECT pp.title, pp.base_price, COALESCE(ws.qty_available,0) AS qty_available, COALESCE(ws.qty_reserved,0) AS qty_reserved
  FROM provider_products pp
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id=pp.id
  WHERE pp.provider_id=?
  ORDER BY qty_available DESC, pp.id DESC
");
$rows->execute([(int)$p['id']]);
$list = $rows->fetchAll();

page_header('Proveedor - Stock en bodega');
echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Producto</th><th>Base</th><th>Disp</th><th>Res</th></tr>";
foreach($list as $r){
  echo "<tr><td>".h($r['title'])."</td><td>".h((string)$r['base_price'])."</td><td>".h((string)$r['qty_available'])."</td><td>".h((string)$r['qty_reserved'])."</td></tr>";
}
echo "</table>";
page_footer();
