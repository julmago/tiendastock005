<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id, account_type, wholesale_status FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$row = $pdo->prepare("SELECT id, store_type, name, slug, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC LIMIT 1");
$row->execute([(int)$seller['id']]);
$store = $row->fetch();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $type = ($seller['account_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
  $storeType = $store ? $store['store_type'] : $type;
  $name = trim((string)($_POST['name'] ?? ''));
  $slugRaw = trim((string)($_POST['slug'] ?? ''));
  $slug = strtolower($slugRaw);
  $markupDefault = ($storeType === 'wholesale') ? 30 : 100;
  $markup = (float)($_POST['markup_percent'] ?? $markupDefault);

  if (!$store && $storeType==='wholesale' && ($seller['wholesale_status'] ?? '')!=='approved') {
    $err="Para crear tienda mayorista necesitás aprobación.";
  } elseif (!$name || !$slug) {
    $err="Completá nombre y slug.";
  } elseif (!preg_match('/^[a-z0-9]+$/i', $slugRaw)) {
    $err="El slug solo puede tener letras y números, sin espacios ni caracteres especiales.";
  } else {
    if ($store) {
      $pdo->prepare("UPDATE stores SET name=?, slug=?, markup_percent=? WHERE id=? AND seller_id=?")
          ->execute([$name,$slug,$markup,(int)$store['id'],(int)$seller['id']]);
      $ok = 'updated';
    } else {
      $pdo->prepare("INSERT INTO stores(seller_id,store_type,name,slug,status,markup_percent) VALUES(?,?,?,?, 'active', ?)")
          ->execute([(int)$seller['id'],$storeType,$name,$slug,$markup]);
      $storeId = (int)$pdo->lastInsertId();

      $mpExtra = (float)setting($pdo,'mp_extra_percent','6');
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,?)")->execute([$storeId,'mercadopago',$mpExtra]);
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,0)")->execute([$storeId,'transfer']);
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,0,0)")->execute([$storeId,'cash_pickup']);

      $ok = 'created';
    }

    header('Location: /vendedor/tiendas.php?ok='.$ok);
    exit;
  }
}

if (isset($_GET['ok'])) {
  $msg = ($_GET['ok'] === 'created') ? 'Tienda creada.' : 'Tienda actualizada.';
}

if (!$store) {
  $row = $pdo->prepare("SELECT id, store_type, name, slug, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC LIMIT 1");
  $row->execute([(int)$seller['id']]);
  $store = $row->fetch();
}

page_header('Mis tiendas');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

$storeTypeLabel = $store ? $store['store_type'] : ((($seller['account_type'] ?? 'retail') === 'wholesale') ? 'wholesale' : 'retail');
$defaultMarkup = $store ? (string)$store['markup_percent'] : (($storeTypeLabel === 'wholesale') ? '30' : '100');
$actionLabel = $store ? 'Modificar' : 'Crear';
$nameValue = $store ? $store['name'] : '';
$slugValue = $store ? $store['slug'] : '';
echo "<form method='post' id='store-form'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Tipo: <strong>".h(($storeTypeLabel === 'wholesale') ? 'mayorista' : 'minorista')."</strong></p>
<p>Nombre: <input name='name' style='width:420px' value='".h($nameValue)."'></p>
<p>Slug: <input name='slug' style='width:220px' pattern='[A-Za-z0-9]+' inputmode='text' autocomplete='off' value='".h($slugValue)."'></p>
<p id='slug-error' style='color:#b00; display:none; margin-top:-6px;'>Solo letras y números, sin espacios ni caracteres especiales.</p>
<p>Markup %: <input name='markup_percent' style='width:120px' value='".h($defaultMarkup)."'></p>
<button>".h($actionLabel)."</button>
</form><hr>";

echo "<script>
const slugInput = document.querySelector(\"input[name='slug']\");
const slugError = document.getElementById('slug-error');
if (slugInput) {
  slugInput.addEventListener('input', () => {
    const original = slugInput.value;
    const lower = original.toLowerCase();
    const cleaned = lower.replace(/[^a-z0-9]/g, '');
    const hasInvalid = /[^a-z0-9]/i.test(original);
    if (original !== cleaned) {
      slugInput.value = cleaned;
    }
    if (slugError) slugError.style.display = hasInvalid ? 'block' : 'none';
  });
}
</script>";
page_footer();
