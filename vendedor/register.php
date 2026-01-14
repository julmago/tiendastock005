<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');
  $name  = trim((string)($_POST['display_name'] ?? ''));
  $accountType = (string)($_POST['account_type'] ?? 'retail');
  if (!$email || !$pass || !$name) $err="Completá email, contraseña y nombre.";
  elseif (!in_array($accountType, ['retail','wholesale'], true)) $err="Seleccioná un tipo de registro válido.";
  else {
    $existsSt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $existsSt->execute([$email]);
    if ($existsSt->fetch()) {
      $err="Ese email ya está registrado.";
    } else {
      try {
        $pdo->beginTransaction();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users(email,password_hash,role,status) VALUES(?,?, 'seller','active')")->execute([$email,$hash]);
        $uid = (int)$pdo->lastInsertId();
        $wholesaleStatus = $accountType === 'wholesale' ? 'pending' : 'not_requested';
        $pdo->prepare("INSERT INTO sellers(user_id,display_name,account_type,wholesale_status) VALUES(?,?,?,?)")
          ->execute([$uid,$name,$accountType,$wholesaleStatus]);
        $pdo->commit();
        if ($accountType === 'wholesale') {
          $_SESSION['flash_success'] = "Vendedor mayorista creado (pendiente de aprobación).";
        } else {
          $_SESSION['flash_success'] = "Vendedor minorista creado (activo).";
        }
        header('Location: /vendedor/login.php');
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $err = "No se pudo completar el registro. Intentá nuevamente.";
      }
    }
  }
}
page_header('Registro Vendedor');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Tipo de cuenta:
  <label><input type='radio' name='account_type' value='retail' checked> Registrarme como Minorista</label>
  <label style='margin-left:12px'><input type='radio' name='account_type' value='wholesale'> Registrarme como Mayorista</label>
</p>
<p>Email: <input name='email' style='width:320px'></p>
<p>Contraseña: <input type='password' name='password' style='width:320px'></p>
<p>Nombre / marca: <input name='display_name' style='width:420px'></p>
<button>Registrar</button>
</form>";
page_footer();
