<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin','/admin/login.php');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pairs = [
    'seller_fee_percent' => (string)($_POST['seller_fee_percent'] ?? '3.00'),
    'provider_fee_percent' => (string)($_POST['provider_fee_percent'] ?? '1.00'),
    'mp_extra_percent' => (string)($_POST['mp_extra_percent'] ?? '6.00'),
  ];
  foreach($pairs as $k=>$v){
    $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$k,$v]);
  }
  $msg="Settings actualizados.";
}

$cur = [];
foreach(['seller_fee_percent','provider_fee_percent','mp_extra_percent'] as $k){
  $cur[$k] = setting($pdo, $k, '');
}

page_header('Settings / Comisiones (superadmin)');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Comisión vendedor (%): <input name='seller_fee_percent' value='".h($cur['seller_fee_percent'])."'></p>
<p>Comisión proveedor (%): <input name='provider_fee_percent' value='".h($cur['provider_fee_percent'])."'></p>
<p>Extra MercadoPago (%): <input name='mp_extra_percent' value='".h($cur['mp_extra_percent'])."'></p>
<button>Guardar</button>
</form>";
page_footer();
