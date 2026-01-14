<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_any_role(['superadmin','admin'], '/admin/login.php');

$providerId = (int)($_GET['provider_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $providerId = (int)($_POST['provider_id'] ?? 0);
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $desc = trim((string)($_POST['description'] ?? ''));
  $price = (float)($_POST['base_price'] ?? 0);

  if (!$providerId || !$title || $price<=0) $err="Completá proveedor, título y precio base.";
  else {
    $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,description,base_price,status) VALUES(?,?,?,?,?,'active')")
        ->execute([$providerId,$title,$sku?:null,$desc?:null,$price]);
    $msg="Producto base creado.";
  }
}

$providers = $pdo->query("SELECT id, display_name FROM providers WHERE status='active' ORDER BY id DESC")->fetchAll();
$products = [];
if ($providerId) {
  $st = $pdo->prepare("SELECT id,title,sku,base_price,status FROM provider_products WHERE provider_id=? ORDER BY id DESC");
  $st->execute([$providerId]);
  $products = $st->fetchAll();
}

page_header('Catálogo base (proveedor)');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<form method='get'>
<p>Proveedor:
<select name='provider_id'>
  <option value='0'>-- elegir --</option>";
foreach($providers as $p){
  $sel = ($providerId==(int)$p['id']) ? "selected" : "";
  echo "<option value='".h((string)$p['id'])."' $sel>".h($p['display_name'])."</option>";
}
echo "</select> <button>Ver</button></p></form>";

if ($providerId) {
  echo "<hr><form method='post'>
    <input type='hidden' name='csrf' value='".h(csrf_token())."'>
    <input type='hidden' name='provider_id' value='".h((string)$providerId)."'>
    <p>Título: <input name='title' style='width:520px'></p>
    <p>SKU: <input name='sku' style='width:220px'></p>
    <p>Precio base: <input name='base_price' style='width:160px'></p>
    <p>Descripción:<br><textarea name='description' rows='4' style='width:90%'></textarea></p>
    <button>Crear</button>
  </form><hr>";

  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Título</th><th>SKU</th><th>Base</th></tr>";
  foreach($products as $pp){
    echo "<tr><td>".h((string)$pp['id'])."</td><td>".h($pp['title'])."</td><td>".h($pp['sku']??'')."</td><td>".h((string)$pp['base_price'])."</td></tr>";
  }
  echo "</table>";
}
page_footer();
