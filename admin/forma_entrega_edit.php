<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Entrega no encontrada'); }

$st = $pdo->prepare("SELECT * FROM delivery_methods WHERE id=? LIMIT 1");
$st->execute([$id]);
$method = $st->fetch();
if (!$method) { http_response_code(404); exit('Entrega no encontrada'); }

$name = (string)$method['name'];
$deliveryTime = (string)$method['delivery_time'];
$price = (string)$method['price'];
$status = (string)$method['status'];
$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $deliveryTime = trim((string)($_POST['delivery_time'] ?? ''));
  $priceRaw = trim((string)($_POST['price'] ?? ''));
  $status = (string)($_POST['status'] ?? 'inactive');

  if ($name === '' || $deliveryTime === '' || $priceRaw === '') {
    $err = 'Completá nombre, tiempo de entrega y precio.';
  } elseif (!is_numeric($priceRaw)) {
    $err = 'El precio debe ser numérico.';
  } elseif (!in_array($status, ['active','inactive'], true)) {
    $err = 'Estado inválido.';
  } else {
    $price = (float)$priceRaw;
    $pdo->prepare("UPDATE delivery_methods SET name=?, delivery_time=?, price=?, status=? WHERE id=?")
        ->execute([$name, $deliveryTime, $price, $status, $id]);
    header("Location: /admin/formas_entrega.php?msg=Entrega%20actualizada");
    exit;
  }
}

page_header('Modificar forma de entrega');
if ($err) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<p><a href='/admin/formas_entrega.php'>← volver al listado</a></p>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Nombre: <input name='name' style='width:320px' value='".h($name)."'></p>
<p>Tiempo de entrega: <input name='delivery_time' style='width:320px' value='".h($deliveryTime)."'></p>
<p>Precio: <input name='price' style='width:120px' value='".h($price)."'></p>
<p>Estado: <select name='status'>
  <option value='active'".($status==='active'?' selected':'').">Activo</option>
  <option value='inactive'".($status==='inactive'?' selected':'').">Inactivo</option>
</select></p>
<button>Guardar</button>
</form>";
page_footer();
?>
