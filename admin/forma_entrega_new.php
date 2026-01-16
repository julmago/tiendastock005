<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$name = '';
$deliveryTime = '';
$price = '';
$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $deliveryTime = trim((string)($_POST['delivery_time'] ?? ''));
  $priceRaw = trim((string)($_POST['price'] ?? ''));

  if ($name === '' || $deliveryTime === '' || $priceRaw === '') {
    $err = 'Completá nombre, tiempo de entrega y precio.';
  } elseif (!is_numeric($priceRaw)) {
    $err = 'El precio debe ser numérico.';
  } else {
    $price = (float)$priceRaw;
    $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), 0) FROM delivery_methods")->fetchColumn();
    $pos++;
    $pdo->prepare("INSERT INTO delivery_methods(name, delivery_time, price, status, position) VALUES(?,?,?,?,?)")
        ->execute([$name, $deliveryTime, $price, 'inactive', $pos]);
    header("Location: /admin/formas_entrega.php?msg=Entrega%20creada");
    exit;
  }
}

page_header('Nueva forma de entrega');
if ($err) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<p><a href='/admin/formas_entrega.php'>← volver al listado</a></p>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Nombre: <input name='name' style='width:320px' value='".h($name)."'></p>
<p>Tiempo de entrega: <input name='delivery_time' style='width:320px' value='".h($deliveryTime)."'></p>
<p>Precio: <input name='price' style='width:120px' value='".h($price)."'></p>
<button>Guardar</button>
</form>";
page_footer();
?>
