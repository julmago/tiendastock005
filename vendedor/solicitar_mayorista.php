<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id, account_type, wholesale_status FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$s = $st->fetch();

page_header('Solicitar mayorista');
if (($s['account_type'] ?? 'retail') === 'wholesale') {
  echo "<p>Tu cuenta ya es mayorista. Estado: <b>".h($s['wholesale_status'] ?? '')."</b></p>";
} else {
  echo "<p>Las cuentas minoristas no solicitan mayorista desde este panel.</p>";
}
page_footer();
