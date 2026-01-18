<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$editId = (int)($_GET['edit_id'] ?? 0);
$editColor = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $name = trim((string)($_POST['name'] ?? ''));
  $hex = trim((string)($_POST['hex'] ?? ''));
  $active = (int)($_POST['active'] ?? 1) ? 1 : 0;
  $hexValue = $hex === '' ? null : $hex;

  if ($action === 'add') {
    if ($name === '') {
      $err = "Falta nombre.";
    } else {
      $pdo->prepare("INSERT INTO colors(name, hex, active) VALUES(?,?,?)")
          ->execute([$name, $hexValue, $active]);
      $msg = "Color creado.";
    }
  }

  if ($action === 'update') {
    $colorId = (int)($_POST['color_id'] ?? 0);
    if ($colorId <= 0) {
      $err = "Color inválido.";
    } elseif ($name === '') {
      $err = "Falta nombre.";
      $editId = $colorId;
    } else {
      $pdo->prepare("UPDATE colors SET name=?, hex=?, active=? WHERE id=?")
          ->execute([$name, $hexValue, $active, $colorId]);
      $msg = "Color actualizado.";
      $editId = $colorId;
    }
  }
}

if ($editId > 0) {
  $st = $pdo->prepare("SELECT id, name, hex, active FROM colors WHERE id=? LIMIT 1");
  $st->execute([$editId]);
  $editColor = $st->fetch();
  if (!$editColor) {
    $editId = 0;
    $err = $err ?? "Color inválido.";
  }
}

$colors = $pdo->query("SELECT id, name, hex, active FROM colors ORDER BY name ASC, id ASC")->fetchAll();

page_header('Colores');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<h3>".($editId > 0 ? "Modificar color" : "Nuevo color")."</h3>";
echo "<form method='post'>
  <input type='hidden' name='csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='action' value='".($editId > 0 ? "update" : "add")."'>
  <input type='hidden' name='color_id' value='".h((string)($editColor['id'] ?? ''))."'>
  <p>Nombre: <input name='name' style='width:280px' value='".h($editColor['name'] ?? '')."'></p>
  <p>Hex (opcional): <input name='hex' style='width:140px' value='".h($editColor['hex'] ?? '')."'></p>
  <p>Estado:
    <select name='active'>
      <option value='1'".(((int)($editColor['active'] ?? 1) === 1) ? " selected" : "").">Activo</option>
      <option value='0'".(((int)($editColor['active'] ?? 1) === 0) ? " selected" : "").">Inactivo</option>
    </select>
  </p>
  <button>".($editId > 0 ? "Guardar cambios" : "Crear")."</button>";
if ($editId > 0) {
  echo " <a href='/admin/colores.php'>Cancelar</a>";
}
echo "</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Nombre</th><th>Hex</th><th>Estado</th><th></th></tr>";
foreach ($colors as $color) {
  $statusLabel = ((int)$color['active'] === 1) ? 'Activo' : 'Inactivo';
  echo "<tr>
    <td>".h((string)$color['id'])."</td>
    <td>".h((string)$color['name'])."</td>
    <td>".h((string)($color['hex'] ?? ''))."</td>
    <td>".h($statusLabel)."</td>
    <td><a href='/admin/colores.php?edit_id=".h((string)$color['id'])."'>Modificar</a></td>
  </tr>";
}
echo "</table>";

page_footer();
