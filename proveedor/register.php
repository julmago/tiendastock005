<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');
  $name  = trim((string)($_POST['display_name'] ?? ''));

  if (!$email || !$pass || !$name) $err="Completá email, contraseña y nombre.";
  else {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users(email,password_hash,role,status) VALUES(?,?, 'provider','pending')")->execute([$email,$hash]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO providers(user_id,display_name,status) VALUES(?,?,'pending')")->execute([$uid,$name]);
    $msg="Proveedor registrado. Quedó pendiente de aprobación.";
  }
}

page_header('Registro Proveedor');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Email: <input name='email' style='width:320px'></p>
<p>Contraseña: <input type='password' name='password' style='width:320px'></p>
<p>Nombre comercial: <input name='display_name' style='width:420px'></p>
<button>Registrar</button>
</form>";
page_footer();
