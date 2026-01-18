<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('superadmin', '/admin/login.php');

$msg = (string)($_GET['msg'] ?? '');

$categories = $pdo->query("SELECT id, parent_id, name, status FROM categories ORDER BY name ASC, id ASC")->fetchAll();
$byParent = [];
foreach ($categories as $cat) {
  $parentId = $cat['parent_id'] ? (int)$cat['parent_id'] : 0;
  $byParent[$parentId][] = $cat;
}

function render_category_rows(array $byParent, int $parentId = 0, int $depth = 0): void {
  if (empty($byParent[$parentId])) {
    return;
  }
  foreach ($byParent[$parentId] as $cat) {
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
    $statusLabel = $cat['status'] === 'active' ? 'Activo' : 'Inactivo';
    $id = (int)$cat['id'];
    echo "<tr>
      <td>{$indent}".h($cat['name'])."</td>
      <td>".h($statusLabel)."</td>
      <td>
        <a href='/admin/categoria_form.php?id=".h((string)$id)."'>Editar</a>
        | <a href='/admin/categoria_form.php?parent_id=".h((string)$id)."'>Agregar subcategoría</a>
      </td>
    </tr>";
    render_category_rows($byParent, $id, $depth + 1);
  }
}

page_header('Categorías');
if ($msg) echo "<p style='color:green'>".h($msg)."</p>";

echo "<p><a href='/admin/categoria_form.php'>Agregar categoría</a></p>";
echo "<table border='1' cellpadding='6' cellspacing='0'>";
echo "<tr><th>Nombre</th><th>Estado</th><th>Acciones</th></tr>";

if (!$categories) {
  echo "<tr><td colspan='3'>Sin categorías.</td></tr>";
} else {
  render_category_rows($byParent);
}

echo "</table>";
page_footer();
?>
