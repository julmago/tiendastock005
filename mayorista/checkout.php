<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
require __DIR__.'/../_inc/store_auth.php';
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
$postalKey = 'postal_code_'.$store['id'];
$deliveryRows = $pdo->query("SELECT id, name, delivery_time, price FROM delivery_methods WHERE status='active' ORDER BY position ASC, id ASC")->fetchAll();
$deliveryMethods = [];
foreach ($deliveryRows as $row) {
  $deliveryMethods[(int)$row['id']] = $row;
}
$deliverySelected = (int)($_SESSION[$deliveryKey] ?? 0);
$deliverySelectedMethod = $deliverySelected ? ($deliveryMethods[$deliverySelected] ?? null) : null;
if (!$deliverySelectedMethod) {
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

$customer = store_customer_current($pdo, (int)$store['id']);
$postalSaved = (string)($_SESSION[$postalKey] ?? '');
if ($postalSaved === '' && $customer) $postalSaved = (string)$customer['postal_code'];
$formData = [
  'email' => (string)($_POST['email'] ?? ($customer['email'] ?? '')),
  'first_name' => (string)($_POST['first_name'] ?? ($customer['first_name'] ?? '')),
  'last_name' => (string)($_POST['last_name'] ?? ($customer['last_name'] ?? '')),
  'phone' => (string)($_POST['phone'] ?? ($customer['phone'] ?? '')),
  'postal_code' => (string)($_POST['postal_code'] ?? $postalSaved),
  'street' => (string)($_POST['street'] ?? ($customer['street'] ?? '')),
  'street_number' => (string)($_POST['street_number'] ?? (($customer && (int)$customer['street_number_sn'] === 0) ? (string)$customer['street_number'] : '')),
  'street_number_sn' => isset($_POST['street_number_sn']) ? true : (($customer && (int)$customer['street_number_sn'] === 1) ? true : false),
  'apartment' => (string)($_POST['apartment'] ?? ($customer['apartment'] ?? '')),
  'neighborhood' => (string)($_POST['neighborhood'] ?? ($customer['neighborhood'] ?? '')),
  'document_id' => (string)($_POST['document_id'] ?? ($customer['document_id'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $customerEmail = trim((string)($_POST['email'] ?? ''));
  $customerFirst = trim((string)($_POST['first_name'] ?? ''));
  $customerLast = trim((string)($_POST['last_name'] ?? ''));
  $customerPhone = trim((string)($_POST['phone'] ?? ''));
  $customerPostal = trim((string)($_POST['postal_code'] ?? ''));
  $customerStreet = trim((string)($_POST['street'] ?? ''));
  $customerStreetNumberSn = isset($_POST['street_number_sn']) ? 1 : 0;
  $customerStreetNumber = $customerStreetNumberSn ? 'SN' : trim((string)($_POST['street_number'] ?? ''));
  $customerApartment = trim((string)($_POST['apartment'] ?? ''));
  $customerNeighborhood = trim((string)($_POST['neighborhood'] ?? ''));
  $customerDocument = trim((string)($_POST['document_id'] ?? ''));

  if (!$customerEmail || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    $err = "Ingresá un email válido.";
  } elseif ($customerFirst === '') {
    $err = "Ingresá tu nombre.";
  } elseif ($customerLast === '') {
    $err = "Ingresá tu apellido.";
  } elseif ($customerPhone === '') {
    $err = "Ingresá tu teléfono.";
  } elseif ($customerPostal === '' || !preg_match('/^\\d{1,4}$/', $customerPostal)) {
    $err = "Ingresá un código postal válido (solo números, hasta 4).";
  } elseif ($customerStreet === '') {
    $err = "Ingresá tu calle.";
  } elseif (!$customerStreetNumberSn && $customerStreetNumber === '') {
    $err = "Ingresá el número de tu domicilio o marcá Sin número.";
  } elseif ($customerDocument === '') {
    $err = "Ingresá DNI o CUIT.";
  }
  if (empty($err) && $customer && $customerEmail !== (string)$customer['email']) {
    $existing = store_customer_find($pdo, (int)$store['id'], $customerEmail);
    if ($existing && (int)$existing['id'] !== (int)$customer['id']) {
      $err = "Ya existe una cuenta con ese email.";
    }
  }

  $method = (string)($_POST['payment_method'] ?? '');
  $valid = false; $extraPercent = 0.0;
  foreach($payMethods as $m){
    if ($m['method']===$method){ $valid=true; $extraPercent=(float)$m['extra_percent']; }
  }
  if (empty($err) && !$valid) $err="Elegí un medio de pago válido.";
  else if (empty($err)) {
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
      $_SESSION[$postalKey] = $customerPostal;
      $sellerFeePercent = (float)setting($pdo,'seller_fee_percent','3');
      $providerFeePercent = (float)setting($pdo,'provider_fee_percent','1');

      $sellerFee = $itemsTotal * ($sellerFeePercent/100.0);
      $mpExtra = ($method==='mercadopago') ? ($itemsTotal * ($extraPercent/100.0)) : 0.0;
      $deliveryPrice = (float)$deliverySelectedMethod['price'];
      $grand = $itemsTotal + $mpExtra + $deliveryPrice;

      $pdo->beginTransaction();
      try {
        $storeCustomerId = $customer ? (int)$customer['id'] : null;
        $pdo->prepare("INSERT INTO orders(store_id,store_customer_id,status,payment_method,payment_status,items_total,grand_total,seller_fee_amount,provider_fee_amount,mp_extra_amount,customer_email,customer_first_name,customer_last_name,customer_phone,customer_postal_code,customer_street,customer_street_number,customer_street_number_sn,customer_apartment,customer_neighborhood,customer_document_id)
                      VALUES(?, ?, 'created', ?, 'pending', ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
              (int)$store['id'],
              $storeCustomerId,
              $method,
              $itemsTotal,
              $grand,
              $sellerFee,
              $mpExtra,
              $customerEmail,
              $customerFirst,
              $customerLast,
              $customerPhone,
              $customerPostal,
              $customerStreet,
              $customerStreetNumber,
              $customerStreetNumberSn,
              $customerApartment !== '' ? $customerApartment : null,
              $customerNeighborhood !== '' ? $customerNeighborhood : null,
              $customerDocument
            ]);
        $orderId = (int)$pdo->lastInsertId();

        if ($customer) {
          $pdo->prepare("UPDATE store_customers
                        SET email=?, first_name=?, last_name=?, phone=?, postal_code=?, street=?, street_number=?, street_number_sn=?, apartment=?, neighborhood=?, document_id=?
                        WHERE id=? AND store_id=?")
              ->execute([
                $customerEmail,
                $customerFirst,
                $customerLast,
                $customerPhone,
                $customerPostal,
                $customerStreet,
                $customerStreetNumber,
                $customerStreetNumberSn,
                $customerApartment !== '' ? $customerApartment : null,
                $customerNeighborhood !== '' ? $customerNeighborhood : null,
                $customerDocument,
                (int)$customer['id'],
                (int)$store['id']
              ]);
          $updated = store_customer_find($pdo, (int)$store['id'], $customerEmail);
          if ($updated) store_customer_set_session($updated, (int)$store['id']);
        }

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

$GLOBALS['STORE_AUTH_HTML'] = store_auth_links($store, $BASE, $slug, $customer);
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
$deliveryPrice = (float)$deliverySelectedMethod['price'];
$grandTotal = $itemsTotal + $deliveryPrice;
echo "</ul>";
echo "<p><b>Forma de entrega:</b> ".h($deliverySelectedMethod['name'])." - ".h($deliverySelectedMethod['delivery_time'])." ($".number_format($deliveryPrice,2,',','.').")</p>";
echo "<p><b>Total:</b> $".number_format($itemsTotal,2,',','.')."</p>";
echo "<p><b>Total final:</b> $".number_format($grandTotal,2,',','.')."</p>";

echo "<h3>Datos del cliente</h3>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p><label>Email<br><input type='email' name='email' value='".h($formData['email'])."'></label></p>
<p><label>Nombre<br><input type='text' name='first_name' value='".h($formData['first_name'])."'></label></p>
<p><label>Apellido<br><input type='text' name='last_name' value='".h($formData['last_name'])."'></label></p>
<p><label>Teléfono<br><input type='text' name='phone' value='".h($formData['phone'])."'></label></p>
<p><label>Código postal<br><input type='text' name='postal_code' value='".h($formData['postal_code'])."' maxlength='4' inputmode='numeric' pattern='\\d{1,4}'></label></p>
<p><label>Calle<br><input type='text' name='street' value='".h($formData['street'])."'></label></p>
<p><label>Número<br><input id='street_number' type='text' name='street_number' value='".h($formData['street_number'])."'></label>
<label><input id='street_number_sn' type='checkbox' name='street_number_sn' value='1'".($formData['street_number_sn'] ? " checked" : "")."> Sin número</label></p>
<p><label>Departamento (opcional)<br><input type='text' name='apartment' value='".h($formData['apartment'])."'></label></p>
<p><label>Barrio (opcional)<br><input type='text' name='neighborhood' value='".h($formData['neighborhood'])."'></label></p>
<p><label>DNI o CUIT<br><input type='text' name='document_id' value='".h($formData['document_id'])."'></label></p>
<h3>Medio de pago</h3>
<select name='payment_method'>";
foreach($payMethods as $m){
  echo "<option value='".h($m['method'])."'>".h($m['method'])."</option>";
}
echo "</select> <button>Confirmar</button></form>
<script>
const snBox = document.getElementById('street_number_sn');
const snInput = document.getElementById('street_number');
if (snBox && snInput) {
  const toggleSn = () => {
    if (snBox.checked) {
      snInput.value = '';
      snInput.disabled = true;
    } else {
      snInput.disabled = false;
    }
  };
  snBox.addEventListener('change', toggleSn);
  toggleSn();
}
</script>";

page_footer();
