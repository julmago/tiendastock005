<?php
require __DIR__.'/../../config.php';
require_role('seller','/vendedor/login.php');

header('Content-Type: application/json; charset=utf-8');

$productId = (int)($_GET['product_id'] ?? 0);
$variantId = (int)($_GET['variant_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$providerId = (int)($_GET['provider_id'] ?? 0);

if (!$productId) {
  http_response_code(400);
  echo json_encode(['error' => 'Producto inválido.']);
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

$st = $pdo->prepare("SELECT sp.id, sp.store_id FROM store_products sp JOIN stores s ON s.id=sp.store_id WHERE sp.id=? AND s.seller_id=? LIMIT 1");
$st->execute([$productId, (int)$seller['id']]);
$storeRow = $st->fetch();
if (!$storeRow) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}
$storeId = (int)$storeRow['store_id'];

if ($variantId > 0) {
  $variantSt = $pdo->prepare("SELECT id FROM product_variants WHERE id=? AND owner_type='vendor' AND owner_id=? AND product_id=? LIMIT 1");
  $variantSt->execute([$variantId, $storeId, $productId]);
  if (!$variantSt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Variante inválida.']);
    exit;
  }
}

if ($q === '' || mb_strlen($q) < 2) {
  echo json_encode([
    'items' => [],
    'empty_reason' => 'no_results',
  ]);
  exit;
}

$like = "%{$q}%";
$prefix = "{$q}%";
$sourceTable = 'store_product_sources';
$sourceCondition = 'sps.store_product_id = ?';
$sourceId = $productId;
if ($variantId > 0) {
  $sourceTable = 'store_variant_sources';
  $sourceCondition = 'sps.variant_id = ?';
  $sourceId = $variantId;
}
$params = [$sourceId, $like, $like, $like];
$conditions = [
  'pp.title LIKE ?',
  'pp.sku LIKE ?',
  'pp.universal_code LIKE ?',
];
$providerFilter = '';
if ($providerId > 0) {
  $providerFilter = ' AND pp.provider_id = ?';
  $params[] = $providerId;
}
$orderParts = [];
$orderParams = [];
$isUniversalCode = preg_match('/^\d{8,14}$/', $q) === 1;
if ($isUniversalCode) {
  $conditions[] = 'pp.universal_code = ?';
  $params[] = $q;
  $orderParts[] = '(pp.universal_code = ?) DESC';
  $orderParams[] = $q;
}
$orderParts[] = '(pp.title = ?) DESC';
$orderParts[] = '(pp.title LIKE ?) DESC';
$orderParts[] = 'pp.title ASC';
$orderParams[] = $q;
$orderParams[] = $prefix;

$sql = "
  SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name AS provider_name,
         COALESCE(SUM(
           CASE
             WHEN pv.variant_count > 0 THEN pv.variant_stock
             ELSE GREATEST(ws.qty_available - ws.qty_reserved,0)
           END
         ),0) AS stock
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  LEFT JOIN (
    SELECT product_id, owner_id, COUNT(*) AS variant_count, COALESCE(SUM(stock_qty),0) AS variant_stock
    FROM product_variants
    WHERE owner_type='provider'
    GROUP BY product_id, owner_id
  ) pv ON pv.product_id = pp.id AND pv.owner_id = pp.provider_id
  LEFT JOIN {$sourceTable} sps
    ON sps.provider_product_id = pp.id AND {$sourceCondition} AND sps.enabled=1
  WHERE pp.status='active' AND p.status='active'
    AND sps.id IS NULL
    AND (".implode(' OR ', $conditions).")
    {$providerFilter}
  GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name
  HAVING stock > 0
  ORDER BY ".implode(', ', $orderParts)."
  LIMIT 20
";

$searchSt = $pdo->prepare($sql);
$searchSt->execute(array_merge($params, $orderParams));
$items = $searchSt->fetchAll();

$out = [];
foreach ($items as $item) {
  $out[] = [
    'id' => (int)$item['id'],
    'title' => (string)$item['title'],
    'sku' => (string)($item['sku'] ?? ''),
    'universal_code' => (string)($item['universal_code'] ?? ''),
    'price' => $item['base_price'] !== null ? (float)$item['base_price'] : null,
    'provider_name' => (string)($item['provider_name'] ?? ''),
    'stock' => (int)$item['stock'],
  ];
}

$emptyReason = '';
if (!$out) {
  $checkSql = "
    SELECT pp.id
    FROM provider_products pp
    JOIN providers p ON p.id=pp.provider_id
    LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
    LEFT JOIN (
      SELECT product_id, owner_id, COUNT(*) AS variant_count, COALESCE(SUM(stock_qty),0) AS variant_stock
      FROM product_variants
      WHERE owner_type='provider'
      GROUP BY product_id, owner_id
    ) pv ON pv.product_id = pp.id AND pv.owner_id = pp.provider_id
    LEFT JOIN {$sourceTable} sps
      ON sps.provider_product_id = pp.id AND {$sourceCondition} AND sps.enabled=1
    WHERE pp.status='active' AND p.status='active'
      AND sps.id IS NULL
    GROUP BY pp.id
    HAVING COALESCE(SUM(
      CASE
        WHEN pv.variant_count > 0 THEN pv.variant_stock
        ELSE GREATEST(ws.qty_available - ws.qty_reserved,0)
      END
    ),0) > 0
    LIMIT 1
  ";
  $checkSt = $pdo->prepare($checkSql);
  $checkSt->execute([$sourceId]);
  $emptyReason = $checkSt->fetch() ? 'no_results' : 'all_linked';
}

echo json_encode([
  'items' => $out,
  'empty_reason' => $emptyReason,
]);
