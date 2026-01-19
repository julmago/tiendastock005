<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$editId = (int)($_GET['edit_id'] ?? 0);
$editSize = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $name = trim((string)($_POST['name'] ?? ''));
  $code = strtoupper(trim((string)($_POST['code'] ?? '')));
  $active = (int)($_POST['active'] ?? 1) ? 1 : 0;
  $position = (int)($_POST['position'] ?? 0);

  if ($action === 'add') {
    if ($name === '') {
      $err = "Falta nombre.";
    } elseif ($code === '') {
      $err = "Falta código.";
    } elseif (mb_strlen($code) > 4) {
      $err = "El código no puede tener más de 4 caracteres.";
    } else {
      $pdo->prepare("INSERT INTO sizes(name, code, active, position) VALUES(?,?,?,?)")
          ->execute([$name, $code, $active, $position]);
      $msg = "Talle creado.";
    }
  }

  if ($action === 'update') {
    $sizeId = (int)($_POST['size_id'] ?? 0);
    if ($sizeId <= 0) {
      $err = "Talle inválido.";
    } elseif ($name === '') {
      $err = "Falta nombre.";
      $editId = $sizeId;
    } elseif ($code === '') {
      $err = "Falta código.";
      $editId = $sizeId;
    } elseif (mb_strlen($code) > 4) {
      $err = "El código no puede tener más de 4 caracteres.";
      $editId = $sizeId;
    } else {
      $pdo->prepare("UPDATE sizes SET name=?, code=?, active=?, position=? WHERE id=?")
          ->execute([$name, $code, $active, $position, $sizeId]);
      $msg = "Talle actualizado.";
      $editId = $sizeId;
    }
  }
}

if ($editId > 0) {
  $st = $pdo->prepare("SELECT id, name, code, active, position FROM sizes WHERE id=? LIMIT 1");
  $st->execute([$editId]);
  $editSize = $st->fetch();
  if (!$editSize) {
    $editId = 0;
    $err = $err ?? "Talle inválido.";
  }
}

$sizes = $pdo->query("SELECT id, name, code, active, position FROM sizes ORDER BY position ASC, name ASC, id ASC")->fetchAll();

page_header('Talles');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<h3>".($editId > 0 ? "Modificar talle" : "Nuevo talle")."</h3>";
echo "<form method='post'>
  <input type='hidden' name='csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='action' value='".($editId > 0 ? "update" : "add")."'>
  <input type='hidden' name='size_id' value='".h((string)($editSize['id'] ?? ''))."'>
  <p>Nombre: <input name='name' style='width:280px' value='".h($editSize['name'] ?? '')."'></p>
  <p>Código: <input name='code' maxlength='4' style='width:140px' value='".h($editSize['code'] ?? '')."'></p>
  <p>Posición: <input name='position' style='width:140px' value='".h((string)($editSize['position'] ?? 0))."'></p>
  <p>Estado:
    <select name='active'>
      <option value='1'".(((int)($editSize['active'] ?? 1) === 1) ? " selected" : "").">Activo</option>
      <option value='0'".(((int)($editSize['active'] ?? 1) === 0) ? " selected" : "").">Inactivo</option>
    </select>
  </p>
  <button>".($editId > 0 ? "Guardar cambios" : "Crear")."</button>";
if ($editId > 0) {
  echo " <a href='/admin/talles.php'>Cancelar</a>";
}
echo "</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Nombre</th><th>Código</th><th>Estado</th><th>Posición</th><th></th></tr>";
foreach ($sizes as $size) {
  $statusLabel = ((int)$size['active'] === 1) ? 'Activo' : 'Inactivo';
  echo "<tr>
    <td>".h((string)$size['id'])."</td>
    <td>".h((string)$size['name'])."</td>
    <td>".h((string)$size['code'])."</td>
    <td>".h($statusLabel)."</td>
    <td>".h((string)$size['position'])."</td>
    <td><a href='/admin/talles.php?edit_id=".h((string)$size['id'])."'>Modificar</a></td>
  </tr>";
}
echo "</table>";

page_footer();
