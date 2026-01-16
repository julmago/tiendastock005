<?php
declare(strict_types=1);
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
  'lifetime' => $cookieParams['lifetime'],
  'path' => '/',
  'domain' => $cookieParams['domain'],
  'secure' => $cookieParams['secure'],
  'httponly' => $cookieParams['httponly'],
  'samesite' => $cookieParams['samesite'] ?? 'Lax',
]);
session_start();

// ====== CONFIGURACIÓN DB ======
$DB_HOST = 'localhost';
$DB_NAME = 'tiendastock';
$DB_USER = 'tiendastock';
$DB_PASS = 'Martina*84260579';
$DB_CHARSET = 'utf8mb4';

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo "Error DB: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  exit;
}

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_check(): void {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = (string)($_POST['csrf'] ?? '');
    if (!$t || !hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
      http_response_code(400);
      exit('CSRF inválido');
    }
  }
}

function slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]/', '', $s);
  return $s ?: '';
}

function setting(PDO $pdo, string $key, string $default=''): string {
  $st = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
  $st->execute([$key]);
  $r = $st->fetch();
  return $r ? (string)$r['value'] : $default;
}

function require_login(string $loginPath): void {
  if (!isset($_SESSION['uid'])) { header("Location: {$loginPath}"); exit; }
}
function require_role(string $role, string $loginPath): void {
  require_login($loginPath);
  if (($_SESSION['role'] ?? '') !== $role) { http_response_code(403); exit('Acceso denegado'); }
}
function require_any_role(array $roles, string $loginPath): void {
  require_login($loginPath);
  if (!in_array(($_SESSION['role'] ?? ''), $roles, true)) { http_response_code(403); exit('Acceso denegado'); }
}
?>
