<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$orderSt = $pdo->prepare("SELECT o.id, o.customer_first_name, o.customer_last_name, o.grand_total, o.created_at,
                                 s.name AS store_name
                          FROM orders o
                          JOIN stores s ON o.store_id = s.id
                          WHERE s.seller_id = ?
                          ORDER BY o.id DESC");
$orderSt->execute([(int)$seller['id']]);
$orders = $orderSt->fetchAll();

page_header('Vendedor - Pedidos');

if (!$orders) {
  echo "<p>Sin pedidos por ahora.</p>";
  page_footer();
  exit;
}

echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>ID</th><th>Nombre</th><th>Total</th><th>Fecha</th><th>Acciones</th></tr>";
foreach ($orders as $order) {
  $fullName = trim(($order['customer_first_name'] ?? '').' '.($order['customer_last_name'] ?? ''));
  $total = '$'.number_format((float)$order['grand_total'], 2, ',', '.');
  $date = h($order['created_at'] ?? '');
  $viewUrl = "/vendedor/pedido.php?id=".h((string)$order['id']);
  echo "<tr>
    <td>".h((string)$order['id'])."</td>
    <td>".h($fullName)."</td>
    <td>".h($total)."</td>
    <td>".$date."</td>
    <td><a href='".$viewUrl."'>Ver</a></td>
  </tr>";
}
echo "</table>";

page_footer();
