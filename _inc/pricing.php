<?php
function best_provider_source(PDO $pdo, int $store_product_id): ?array {
  $st = $pdo->prepare("
    SELECT sps.provider_product_id, pp.base_price,
           (ws.qty_available - ws.qty_reserved) AS available
    FROM store_product_sources sps
    JOIN provider_products pp ON pp.id = sps.provider_product_id AND pp.status='active'
    JOIN providers p ON p.id = pp.provider_id AND p.status='active'
    JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
    WHERE sps.store_product_id = ? AND sps.enabled=1
      AND (ws.qty_available - ws.qty_reserved) > 0
    ORDER BY pp.base_price ASC, pp.id ASC
    LIMIT 1
  ");
  $st->execute([$store_product_id]);
  $r = $st->fetch();
  return $r ?: null;
}

function provider_stock_sum(PDO $pdo, int $store_product_id): int {
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS s
    FROM store_product_sources sps
    JOIN warehouse_stock ws ON ws.provider_product_id = sps.provider_product_id
    WHERE sps.store_product_id=? AND sps.enabled=1
  ");
  $st->execute([$store_product_id]);
  return (int)($st->fetch()['s'] ?? 0);
}

function price_value_present($value): bool {
  return $value !== null && $value !== '' && is_numeric($value) && (float)$value > 0.0;
}

function current_sell_price_details(PDO $pdo, array $store, array $sp): array {
  $best = best_provider_source($pdo, (int)$sp['id']);
  $base = $best ? (float)$best['base_price'] : 0.0;
  $markupFactor = 1.0 + ((float)$store['markup_percent']/100.0);
  $autoPrice = $best ? ($base * $markupFactor) : 0.0;
  $manualPresent = price_value_present($sp['manual_price'] ?? null);
  $ownPresent = price_value_present($sp['own_stock_price'] ?? null);
  $ownQty = (int)($sp['own_stock_qty'] ?? 0);
  $linkedStock = provider_stock_sum($pdo, (int)$sp['id']);
  $linkedHasStock = $linkedStock > 0;

  $minAllowed = $base * 1.5;
  $minApplied = false;

  if ($manualPresent) {
    $manualValue = (float)$sp['manual_price'];
    if ($linkedHasStock && $minAllowed > 0.0 && $manualValue < $minAllowed) {
      $priceCalculated = $autoPrice;
      $priceSource = 'provider';
      $minApplied = true;
    } else {
      $priceCalculated = $manualValue;
      $priceSource = 'manual';
    }
  } elseif ($linkedHasStock) {
    $priceCalculated = $autoPrice;
    $priceSource = 'provider';
  } elseif ($ownPresent && $ownQty > 0) {
    $priceCalculated = (float)$sp['own_stock_price'];
    $priceSource = 'own';
  } else {
    $priceCalculated = $autoPrice;
    $priceSource = 'provider';
  }

  return [
    'price' => $priceCalculated,
    'min_applied' => $minApplied,
    'min_allowed' => $minAllowed,
    'auto_price' => $autoPrice,
    'base_price' => $base,
    'price_source' => $priceSource,
    'linked_stock' => $linkedStock
  ];
}

function current_sell_price(PDO $pdo, array $store, array $sp): float {
  $details = current_sell_price_details($pdo, $store, $sp);
  return (float)$details['price'];
}
?>
