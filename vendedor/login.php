<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/auth.php';
csrf_check();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $u = login_user($pdo, $_POST['email']??'', $_POST['password']??'', ['seller']);
  if ($u) { session_set_user($u); header('Location: /vendedor/'); exit; }
  $err="Credenciales inválidas.";
}
page_header('Vendedor - Login');
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p><input name='email' placeholder='Email' style='width:320px'></p>
<p><input name='password' type='password' placeholder='Contraseña' style='width:320px'></p>
<button>Ingresar</button>
</form>";
page_footer();
