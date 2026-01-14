<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id, display_name, wholesale_status FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$s = $st->fetch();

page_header('Panel Vendedor');
echo "<p>Vendedor: <b>".h($s['display_name'] ?? '')."</b> | Mayorista: <b>".h($s['wholesale_status'] ?? '')."</b></p>";
echo "<ul>
<li><a href='/vendedor/tiendas.php'>Mis tiendas</a></li>
<li><a href='/vendedor/productos.php'>Productos</a></li>
<li><a href='/vendedor/solicitar_mayorista.php'>Solicitar mayorista</a></li>
</ul>";
page_footer();
