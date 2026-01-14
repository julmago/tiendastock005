<?php
require __DIR__.'/../../config.php';
require_role('seller','/vendedor/login.php');

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
  echo json_encode(['items' => []]);
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

$like = "%{$q}%";
$params = [$like, $like, $like];

$sql = "
  SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.description, pp.base_price,
         p.display_name AS provider_name,
         COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE pp.status='active' AND p.status='active'
    AND (pp.title LIKE ? OR pp.sku LIKE ? OR pp.universal_code LIKE ?)
  GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.description, pp.base_price, p.display_name
  HAVING stock > 0
  ORDER BY pp.id DESC
  LIMIT 20
";

$searchSt = $pdo->prepare($sql);
$searchSt->execute($params);
$items = $searchSt->fetchAll();

$out = [];
foreach ($items as $item) {
  $out[] = [
    'id' => (int)$item['id'],
    'provider_name' => (string)($item['provider_name'] ?? ''),
    'title' => (string)($item['title'] ?? ''),
    'sku' => (string)($item['sku'] ?? ''),
    'universal_code' => (string)($item['universal_code'] ?? ''),
    'stock' => (int)($item['stock'] ?? 0),
    'price' => $item['base_price'] !== null ? (float)$item['base_price'] : null,
    'description' => (string)($item['description'] ?? ''),
  ];
}

echo json_encode(['items' => $out]);
