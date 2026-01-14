<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id, wholesale_status FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$s = $st->fetch();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($s['wholesale_status'] ?? '') === 'not_requested') {
    $pdo->prepare("UPDATE sellers SET wholesale_status='pending' WHERE id=?")->execute([(int)$s['id']]);
    $msg="Solicitud enviada. Queda pendiente de aprobación.";
    $s['wholesale_status']='pending';
  } else {
    $err="Estado actual: ".($s['wholesale_status'] ?? '');
  }
}

page_header('Solicitar mayorista');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<p>Estado actual: <b>".h($s['wholesale_status'] ?? '')."</b></p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<button>Solicitar</button>
</form>";
page_footer();
