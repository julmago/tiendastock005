<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
csrf_check();

$BASE = '/mayorista/';
$STORE_TYPE = 'wholesale';

$slug = slugify((string)($_GET['slug'] ?? ''));
if (!$slug) { header("Location: ".$BASE); exit; }

$st = $pdo->prepare("SELECT * FROM stores WHERE slug=? AND status='active' AND store_type=? LIMIT 1");
$st->execute([$slug, $STORE_TYPE]);
$store = $st->fetch();
if (!$store) { http_response_code(404); exit('Tienda no encontrada'); }

$cartKey = 'cart_'.$store['id'];
$cart = $_SESSION[$cartKey] ?? [];
if (!$cart) { header("Location: ".$BASE.$slug."/"); exit; }

$deliveryKey = 'delivery_'.$store['id'];
$deliveryMethods = [
  'retiro' => 'Retiro en tienda',
  'envio' => 'Envío a domicilio',
];
$deliverySelected = (string)($_SESSION[$deliveryKey] ?? '');
if (!$deliverySelected || !array_key_exists($deliverySelected, $deliveryMethods)) {
  header("Location: ".$BASE.$slug."/?delivery_error=1"); exit;
}

$ids = array_keys($cart);
$in = implode(",", array_fill(0, count($ids), "?"));
$stc = $pdo->prepare("SELECT * FROM store_products WHERE id IN ($in)");
$stc->execute($ids);
$products = $stc->fetchAll();
$map = []; foreach($products as $p) $map[$p['id']] = $p;

$methods = $pdo->prepare("SELECT method, enabled, extra_percent FROM store_payment_methods WHERE store_id=? AND enabled=1");
$methods->execute([(int)$store['id']]);
$payMethods = $methods->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $method = (string)($_POST['payment_method'] ?? '');
  $valid = false; $extraPercent = 0.0;
  foreach($payMethods as $m){
    if ($m['method']===$method){ $valid=true; $extraPercent=(float)$m['extra_percent']; }
  }
  if (!$valid) $err="Elegí un medio de pago válido.";
  else {
    $itemsTotal = 0.0;
    $lines = [];
    foreach($cart as $pid=>$qty){
      $p = $map[$pid] ?? null;
      if (!$p) continue;
      $price = current_sell_price($pdo, $store, $p);
      if ($price <= 0) { $err="El producto '".$p['title']."' no tiene stock."; break; }
      $stock = provider_stock_sum($pdo, (int)$p['id']) + (int)$p['own_stock_qty'];
      if ($stock < (int)$qty) { $err="Stock insuficiente para '".$p['title']."'."; break; }
      $itemsTotal += ($price * (int)$qty);
      $lines[] = ['pid'=>(int)$pid,'qty'=>(int)$qty,'price'=>$price,'title'=>$p['title']];
    }

    if (empty($err) && $itemsTotal>0) {
      $sellerFeePercent = (float)setting($pdo,'seller_fee_percent','3');
      $providerFeePercent = (float)setting($pdo,'provider_fee_percent','1');

      $sellerFee = $itemsTotal * ($sellerFeePercent/100.0);
      $mpExtra = ($method==='mercadopago') ? ($itemsTotal * ($extraPercent/100.0)) : 0.0;
      $grand = $itemsTotal + $mpExtra;

      $pdo->beginTransaction();
      try {
        $pdo->prepare("INSERT INTO orders(store_id,status,payment_method,payment_status,items_total,grand_total,seller_fee_amount,provider_fee_amount,mp_extra_amount)
                      VALUES(?, 'created', ?, 'pending', ?, ?, ?, 0, ?)")
            ->execute([(int)$store['id'], $method, $itemsTotal, $grand, $sellerFee, $mpExtra]);
        $orderId = (int)$pdo->lastInsertId();

        $providerFeeTotal = 0.0;

        foreach($lines as $ln){
          $pid = (int)$ln['pid'];
          $qty = (int)$ln['qty'];
          $p = $map[$pid];

          $best = best_provider_source($pdo, (int)$pid);

          if ($best) {
            $basePrice = (float)$best['base_price'];
            $bestPP = (int)$best['provider_product_id'];

            $upd = $pdo->prepare("UPDATE warehouse_stock
                                  SET qty_available = qty_available - ?
                                  WHERE provider_product_id=? AND (qty_available - qty_reserved) >= ?");
            $upd->execute([$qty, $bestPP, $qty]);
            if ($upd->rowCount() === 0) throw new Exception("Sin stock en bodega para '".$ln['title']."'.");

            $pdo->prepare("INSERT INTO order_items(order_id,store_product_id,qty,unit_sell_price,line_total,unit_base_price)
                          VALUES(?,?,?,?,?,?)")
                ->execute([$orderId, $pid, $qty, $ln['price'], ($ln['price']*$qty), $basePrice]);
            $itemId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO order_allocations(order_item_id,source_type,provider_product_id,qty_allocated,unit_base_price)
                          VALUES(?, 'provider', ?, ?, ?)")
                ->execute([$itemId, $bestPP, $qty, $basePrice]);

            $providerFeeTotal += ($basePrice * $qty) * ($providerFeePercent/100.0);
          } else {
            $upd = $pdo->prepare("UPDATE store_products SET own_stock_qty = own_stock_qty - ? WHERE id=? AND own_stock_qty >= ?");
            $upd->execute([$qty, $pid, $qty]);
            if ($upd->rowCount() === 0) throw new Exception("Sin stock propio para '".$ln['title']."'.");

            $basePrice = (float)($p['own_stock_price'] ?? 0);

            $pdo->prepare("INSERT INTO order_items(order_id,store_product_id,qty,unit_sell_price,line_total,unit_base_price)
                          VALUES(?,?,?,?,?,?)")
                ->execute([$orderId, $pid, $qty, $ln['price'], ($ln['price']*$qty), $basePrice]);
            $itemId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO order_allocations(order_item_id,source_type,provider_product_id,qty_allocated,unit_base_price)
                          VALUES(?, 'seller_own', NULL, ?, ?)")
                ->execute([$itemId, $qty, $basePrice]);
          }
        }

        $pdo->prepare("UPDATE orders SET provider_fee_amount=? WHERE id=?")->execute([$providerFeeTotal, $orderId]);
        $pdo->prepare("INSERT INTO payments(order_id,method,status,amount) VALUES(?,?, 'pending', ?)")->execute([$orderId,$method,$grand]);

        $sid = $pdo->prepare("SELECT seller_id FROM stores WHERE id=?");
        $sid->execute([(int)$store['id']]);
        $sellerId = (int)($sid->fetch()['seller_id'] ?? 0);

        $pdo->prepare("INSERT INTO fees_ledger(order_id,store_id,seller_id,provider_id,fee_type,amount) VALUES(?,?,?,?,?,?)")
            ->execute([$orderId,(int)$store['id'],$sellerId,NULL,'seller_fee',$sellerFee]);

        if ($providerFeeTotal>0) {
          $pdo->prepare("INSERT INTO fees_ledger(order_id,store_id,seller_id,provider_id,fee_type,amount) VALUES(?,?,?,?,?,?)")
              ->execute([$orderId,(int)$store['id'],$sellerId,NULL,'provider_fee',$providerFeeTotal]);
        }
        if ($mpExtra>0) {
          $pdo->prepare("INSERT INTO fees_ledger(order_id,store_id,seller_id,provider_id,fee_type,amount) VALUES(?,?,?,?,?,?)")
              ->execute([$orderId,(int)$store['id'],$sellerId,NULL,'mp_extra',$mpExtra]);
        }

        $pdo->commit();
        $_SESSION[$cartKey] = [];
        header("Location: ".$BASE."thanks.php?slug=".$slug."&order_id=".$orderId);
        exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $err = "Error: ".$e->getMessage();
      }
    }
  }
}

page_header("Checkout - ".$store['name']);
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<p><a href='".$BASE.$slug."/'>← volver</a></p>";

$itemsTotal = 0.0;
echo "<h3>Resumen</h3><ul>";
foreach($cart as $pid=>$qty){
  $p = $map[$pid] ?? null; if (!$p) continue;
  $price = current_sell_price($pdo, $store, $p);
  $sub = $price * (int)$qty;
  $itemsTotal += $sub;
  echo "<li>".h($p['title'])." x ".h((string)$qty)." = $".number_format($sub,2,',','.')."</li>";
}
echo "</ul><p><b>Total:</b> $".number_format($itemsTotal,2,',','.')."</p>";

echo "<h3>Medio de pago</h3><form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<select name='payment_method'>";
foreach($payMethods as $m){
  echo "<option value='".h($m['method'])."'>".h($m['method'])."</option>";
}
echo "</select> <button>Confirmar</button></form>";

page_footer();
