<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/store_auth.php';

$BASE = '/shop/';
$STORE_TYPE = 'retail';

$slug = slugify((string)($_GET['slug'] ?? ''));
if ($slug) {
  $st = $pdo->prepare("SELECT id FROM stores WHERE slug=? AND status='active' AND store_type=? LIMIT 1");
  $st->execute([$slug, $STORE_TYPE]);
  $store = $st->fetch();
  if ($store) {
    store_customer_logout((int)$store['id']);
    header("Location: ".$BASE.$slug."/"); exit;
  }
}
header("Location: ".$BASE); exit;
?>
