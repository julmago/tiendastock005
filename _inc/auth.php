<?php
function find_user(PDO $pdo, string $email, array $rolesAllowed): ?array {
  if (!$rolesAllowed) return null;
  $in = implode(",", array_fill(0, count($rolesAllowed), "?"));
  $params = array_merge([$email], $rolesAllowed);
  $st = $pdo->prepare("SELECT id,email,password_hash,role,status FROM users WHERE email=? AND role IN ($in) LIMIT 1");
  $st->execute($params);
  $u = $st->fetch();
  return $u ?: null;
}

function login_user(PDO $pdo, string $email, string $password, array $rolesAllowed): ?array {
  $u = find_user($pdo, $email, $rolesAllowed);
  if (!$u) return null;
  if (($u['status'] ?? '') !== 'active') return null;
  if (!password_verify($password, $u['password_hash'])) return null;
  return $u;
}

function session_set_user(array $u): void {
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['role'] = (string)$u['role'];
  $_SESSION['email'] = (string)$u['email'];
}
?>
