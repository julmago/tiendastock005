<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id, display_name, account_type, wholesale_status FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$s = $st->fetch();

page_header('Panel Vendedor');
if ($s) {
  $accountType = $s['account_type'] ?? 'retail';
  $accountLabel = $accountType === 'wholesale' ? 'Mayorista' : 'Minorista';
  $statusLabel = $accountType === 'wholesale' ? ($s['wholesale_status'] ?? '') : 'activo';
  echo "<p>Vendedor: <b>".h($s['display_name'] ?? '')."</b> | Tipo: <b>".h($accountLabel)."</b> | Estado: <b>".h($statusLabel)."</b></p>";
}
echo "<ul>
<li><a href='/vendedor/tiendas.php'>Mis tiendas</a></li>
<li><a href='/vendedor/productos.php'>Productos</a></li>
<li><a href='/vendedor/pedidos.php'>Pedidos</a></li>
</ul>";
page_footer();
