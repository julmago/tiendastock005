<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__.'/config.php';
require __DIR__.'/lib/xlsx_writer.php';

require_role('provider', '/proveedor/login.php');

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) {
  http_response_code(400);
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo 'No se pudo generar XLSX';
  exit;
}

$providerSt = $pdo->prepare("SELECT id FROM providers WHERE user_id=? LIMIT 1");
$providerSt->execute([(int)$_SESSION['uid']]);
$provider = $providerSt->fetch();
if (!$provider) {
  http_response_code(403);
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo 'No se pudo generar XLSX';
  exit;
}

$productSt = $pdo->prepare("SELECT id, title FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
$productSt->execute([$productId, (int)$provider['id']]);
$product = $productSt->fetch();
if (!$product) {
  http_response_code(404);
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo 'No se pudo generar XLSX';
  exit;
}

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

$rows = [
  ['Pedido', 'Vendedor', 'Tipo', 'Fecha', 'Cantidad', 'Precio base'],
];

foreach ($sales as $sale) {
  $sellerType = ($sale['seller_account_type'] ?? 'retail') === 'wholesale' ? 'Mayorista' : 'Minorista';
  $rows[] = [
    (string)$sale['order_id'],
    (string)($sale['seller_name'] ?? ''),
    $sellerType,
    (string)($sale['created_at'] ?? ''),
    (string)$sale['qty_allocated'],
    (string)$sale['unit_base_price'],
  ];
}

try {
  $xlsx = xlsx_generate($rows);
} catch (Throwable $e) {
  http_response_code(500);
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo 'No se pudo generar XLSX';
  exit;
}

while (ob_get_level() > 0) { ob_end_clean(); }

$filename = 'listado_'.$productId.'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.strlen($xlsx));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo $xlsx;
exit;
