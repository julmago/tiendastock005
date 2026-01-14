<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_any_role(['superadmin','admin'], '/admin/login.php');

if (($_POST['action'] ?? '') === 'approve_provider') {
  $pid = (int)($_POST['provider_id'] ?? 0);
  $pdo->prepare("UPDATE providers SET status='active' WHERE id=?")->execute([$pid]);
  $pdo->prepare("UPDATE users u JOIN providers p ON p.user_id=u.id SET u.status='active' WHERE p.id=?")->execute([$pid]);
  $msg="Proveedor aprobado.";
}
if (($_POST['action'] ?? '') === 'approve_wholesale') {
  $sid = (int)($_POST['seller_id'] ?? 0);
  $pdo->prepare("UPDATE sellers SET wholesale_status='approved' WHERE id=?")->execute([$sid]);
  $msg="Vendedor mayorista aprobado.";
}

$pendingProviders = $pdo->query("
  SELECT p.id, u.email, p.display_name
  FROM providers p JOIN users u ON u.id=p.user_id
  WHERE p.status='pending' ORDER BY p.id DESC
")->fetchAll();

$pendingWholesale = $pdo->query("
  SELECT s.id, u.email, s.display_name
  FROM sellers s JOIN users u ON u.id=s.user_id
  WHERE s.wholesale_status='pending' ORDER BY s.id DESC
")->fetchAll();

page_header('Aprobaciones');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";

echo "<h3>Proveedores pendientes</h3>";
if (!$pendingProviders) echo "<p>No hay pendientes.</p>";
else {
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Email</th><th>Nombre</th><th></th></tr>";
  foreach($pendingProviders as $p){
    echo "<tr>
      <td>".h((string)$p['id'])."</td>
      <td>".h($p['email'])."</td>
      <td>".h($p['display_name'])."</td>
      <td>
        <form method='post' style='margin:0'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='approve_provider'>
          <input type='hidden' name='provider_id' value='".h((string)$p['id'])."'>
          <button>Aprobar</button>
        </form>
      </td>
    </tr>";
  }
  echo "</table>";
}

echo "<h3 style='margin-top:18px'>Mayoristas pendientes</h3>";
if (!$pendingWholesale) echo "<p>No hay pendientes.</p>";
else {
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Email</th><th>Nombre</th><th></th></tr>";
  foreach($pendingWholesale as $s){
    echo "<tr>
      <td>".h((string)$s['id'])."</td>
      <td>".h($s['email'])."</td>
      <td>".h($s['display_name'])."</td>
      <td>
        <form method='post' style='margin:0'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='approve_wholesale'>
          <input type='hidden' name='seller_id' value='".h((string)$s['id'])."'>
          <button>Aprobar</button>
        </form>
      </td>
    </tr>";
  }
  echo "</table>";
}
page_footer();
