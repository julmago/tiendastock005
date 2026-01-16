<?php
function store_customer_session_key(int $storeId): string {
  return 'store_customer_'.$storeId;
}

function store_customer_find(PDO $pdo, int $storeId, string $email): ?array {
  $st = $pdo->prepare("SELECT * FROM store_customers WHERE store_id=? AND email=? LIMIT 1");
  $st->execute([$storeId, $email]);
  $row = $st->fetch();
  return $row ?: null;
}

function store_customer_login(PDO $pdo, int $storeId, string $email, string $password): ?array {
  $u = store_customer_find($pdo, $storeId, $email);
  if (!$u) return null;
  if (!password_verify($password, $u['password_hash'])) return null;
  return $u;
}

function store_customer_set_session(array $customer, int $storeId): void {
  $_SESSION[store_customer_session_key($storeId)] = [
    'id' => (int)$customer['id'],
    'email' => (string)$customer['email'],
    'first_name' => (string)$customer['first_name'],
    'last_name' => (string)$customer['last_name'],
  ];
}

function store_customer_logout(int $storeId): void {
  unset($_SESSION[store_customer_session_key($storeId)]);
}

function store_customer_current(PDO $pdo, int $storeId): ?array {
  $session = $_SESSION[store_customer_session_key($storeId)] ?? null;
  $id = (int)($session['id'] ?? 0);
  if (!$id) return null;
  $st = $pdo->prepare("SELECT * FROM store_customers WHERE id=? AND store_id=? LIMIT 1");
  $st->execute([$id, $storeId]);
  $row = $st->fetch();
  return $row ?: null;
}

function store_auth_links(array $store, string $base, string $slug, ?array $customer): string {
  if ($customer) {
    $name = trim((string)($customer['first_name'] ?? '').' '.(string)($customer['last_name'] ?? ''));
    $label = $name !== '' ? $name : (string)$customer['email'];
    return "Cliente: <b>".h($label)."</b> — <a href='".h($base)."logout.php?slug=".h($slug)."'>Salir</a>";
  }
  return "<a href='".h($base)."register.php?slug=".h($slug)."'>Registrarse</a> | <a href='".h($base)."login.php?slug=".h($slug)."'>Iniciar sesión</a>";
}
?>
