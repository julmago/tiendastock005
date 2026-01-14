<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
$slug = slugify((string)($_GET['slug'] ?? ''));
$orderId = (int)($_GET['order_id'] ?? 0);
page_header('Gracias');
echo "<p>Pedido creado. ID: <b>".h((string)$orderId)."</b></p>";
echo "<p><a href='/mayorista/".'".h($slug)."/'>Volver a la tienda</a></p>";
page_footer();
