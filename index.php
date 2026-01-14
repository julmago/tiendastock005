<?php
require __DIR__.'/config.php';
require __DIR__.'/_inc/layout.php';

page_header('TiendaStock (MVP sin diseño)');
echo "<p>Accesos:</p><ul>
<li><a href='/admin/login.php'>Admin</a></li>
<li><a href='/proveedor/register.php'>Registro Proveedor</a> | <a href='/proveedor/login.php'>Login Proveedor</a></li>
<li><a href='/vendedor/register.php'>Registro Vendedor</a> | <a href='/vendedor/login.php'>Login Vendedor</a></li>
<li><a href='/shop/'>Tiendas minoristas</a></li>
<li><a href='/mayorista/'>Tiendas mayoristas</a></li>
</ul>
<p>Este proyecto es funcional (sin CSS). Subí el contenido del ZIP a <b>public_html</b>.</p>";
page_footer();
