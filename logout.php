<?php
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
session_destroy();
setcookie(session_name(), '', [
  'expires' => time() - 3600,
  'path' => '/',
  'domain' => $cookieParams['domain'],
  'secure' => $cookieParams['secure'],
  'httponly' => $cookieParams['httponly'],
  'samesite' => $cookieParams['samesite'] ?? 'Lax',
]);
header('Location: /');
