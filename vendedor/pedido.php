<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('seller','/vendedor/login.php');

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
  page_header('Pedido');
  echo "<p>Pedido inválido.</p>";
  page_footer();
  exit;
}

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$orderSt = $pdo->prepare("SELECT o.*, s.name AS store_name
                          FROM orders o
                          JOIN stores s ON o.store_id = s.id
                          WHERE o.id = ? AND s.seller_id = ?
                          LIMIT 1");
$orderSt->execute([$orderId, (int)$seller['id']]);
$order = $orderSt->fetch();

page_header('Vendedor - Pedido');

if (!$order) {
  echo "<p>Pedido no encontrado.</p>";
  page_footer();
  exit;
}

$itemSt = $pdo->prepare("SELECT oi.qty, oi.unit_sell_price, oi.line_total, sp.title
                         FROM order_items oi
                         JOIN store_products sp ON oi.store_product_id = sp.id
                         WHERE oi.order_id = ?
                         ORDER BY oi.id ASC");
$itemSt->execute([$orderId]);
$items = $itemSt->fetchAll();

$fullName = trim(($order['customer_first_name'] ?? '').' '.($order['customer_last_name'] ?? ''));
$address = trim(($order['customer_street'] ?? '').' '.($order['customer_street_number'] ?? ''));
if (!empty($order['customer_street_number_sn'])) {
  $address = trim(($order['customer_street'] ?? '').' S/N');
}
if (!empty($order['customer_apartment'])) {
  $address .= ' '.trim((string)$order['customer_apartment']);
}
$addressExtra = trim(($order['customer_neighborhood'] ?? '').' '.($order['customer_postal_code'] ?? ''));

$itemsTotal = '$'.number_format((float)$order['items_total'], 2, ',', '.');
$deliveryTotal = '$'.number_format((float)$order['delivery_price'], 2, ',', '.');
$grandTotal = '$'.number_format((float)$order['grand_total'], 2, ',', '.');

$backUrl = "/vendedor/pedidos.php";

echo "<p><a href='".$backUrl."'>Volver al listado</a></p>";

echo "<h3>Detalle del pedido #".h((string)$order['id'])."</h3>";
if ($items) {
  echo "<table border='1' cellpadding='6' cellspacing='0'>
    <tr><th>Producto</th><th>Cantidad</th><th>Precio unitario</th><th>Subtotal</th></tr>";
  foreach ($items as $item) {
    $unitPrice = '$'.number_format((float)$item['unit_sell_price'], 2, ',', '.');
    $lineTotal = '$'.number_format((float)$item['line_total'], 2, ',', '.');
    echo "<tr>
      <td>".h($item['title'] ?? '')."</td>
      <td>".h((string)$item['qty'])."</td>
      <td>".h($unitPrice)."</td>
      <td>".h($lineTotal)."</td>
    </tr>";
  }
  echo "</table>";
} else {
  echo "<p>Sin items en este pedido.</p>";
}

echo "<h3>Totales</h3>";
echo "<ul>
  <li>Items: ".$itemsTotal."</li>
  <li>Envío: ".$deliveryTotal."</li>
  <li>Total: ".$grandTotal."</li>
</ul>";

echo "<h3>Datos del cliente</h3>";
echo "<ul>
  <li>Nombre: ".h($fullName)."</li>
  <li>Email: ".h($order['customer_email'] ?? '')."</li>
  <li>Teléfono: ".h($order['customer_phone'] ?? '')."</li>
  <li>Dirección: ".h($address)."</li>";
if ($addressExtra !== '') {
  echo "<li>Zona / CP: ".h($addressExtra)."</li>";
}
echo "<li>Documento: ".h($order['customer_document_id'] ?? '')."</li>
</ul>";

page_footer();
