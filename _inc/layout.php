<?php
function page_header(string $title): void {
  $storeAuthHtml = $GLOBALS['STORE_AUTH_HTML'] ?? '';
  echo "<!doctype html><html><head><meta charset='utf-8'>";
  echo "<meta name='viewport' content='width=device-width,initial-scale=1'>";
  echo "<title>".h($title)."</title></head><body>";
  echo "<div style='max-width:980px;margin:20px auto;font-family:Arial,sans-serif;'>";
  echo "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;'>";
  echo "<div><b>".h($title)."</b></div><div>";
  if (isset($_SESSION['uid'])) {
    echo "Rol: <b>".h($_SESSION['role'] ?? '')."</b> — ".h($_SESSION['email'] ?? '')." — ";
    echo "<a href='/logout.php'>Salir</a>";
  }
  if ($storeAuthHtml) {
    if (isset($_SESSION['uid'])) echo " | ";
    echo $storeAuthHtml;
  }
  echo "</div></div><hr>";
}
function page_footer(): void {
  echo "</div></body></html>";
}
?>
