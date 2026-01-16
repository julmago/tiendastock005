<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();
if (!$p) exit('Proveedor inválido');

$rows = $pdo->prepare("
  SELECT pp.id, pp.title, pp.base_price,
         COALESCE(ws.qty_available,0) AS qty_available,
         COALESCE(ws.qty_reserved,0) AS qty_reserved,
         COALESCE(SUM(oa.qty_allocated),0) AS qty_sold
  FROM provider_products pp
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id=pp.id
  LEFT JOIN order_allocations oa ON oa.provider_product_id=pp.id
  WHERE pp.provider_id=?
  GROUP BY pp.id, pp.title, pp.base_price, ws.qty_available, ws.qty_reserved
  ORDER BY qty_available DESC, pp.id DESC
");
$rows->execute([(int)$p['id']]);
$list = $rows->fetchAll();

page_header('Proveedor - Stock en bodega');
echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Producto</th><th>Base</th><th>Disp</th><th>Res</th><th>Ventas</th><th>Acciones</th></tr>";
foreach($list as $r){
  $ventasUrl = "/proveedor/stock_ventas.php?id=".h((string)$r['id']);
  echo "<tr><td>".h($r['title'])."</td><td>".h((string)$r['base_price'])."</td><td>".h((string)$r['qty_available'])."</td><td>".h((string)$r['qty_reserved'])."</td><td>".h((string)$r['qty_sold'])."</td><td><a href='".$ventasUrl."'>Ver</a></td></tr>";
}
echo "</table>";
page_footer();
