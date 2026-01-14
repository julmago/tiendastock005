<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
page_header('Tiendas Mayoristas');

$st = $pdo->prepare("SELECT name, slug FROM stores WHERE status='active' AND store_type=? ORDER BY id DESC");
$st->execute(['wholesale']);
$rows = $st->fetchAll();

if (!$rows) echo "<p>No hay tiendas todavía.</p>";
else {
  echo "<ul>";
  foreach($rows as $r){
    echo "<li><a href='/mayorista/".h($r['slug'])."/'>".h($r['name'])."</a></li>";
  }
  echo "</ul>";
}
page_footer();
