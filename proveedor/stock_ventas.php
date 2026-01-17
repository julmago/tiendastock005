<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$provider = $st->fetch();
if (!$provider) exit('Proveedor inválido');

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) exit('Producto inválido');

$productSt = $pdo->prepare("SELECT id, title FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
$productSt->execute([$productId, (int)$provider['id']]);
$product = $productSt->fetch();
if (!$product) exit('Producto inválido');

$salesSt = $pdo->prepare("SELECT o.id AS order_id, o.created_at, oa.qty_allocated, oa.unit_base_price,
                                 sel.display_name AS seller_name, sel.account_type AS seller_account_type
                           FROM order_allocations oa
                           JOIN order_items oi ON oi.id=oa.order_item_id
                           JOIN orders o ON o.id=oi.order_id
                           JOIN stores s ON s.id=o.store_id
                           JOIN sellers sel ON sel.id=s.seller_id
                           WHERE oa.provider_product_id=?
                           ORDER BY o.id DESC, oa.id DESC");
$salesSt->execute([$productId]);
$sales = $salesSt->fetchAll();

page_header('Proveedor - Ventas del producto');
echo "<h3>Ventas de ".h($product['title'])."</h3>";
echo "<p><a href='/proveedor/stock.php'>&larr; Volver al stock</a></p>";

if (!$sales) {
  echo "<p>No hay ventas registradas para este producto.</p>";
  page_footer();
  exit;
}

echo "<table border='1' cellpadding='6' cellspacing='0'>
        <tr><th>Pedido</th><th>Vendedor</th><th>Tipo</th><th>Fecha</th><th>Cantidad</th><th>Precio base</th></tr>";
foreach ($sales as $sale) {
  $sellerType = ($sale['seller_account_type'] ?? 'retail') === 'wholesale' ? 'Mayorista' : 'Minorista';
  echo "<tr><td>".h((string)$sale['order_id'])."</td><td>".h($sale['seller_name'] ?? '')."</td><td>".h($sellerType)."</td><td>".h($sale['created_at'] ?? '')."</td><td>".h((string)$sale['qty_allocated'])."</td><td>".h((string)$sale['unit_base_price'])."</td></tr>";
}
echo "</table>";

page_footer();
