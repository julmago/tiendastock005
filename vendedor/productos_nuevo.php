<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$storesSt = $pdo->prepare("SELECT id, name, slug, store_type, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC");
$storesSt->execute([(int)$seller['id']]);
$myStores = $storesSt->fetchAll();

$storeId = (int)($_GET['store_id'] ?? 0);
if (!$storeId && $myStores) $storeId = (int)$myStores[0]['id'];

$currentStore = null;
foreach($myStores as $ms){ if ((int)$ms['id'] === $storeId) $currentStore = $ms; }
if (!$currentStore) { page_header('Productos'); echo "<p>Primero creá una tienda.</p>"; page_footer(); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'create') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  if (!$title) $err="Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  else {
    $pdo->prepare("INSERT INTO store_products(store_id,title,sku,universal_code,description,status,own_stock_qty,own_stock_price,manual_price)
                   VALUES(?,?,?,?,?, 'active',0,NULL,NULL)")
        ->execute([$storeId,$title,$sku?:null,$universalCode?:null,($_POST['description']??'')?:null]);
    $msg="Producto creado.";
  }
}

page_header('Vendedor - Productos');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

require __DIR__.'/partials/productos_header.php';

echo "<h3>Crear desde cero</h3>
<form method='post' id='create-form'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='create'>
<p>Título: <input name='title' id='create-title' style='width:520px'></p>
<p>SKU: <input name='sku' id='create-sku' style='width:220px'></p>
<p>Código universal (8-14 dígitos): <input name='universal_code' id='create-universal-code' style='width:220px'></p>
<p>Descripción:<br><textarea name='description' id='create-description' rows='3' style='width:90%'></textarea></p>
<button id='create-submit'>Crear</button>
</form><hr>";

echo "<h3>Copiar desde proveedor</h3>
<div style='margin-bottom:8px'>
  <input type='text' id='provider-search-input' placeholder='Buscar producto del proveedor…' style='width:520px'>
  <button type='button' id='provider-search-button'>Buscar</button>
</div>
<div id='provider-search-results'>
  <table border='1' cellpadding='4' cellspacing='0' style='width:100%; max-width:980px'>
    <thead>
      <tr>
        <th>Proveedor</th>
        <th>Título</th>
        <th>SKU</th>
        <th>Código universal</th>
        <th>Stock</th>
        <th>Precio</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody id='provider-search-body'>
      <tr id='provider-search-empty' style='display:none'>
        <td colspan='7'>No se encontraron productos del proveedor.</td>
      </tr>
    </tbody>
  </table>
</div>
<hr>
<script>
(() => {
  const searchInput = document.getElementById('provider-search-input');
  const searchButton = document.getElementById('provider-search-button');
  const resultsBody = document.getElementById('provider-search-body');
  const emptyRow = document.getElementById('provider-search-empty');
  const createTitle = document.getElementById('create-title');
  const createSku = document.getElementById('create-sku');
  const createUniversal = document.getElementById('create-universal-code');
  const createDescription = document.getElementById('create-description');
  const createForm = document.getElementById('create-form');
  const createSubmit = document.getElementById('create-submit');

  const clearResults = () => {
    while (resultsBody.firstChild) {
      resultsBody.removeChild(resultsBody.firstChild);
    }
    resultsBody.appendChild(emptyRow);
  };

  const renderResults = (items) => {
    clearResults();
    if (!items.length) {
      emptyRow.style.display = '';
      return;
    }
    emptyRow.style.display = 'none';
    items.forEach((item) => {
      const row = document.createElement('tr');
      const providerCell = document.createElement('td');
      providerCell.textContent = item.provider_name;
      row.appendChild(providerCell);

      const titleCell = document.createElement('td');
      titleCell.textContent = item.title;
      row.appendChild(titleCell);

      const skuCell = document.createElement('td');
      skuCell.textContent = item.sku || '';
      row.appendChild(skuCell);

      const universalCell = document.createElement('td');
      universalCell.textContent = item.universal_code || '';
      row.appendChild(universalCell);

      const stockCell = document.createElement('td');
      stockCell.textContent = String(item.stock ?? '');
      row.appendChild(stockCell);

      const priceCell = document.createElement('td');
      priceCell.textContent = item.price !== null && item.price !== undefined ? `$${item.price}` : '';
      row.appendChild(priceCell);

      const actionCell = document.createElement('td');
      const copyButton = document.createElement('button');
      copyButton.type = 'button';
      copyButton.textContent = 'Copiar';
      copyButton.addEventListener('click', () => {
        createTitle.value = item.title || '';
        createSku.value = item.sku || '';
        createUniversal.value = item.universal_code || '';
        createDescription.value = item.description || '';
        if (createForm) {
          createForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        if (createSubmit) {
          createSubmit.focus();
        }
      });
      actionCell.appendChild(copyButton);
      row.appendChild(actionCell);

      resultsBody.appendChild(row);
    });
  };

  const runSearch = () => {
    const q = (searchInput.value || '').trim();
    if (!q) {
      clearResults();
      emptyRow.style.display = 'none';
      return;
    }
    const params = new URLSearchParams({ q });
    fetch(`/vendedor/api/provider_products_search.php?${params.toString()}`, {
      credentials: 'same-origin',
    })
      .then((res) => res.json())
      .then((data) => {
        renderResults(Array.isArray(data.items) ? data.items : []);
      })
      .catch(() => {
        renderResults([]);
      });
  };

  searchButton.addEventListener('click', runSearch);
  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      runSearch();
    }
  });
})();
</script>";

page_footer();
