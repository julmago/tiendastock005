<?php
require __DIR__.'/../../config.php';
csrf_check();
require_role('seller','/vendedor/login.php');

header('Content-Type: application/json; charset=utf-8');

$productId = (int)($_POST['product_id'] ?? 0);
$linkedId = (int)($_POST['linked_product_id'] ?? 0);

if (!$productId || !$linkedId) {
  http_response_code(400);
  echo json_encode(['error' => 'Datos incompletos.']);
  exit;
}

$sellerSt = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$sellerSt->execute([(int)($_SESSION['uid'] ?? 0)]);
$seller = $sellerSt->fetch();
if (!$seller) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}

$st = $pdo->prepare("SELECT sp.id FROM store_products sp JOIN stores s ON s.id=sp.store_id WHERE sp.id=? AND s.seller_id=? LIMIT 1");
$st->execute([$productId, (int)$seller['id']]);
if (!$st->fetch()) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}

$existsSt = $pdo->prepare("
  SELECT sps.id
  FROM store_product_sources sps
  JOIN provider_products pp ON pp.id = sps.provider_product_id
  LEFT JOIN providers p ON p.id = pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE sps.store_product_id = ? AND sps.provider_product_id = ? AND sps.enabled = 1
  LIMIT 1
");
$existsSt->execute([$productId, $linkedId]);
$existingLink = $existsSt->fetch();
// Log temporal para verificar IDs y resultado de la validación de vínculo.
error_log(sprintf('[link_product] validate link store_product_id=%d provider_product_id=%d exists=%s', $productId, $linkedId, $existingLink ? '1' : '0'));
if ($existingLink) {
  http_response_code(409);
  echo json_encode(['error' => 'Ya está vinculado.']);
  exit;
}

$ppSt = $pdo->prepare("
  SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name AS provider_name,
         COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE pp.id=? AND pp.status='active' AND p.status='active'
  GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name
  HAVING stock > 0
  LIMIT 1
");
$ppSt->execute([$linkedId]);
$pp = $ppSt->fetch();
if (!$pp) {
  http_response_code(400);
  echo json_encode(['error' => 'Producto proveedor inválido o sin stock.']);
  exit;
}

try {
  $pdo->prepare("INSERT INTO store_product_sources(store_product_id,provider_product_id,enabled) VALUES(?,?,1)")
      ->execute([$productId, $linkedId]);
} catch (Throwable $e) {
  $existsSt->execute([$productId, $linkedId]);
  $existingLink = $existsSt->fetch();
  // Log temporal para confirmar si el error es por vínculo existente.
  error_log(sprintf('[link_product] insert failed store_product_id=%d provider_product_id=%d exists=%s', $productId, $linkedId, $existingLink ? '1' : '0'));
  if ($existingLink) {
    http_response_code(409);
    echo json_encode(['error' => 'Ya está vinculado.']);
    exit;
  }
  http_response_code(500);
  echo json_encode(['error' => 'No se pudo vincular.']);
  exit;
}

$response = [
  'ok' => true,
  'item' => [
    'id' => (int)$pp['id'],
    'title' => (string)$pp['title'],
    'sku' => (string)($pp['sku'] ?? ''),
    'universal_code' => (string)($pp['universal_code'] ?? ''),
    'price' => $pp['base_price'] !== null ? (float)$pp['base_price'] : null,
    'provider_name' => (string)($pp['provider_name'] ?? ''),
    'stock' => (int)$pp['stock'],
  ],
];

echo json_encode($response);
