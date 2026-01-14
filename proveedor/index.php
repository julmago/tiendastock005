<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('provider','/proveedor/login.php');

$st = $pdo->prepare("SELECT id, display_name, status FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();

page_header('Panel Proveedor');
echo "<p>Proveedor: <b>".h($p['display_name'] ?? '')."</b> | Status: <b>".h($p['status'] ?? '')."</b></p>";
echo "<ul>
<li><a href='/proveedor/catalogo.php'>Mi catálogo base</a></li>
<li><a href='/proveedor/stock.php'>Mi stock en bodega</a></li>
</ul>";
page_footer();
