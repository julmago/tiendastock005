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

$customer = store_customer_current($pdo, (int)$store['id']);
if ($customer) { header("Location: ".$BASE.$slug."/"); exit; }

$formData = [
  'email' => (string)($_POST['email'] ?? ''),
  'first_name' => (string)($_POST['first_name'] ?? ''),
  'last_name' => (string)($_POST['last_name'] ?? ''),
  'phone' => (string)($_POST['phone'] ?? ''),
  'postal_code' => (string)($_POST['postal_code'] ?? ''),
  'street' => (string)($_POST['street'] ?? ''),
  'street_number' => (string)($_POST['street_number'] ?? ''),
  'street_number_sn' => isset($_POST['street_number_sn']),
  'apartment' => (string)($_POST['apartment'] ?? ''),
  'neighborhood' => (string)($_POST['neighborhood'] ?? ''),
  'document_id' => (string)($_POST['document_id'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $firstName = trim((string)($_POST['first_name'] ?? ''));
  $lastName = trim((string)($_POST['last_name'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $postal = trim((string)($_POST['postal_code'] ?? ''));
  $street = trim((string)($_POST['street'] ?? ''));
  $streetNumberSn = isset($_POST['street_number_sn']) ? 1 : 0;
  $streetNumber = $streetNumberSn ? 'SN' : trim((string)($_POST['street_number'] ?? ''));
  $apartment = trim((string)($_POST['apartment'] ?? ''));
  $neighborhood = trim((string)($_POST['neighborhood'] ?? ''));
  $documentId = trim((string)($_POST['document_id'] ?? ''));

  if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = "Ingresá un email válido.";
  } elseif ($password === '') {
    $err = "Ingresá una contraseña.";
  } elseif ($firstName === '') {
    $err = "Ingresá tu nombre.";
  } elseif ($lastName === '') {
    $err = "Ingresá tu apellido.";
  } elseif ($phone === '') {
    $err = "Ingresá tu teléfono.";
  } elseif ($postal === '' || !preg_match('/^\\d{1,4}$/', $postal)) {
    $err = "Ingresá un código postal válido (solo números, hasta 4).";
  } elseif ($street === '') {
    $err = "Ingresá tu calle.";
  } elseif (!$streetNumberSn && $streetNumber === '') {
    $err = "Ingresá el número de tu domicilio o marcá Sin número.";
  } elseif ($documentId === '') {
    $err = "Ingresá DNI o CUIT.";
  } elseif (store_customer_find($pdo, (int)$store['id'], $email)) {
    $err = "Ya existe una cuenta con ese email.";
  } else {
    $pdo->prepare("INSERT INTO store_customers(store_id,email,password_hash,first_name,last_name,phone,postal_code,street,street_number,street_number_sn,apartment,neighborhood,document_id)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          (int)$store['id'],
          $email,
          password_hash($password, PASSWORD_DEFAULT),
          $firstName,
          $lastName,
          $phone,
          $postal,
          $street,
          $streetNumber,
          $streetNumberSn,
          $apartment !== '' ? $apartment : null,
          $neighborhood !== '' ? $neighborhood : null,
          $documentId
        ]);
    $customer = store_customer_find($pdo, (int)$store['id'], $email);
    if ($customer) {
      store_customer_set_session($customer, (int)$store['id']);
      header("Location: ".$BASE.$slug."/"); exit;
    }
  }
}

$GLOBALS['STORE_AUTH_HTML'] = store_auth_links($store, $BASE, $slug, null);
page_header("Registrarse - ".$store['name']);
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p><label>Email<br><input type='email' name='email' value='".h($formData['email'])."'></label></p>
<p><label>Contraseña<br><input type='password' name='password'></label></p>
<p><label>Nombre<br><input type='text' name='first_name' value='".h($formData['first_name'])."'></label></p>
<p><label>Apellido<br><input type='text' name='last_name' value='".h($formData['last_name'])."'></label></p>
<p><label>Teléfono<br><input type='text' name='phone' value='".h($formData['phone'])."'></label></p>
<p><label>Código postal<br><input type='text' name='postal_code' value='".h($formData['postal_code'])."' maxlength='4' inputmode='numeric' pattern='\\d{1,4}'></label></p>
<p><label>Calle<br><input type='text' name='street' value='".h($formData['street'])."'></label></p>
<p><label>Número<br><input id='street_number' type='text' name='street_number' value='".h($formData['street_number'])."'></label>
<label><input id='street_number_sn' type='checkbox' name='street_number_sn' value='1'".($formData['street_number_sn'] ? " checked" : "")."> Sin número</label></p>
<p><label>Departamento (opcional)<br><input type='text' name='apartment' value='".h($formData['apartment'])."'></label></p>
<p><label>Barrio (opcional)<br><input type='text' name='neighborhood' value='".h($formData['neighborhood'])."'></label></p>
<p><label>DNI o CUIT<br><input type='text' name='document_id' value='".h($formData['document_id'])."'></label></p>
<button>Registrarse</button>
</form>
<script>
const snBox = document.getElementById('street_number_sn');
const snInput = document.getElementById('street_number');
if (snBox && snInput) {
  const toggleSn = () => {
    if (snBox.checked) {
      snInput.value = '';
      snInput.disabled = true;
    } else {
      snInput.disabled = false;
    }
  };
  snBox.addEventListener('change', toggleSn);
  toggleSn();
}
</script>";
echo "<p><a href='".$BASE."login.php?slug=".h($slug)."'>Iniciar sesión</a></p>";
echo "<p><a href='".$BASE.$slug."/'>Volver</a></p>";
page_footer();
?>
