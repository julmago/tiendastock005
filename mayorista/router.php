<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';

$BASE = '/mayorista/';
$STORE_TYPE = 'wholesale';

$slug = slugify((string)($_GET['slug'] ?? ''));
if (!$slug) { header("Location: ".$BASE); exit; }

$st = $pdo->prepare("SELECT * FROM stores WHERE slug=? AND status='active' AND store_type=? LIMIT 1");
$st->execute([$slug, $STORE_TYPE]);
$store = $st->fetch();
if (!$store) { http_response_code(404); exit('Tienda no encontrada'); }

$cartKey = 'cart_'.$store['id'];
if (!isset($_SESSION[$cartKey])) $_SESSION[$cartKey] = [];

if (isset($_GET['add'])) {
  $pid = (int)($_GET['add'] ?? 0);
  if ($pid>0) $_SESSION[$cartKey][$pid] = ($_SESSION[$cartKey][$pid] ?? 0) + 1;
  header("Location: ".$BASE.$slug."/"); exit;
}
if (isset($_GET['del'])) {
  $pid = (int)($_GET['del'] ?? 0);
  unset($_SESSION[$cartKey][$pid]);
  header("Location: ".$BASE.$slug."/"); exit;
}

page_header('Tienda: '.$store['name']);
echo "<p><a href='".$BASE."'>← todas las tiendas</a></p>";

$stp = $pdo->prepare("SELECT * FROM store_products WHERE store_id=? AND status='active' ORDER BY id DESC");
$stp->execute([(int)$store['id']]);
$products = $stp->fetchAll();

echo "<h3>Productos</h3>";
if (!$products) echo "<p>Sin productos.</p>";
else {
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Título</th><th>Precio</th><th>Stock</th><th></th></tr>";
  foreach($products as $p){
    $price = current_sell_price($pdo, $store, $p);
    $provStock = provider_stock_sum($pdo, (int)$p['id']);
    $stock = $provStock + (int)$p['own_stock_qty'];
    $priceTxt = $price>0 ? "$".number_format($price,2,',','.') : "Sin stock";
    echo "<tr>
      <td>".h($p['title'])."</td>
      <td>".h($priceTxt)."</td>
      <td>".h((string)$stock)."</td>
      <td>".(($price>0 && $stock>0) ? "<a href='?slug=".h($slug)."&add=".h((string)$p['id'])."'>Agregar</a>" : "")."</td>
    </tr>";
  }
  echo "</table>";
}

$cart = $_SESSION[$cartKey];
echo "<h3>Carrito</h3>";
if (!$cart) {
  echo "<p>Vacío</p>";
} else {
  $ids = array_keys($cart);
  $in = implode(",", array_fill(0, count($ids), "?"));
  $stc = $pdo->prepare("SELECT * FROM store_products WHERE id IN ($in)");
  $stc->execute($ids);
  $map = [];
  foreach($stc->fetchAll() as $p) $map[$p['id']] = $p;

  $itemsTotal = 0.0;
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Producto</th><th>Cant</th><th>Precio</th><th>Subtotal</th><th></th></tr>";
  foreach($cart as $pid=>$qty){
    $p = $map[$pid] ?? null;
    if (!$p) continue;
    $price = current_sell_price($pdo, $store, $p);
    $sub = $price * (int)$qty;
    $itemsTotal += $sub;
    echo "<tr>
      <td>".h($p['title'])."</td>
      <td>".h((string)$qty)."</td>
      <td>$".number_format($price,2,',','.')."</td>
      <td>$".number_format($sub,2,',','.')."</td>
      <td><a href='?slug=".h($slug)."&del=".h((string)$pid)."'>Quitar</a></td>
    </tr>";
  }
  echo "</table>";
  echo "<p><b>Total:</b> $".number_format($itemsTotal,2,',','.')."</p>";
  echo "<p><a href='".$BASE."checkout.php?slug=".h($slug)."'>Ir a pagar</a></p>";
}

page_footer();
