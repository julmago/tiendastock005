<?php
$storeParam = '';
if (!empty($storeId)) {
  $storeParam = '?'.http_build_query(['store_id' => $storeId]);
}
$listUrl = 'productos.php'.$storeParam;
$newUrl = 'productos_nuevo.php'.$storeParam;

echo "<div style='display:flex; align-items:center; justify-content:space-between; gap:12px;'>
  <h2 style='margin:0;'>Vendedor - Productos</h2>
  <div><a href='".$newUrl."'>Nuevo</a> | <a href='".$listUrl."'>Listado</a></div>
</div>";
