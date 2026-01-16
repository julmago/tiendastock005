<?php
function store_customer_session_key(): string {
  return 'store_customer';
}

function store_customer_find(PDO $pdo, string $email): ?array {
  $st = $pdo->prepare("SELECT * FROM store_customers WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $row = $st->fetch();
  return $row ?: null;
}

function store_customer_login(PDO $pdo, string $email, string $password): ?array {
  $u = store_customer_find($pdo, $email);
  if (!$u) return null;
  if (!password_verify($password, $u['password_hash'])) return null;
  return $u;
}

function store_customer_set_session(array $customer): void {
  $_SESSION[store_customer_session_key()] = [
    'id' => (int)$customer['id'],
    'email' => (string)$customer['email'],
    'first_name' => (string)$customer['first_name'],
    'last_name' => (string)$customer['last_name'],
  ];
}

function store_customer_logout(): void {
  unset($_SESSION[store_customer_session_key()]);
}

function store_customer_current(PDO $pdo): ?array {
  $session = $_SESSION[store_customer_session_key()] ?? null;
  $id = (int)($session['id'] ?? 0);
  if (!$id) return null;
  $st = $pdo->prepare("SELECT * FROM store_customers WHERE id=? LIMIT 1");
  $st->execute([$id]);
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
