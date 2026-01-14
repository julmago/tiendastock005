<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id, account_type, wholesale_status FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $type = ($seller['account_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
  $name = trim((string)($_POST['name'] ?? ''));
  $slugRaw = trim((string)($_POST['slug'] ?? ''));
  $slug = strtolower($slugRaw);
  $markup = (float)($_POST['markup_percent'] ?? ($type==='wholesale'?30:100));

  if ($type==='wholesale' && ($seller['wholesale_status'] ?? '')!=='approved') {
    $err="Para crear tienda mayorista necesitás aprobación.";
  } elseif (!$name || !$slug) {
    $err="Completá nombre y slug.";
  } elseif (!preg_match('/^[a-z0-9]+$/i', $slugRaw)) {
    $err="El slug solo puede tener letras y números, sin espacios ni caracteres especiales.";
  } else {
    $pdo->prepare("INSERT INTO stores(seller_id,store_type,name,slug,status,markup_percent) VALUES(?,?,?,?, 'active', ?)")
        ->execute([(int)$seller['id'],$type,$name,$slug,$markup]);
    $storeId = (int)$pdo->lastInsertId();

    $mpExtra = (float)setting($pdo,'mp_extra_percent','6');
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,?)")->execute([$storeId,'mercadopago',$mpExtra]);
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,0)")->execute([$storeId,'transfer']);
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,0,0)")->execute([$storeId,'cash_pickup',0]);

    $msg="Tienda creada.";
  }
}

$rows = $pdo->prepare("SELECT id, store_type, name, slug, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC");
$rows->execute([(int)$seller['id']]);
$stores = $rows->fetchAll();

page_header('Mis tiendas');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

$defaultMarkup = (($seller['account_type'] ?? 'retail') === 'wholesale') ? '30' : '100';
echo "<form method='post' id='store-form'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Tipo: <strong>".h((($seller['account_type'] ?? 'retail') === 'wholesale') ? 'mayorista' : 'minorista')."</strong></p>
<p>Nombre: <input name='name' style='width:420px'></p>
<p>Slug: <input name='slug' style='width:220px' pattern='[A-Za-z0-9]+' inputmode='text' autocomplete='off'></p>
<p id='slug-error' style='color:#b00; display:none; margin-top:-6px;'>Solo letras y números, sin espacios ni caracteres especiales.</p>
<p>Markup %: <input name='markup_percent' style='width:120px' value='".h($defaultMarkup)."'></p>
<button>Crear</button>
</form><hr>";

echo "<script>
const slugInput = document.querySelector(\"input[name='slug']\");
const slugError = document.getElementById('slug-error');
if (slugInput) {
  slugInput.addEventListener('input', () => {
    const original = slugInput.value;
    const cleaned = original.replace(/[^a-z0-9]/gi, '');
    if (original !== cleaned) {
      slugInput.value = cleaned;
      if (slugError) slugError.style.display = 'block';
    } else if (slugError) {
      slugError.style.display = 'none';
    }
  });
}
</script>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Tipo</th><th>Nombre</th><th>Slug</th><th>Link</th></tr>";
foreach($stores as $r){
  $path = ($r['store_type']==='wholesale') ? '/mayorista/' : '/shop/';
  echo "<tr>
    <td>".h((string)$r['id'])."</td>
    <td>".h($r['store_type'])."</td>
    <td>".h($r['name'])."</td>
    <td>".h($r['slug'])."</td>
    <td><a target='_blank' href='".h($path).h($r['slug'])."/'>abrir</a></td>
  </tr>";
}
echo "</table>";
page_footer();
