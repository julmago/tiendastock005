<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$name = '';
$status = 'active';
$parentId = (int)($_GET['parent_id'] ?? 0);
$err = '';

if ($isEdit) {
  $st = $pdo->prepare("SELECT id, parent_id, name, status FROM categories WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $category = $st->fetch();
  if (!$category) { http_response_code(404); exit('Categoría no encontrada'); }
  $name = (string)$category['name'];
  $status = (string)$category['status'];
  $parentId = $category['parent_id'] ? (int)$category['parent_id'] : 0;
}

$categories = $pdo->query("SELECT id, parent_id, name FROM categories ORDER BY name ASC, id ASC")->fetchAll();
$byParent = [];
foreach ($categories as $cat) {
  $parent = $cat['parent_id'] ? (int)$cat['parent_id'] : 0;
  $byParent[$parent][] = $cat;
}

function collect_descendants(array $byParent, int $id, array &$descendants): void {
  if (empty($byParent[$id])) {
    return;
  }
  foreach ($byParent[$id] as $child) {
    $childId = (int)$child['id'];
    $descendants[] = $childId;
    collect_descendants($byParent, $childId, $descendants);
  }
}

function flatten_categories(array $byParent, int $parentId, int $depth, array &$flat): void {
  if (empty($byParent[$parentId])) {
    return;
  }
  foreach ($byParent[$parentId] as $cat) {
    $flat[] = [
      'id' => (int)$cat['id'],
      'name' => (string)$cat['name'],
      'depth' => $depth,
    ];
    flatten_categories($byParent, (int)$cat['id'], $depth + 1, $flat);
  }
}

$descendants = [];
if ($isEdit) {
  collect_descendants($byParent, $id, $descendants);
}

$flatCategories = [];
flatten_categories($byParent, 0, 0, $flatCategories);

$allowedParentIds = [];
foreach ($flatCategories as $cat) {
  if ($isEdit && ($cat['id'] === $id || in_array($cat['id'], $descendants, true))) {
    continue;
  }
  $allowedParentIds[] = $cat['id'];
}

if (!$isEdit && $parentId && !in_array($parentId, $allowedParentIds, true)) {
  $parentId = 0;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $parentId = (int)($_POST['parent_id'] ?? 0);
  $status = (string)($_POST['status'] ?? 'active');

  if ($name === '') {
    $err = 'Completá el nombre.';
  } elseif (!in_array($status, ['active','inactive'], true)) {
    $err = 'Estado inválido.';
  } elseif ($parentId && !in_array($parentId, $allowedParentIds, true)) {
    $err = 'Categoría padre inválida.';
  } else {
    $parentValue = $parentId ? $parentId : null;
    if ($isEdit) {
      $pdo->prepare("UPDATE categories SET name=?, parent_id=?, status=? WHERE id=?")
          ->execute([$name, $parentValue, $status, $id]);
      header("Location: /admin/categorias.php?msg=Categoría%20actualizada");
    } else {
      $pdo->prepare("INSERT INTO categories(name, parent_id, status) VALUES(?,?,?)")
          ->execute([$name, $parentValue, $status]);
      header("Location: /admin/categorias.php?msg=Categoría%20creada");
    }
    exit;
  }
}

$title = $isEdit ? 'Editar categoría' : 'Nueva categoría';
page_header($title);
if ($err) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<p><a href='/admin/categorias.php'>← volver al listado</a></p>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Nombre: <input name='name' style='width:320px' value='".h($name)."'></p>
<p>Categoría padre: <select name='parent_id'>
  <option value='0'".($parentId===0 ? ' selected' : '').">Sin categoría padre</option>";

foreach ($flatCategories as $cat) {
  if ($isEdit && ($cat['id'] === $id || in_array($cat['id'], $descendants, true))) {
    continue;
  }
  $indent = str_repeat('— ', $cat['depth']);
  $selected = $parentId === $cat['id'] ? ' selected' : '';
  echo "<option value='".h((string)$cat['id'])."'{$selected}>".h($indent.$cat['name'])."</option>";
}

echo "</select></p>
<p>Estado: <select name='status'>
  <option value='active'".($status==='active' ? ' selected' : '').">Activo</option>
  <option value='inactive'".($status==='inactive' ? ' selected' : '').">Inactivo</option>
</select></p>
<button>Guardar</button>
</form>";
page_footer();
?>
