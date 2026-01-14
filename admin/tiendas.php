<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_any_role(['superadmin','admin'], '/admin/login.php');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sellerId = (int)($_POST['seller_id'] ?? 0);
  $type = $_POST['store_type'] ?? 'retail';
  $name = trim((string)($_POST['name'] ?? ''));
  $slug = slugify((string)($_POST['slug'] ?? ''));
  $markup = (float)($_POST['markup_percent'] ?? ($type==='wholesale'?30:100));

  if (!$sellerId || !$name || !$slug) $err="Completá vendedor, nombre y slug.";
  else {
    $pdo->prepare("INSERT INTO stores(seller_id,store_type,name,slug,status,markup_percent) VALUES(?,?,?,?, 'active', ?)")
        ->execute([$sellerId,$type,$name,$slug,$markup]);
    $storeId = (int)$pdo->lastInsertId();

    $mpExtra = (float)setting($pdo,'mp_extra_percent','6');
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,?)")->execute([$storeId,'mercadopago',$mpExtra]);
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,0)")->execute([$storeId,'transfer']);
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,0,0)")->execute([$storeId,'cash_pickup',0]);

    $msg="Tienda creada.";
  }
}

$sellers = $pdo->query("SELECT id, display_name FROM sellers ORDER BY id DESC")->fetchAll();
$stores = $pdo->query("SELECT id, name, slug, store_type, markup_percent, status FROM stores ORDER BY id DESC")->fetchAll();

page_header('Tiendas');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Vendedor:
<select name='seller_id'><option value='0'>-- elegir --</option>";
foreach($sellers as $s){ echo "<option value='".h((string)$s['id'])."'>".h($s['display_name'])."</option>"; }
echo "</select></p>
<p>Tipo: <select name='store_type'><option value='retail'>minorista</option><option value='wholesale'>mayorista</option></select></p>
<p>Nombre: <input name='name' style='width:420px'></p>
<p>Slug: <input name='slug' style='width:220px'></p>
<p>Markup %: <input name='markup_percent' style='width:120px' value='100'></p>
<button>Crear</button>
</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Nombre</th><th>Slug</th><th>Tipo</th><th>Markup</th><th>Link</th></tr>";
foreach($stores as $st){
  $path = ($st['store_type']==='wholesale') ? '/mayorista/' : '/shop/';
  echo "<tr>
    <td>".h((string)$st['id'])."</td>
    <td>".h($st['name'])."</td>
    <td>".h($st['slug'])."</td>
    <td>".h($st['store_type'])."</td>
    <td>".h((string)$st['markup_percent'])."</td>
    <td><a target='_blank' href='".h($path).h($st['slug'])."/'>abrir</a></td>
  </tr>";
}
echo "</table>";
page_footer();
