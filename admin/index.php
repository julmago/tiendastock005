<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_any_role(['superadmin','admin'], '/admin/login.php');

page_header('Admin');
echo "<ul>
<li><a href='/admin/aprobaciones.php'>Aprobaciones (proveedor / mayorista)</a></li>
<li><a href='/admin/catalogo_proveedor.php'>Catálogo base (crear productos proveedor)</a></li>
<li><a href='/admin/bodega.php'>Bodega (recepciones y stock)</a></li>
<li><a href='/admin/tiendas.php'>Tiendas</a></li>
<li><a href='/admin/settings.php'>Comisiones / MP extra</a></li>
<li><a href='/admin/admins.php'>Admins internos (solo superadmin)</a></li>";
if (($_SESSION['role'] ?? '') === 'superadmin') {
  echo "<li><a href='/admin/categorias.php'>Categorías</a></li>";
  echo "<li><a href='/admin/formas_entrega.php'>Formas de entrega</a></li>";
}
echo "
</ul>";
page_footer();
