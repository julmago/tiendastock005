<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$msg = (string)($_GET['msg'] ?? '');
$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $moveId = (int)($_POST['move_id'] ?? 0);
  $direction = (string)($_POST['direction'] ?? '');
  if ($moveId && in_array($direction, ['up','down'], true)) {
    $st = $pdo->prepare("SELECT id, position FROM delivery_methods WHERE id=? LIMIT 1");
    $st->execute([$moveId]);
    $current = $st->fetch();

    if ($current) {
      if ($direction === 'up') {
        $swapSt = $pdo->prepare("SELECT id, position FROM delivery_methods WHERE position < ? ORDER BY position DESC, id DESC LIMIT 1");
        $swapSt->execute([(int)$current['position']]);
      } else {
        $swapSt = $pdo->prepare("SELECT id, position FROM delivery_methods WHERE position > ? ORDER BY position ASC, id ASC LIMIT 1");
        $swapSt->execute([(int)$current['position']]);
      }
      $swap = $swapSt->fetch();
      if ($swap) {
        $pdo->prepare("UPDATE delivery_methods SET position=? WHERE id=?")->execute([(int)$swap['position'], (int)$current['id']]);
        $pdo->prepare("UPDATE delivery_methods SET position=? WHERE id=?")->execute([(int)$current['position'], (int)$swap['id']]);
      }
    }
  }
}

$methods = $pdo->query("SELECT id, name, delivery_time, status, position FROM delivery_methods ORDER BY position ASC, id ASC")->fetchAll();

page_header('Formas de entrega');
if ($msg) echo "<p style='color:green'>".h($msg)."</p>";
if ($err) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<p><a href='/admin/forma_entrega_new.php'>Añadir nueva entrega</a></p>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Nombre</th><th>Tiempo de entrega</th><th>Estado</th><th>Posición (orden)</th><th>Acciones</th></tr>";
foreach($methods as $m){
  $statusLabel = $m['status']==='active' ? 'Activo' : 'Inactivo';
  echo "<tr>
    <td>".h($m['name'])."</td>
    <td>".h($m['delivery_time'])."</td>
    <td>".h($statusLabel)."</td>
    <td>".h((string)$m['position'])." ";
  echo "<form method='post' style='display:inline'>
    <input type='hidden' name='csrf' value='".h(csrf_token())."'>
    <input type='hidden' name='move_id' value='".h((string)$m['id'])."'>
    <input type='hidden' name='direction' value='up'>
    <button title='Subir' aria-label='Subir'>↑</button>
  </form>
  <form method='post' style='display:inline'>
    <input type='hidden' name='csrf' value='".h(csrf_token())."'>
    <input type='hidden' name='move_id' value='".h((string)$m['id'])."'>
    <input type='hidden' name='direction' value='down'>
    <button title='Bajar' aria-label='Bajar'>↓</button>
  </form>";
  echo "</td>
    <td><a href='/admin/forma_entrega_edit.php?id=".h((string)$m['id'])."'>Modificar</a></td>
  </tr>";
}
if (!$methods) {
  echo "<tr><td colspan='5'>Sin formas de entrega.</td></tr>";
}

echo "</table>";
page_footer();
?>
