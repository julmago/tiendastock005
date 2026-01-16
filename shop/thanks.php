<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/store_auth.php';
$slug = slugify((string)($_GET['slug'] ?? ''));
$orderId = (int)($_GET['order_id'] ?? 0);
$store = null;
if ($slug) {
  $st = $pdo->prepare("SELECT * FROM stores WHERE slug=? AND status='active' AND store_type=? LIMIT 1");
  $st->execute([$slug, 'retail']);
  $store = $st->fetch();
}
if ($store) {
  $customer = store_customer_current($pdo);
  $GLOBALS['STORE_AUTH_HTML'] = store_auth_links($store, '/shop/', $slug, $customer);
}
page_header('Gracias');
echo "<p>Pedido creado. ID: <b>".h((string)$orderId)."</b></p>";
echo "<p><a href='/shop/".h($slug)."/'>Volver a la tienda</a></p>";
page_footer();
