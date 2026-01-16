<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/store_auth.php';
csrf_check();

$BASE = '/mayorista/';
$STORE_TYPE = 'wholesale';

$slug = slugify((string)($_GET['slug'] ?? ''));
if (!$slug) { header("Location: ".$BASE); exit; }

$st = $pdo->prepare("SELECT * FROM stores WHERE slug=? AND status='active' AND store_type=? LIMIT 1");
$st->execute([$slug, $STORE_TYPE]);
$store = $st->fetch();
if (!$store) { http_response_code(404); exit('Tienda no encontrada'); }

$customer = store_customer_current($pdo);
if ($customer) { header("Location: ".$BASE.$slug."/"); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  if ($email === '' || $password === '') {
    $err = "Completá email y contraseña.";
  } else {
    $login = store_customer_login($pdo, $email, $password);
    if (!$login) {
      $err = "Credenciales inválidas.";
    } else {
      store_customer_set_session($login);
      header("Location: ".$BASE.$slug."/"); exit;
    }
  }
}

$GLOBALS['STORE_AUTH_HTML'] = store_auth_links($store, $BASE, $slug, null);
page_header("Iniciar sesión - ".$store['name']);
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p><label>Email<br><input type='email' name='email' value='".h((string)($_POST['email'] ?? ''))."'></label></p>
<p><label>Contraseña<br><input type='password' name='password'></label></p>
<button>Iniciar sesión</button>
</form>";
echo "<p><a href='".$BASE."register.php?slug=".h($slug)."'>Registrarse</a></p>";
echo "<p><a href='".$BASE.$slug."/'>Volver</a></p>";
page_footer();
?>
