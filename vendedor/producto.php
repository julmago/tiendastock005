<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
require __DIR__.'/../lib/product_images.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$productId = (int)($_GET['id'] ?? 0);
if (!$productId) { page_header('Producto'); echo "<p>Producto inválido.</p>"; page_footer(); exit; }

$productSt = $pdo->prepare("SELECT sp.*, s.name AS store_name, s.store_type, s.markup_percent, s.id AS store_id
  FROM store_products sp
  JOIN stores s ON s.id=sp.store_id
  WHERE sp.id=? AND s.seller_id=? LIMIT 1");
$productSt->execute([$productId,(int)$seller['id']]);
$product = $productSt->fetch();
if (!$product) { page_header('Producto'); echo "<p>Producto inválido.</p>"; page_footer(); exit; }

$storeId = (int)$product['store_id'];
$product_images = [];
$image_errors = [];
if (!empty($_GET['created']) && empty($msg)) {
  $msg = "Producto creado.";
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_info') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));

  if (!$title) $err = "Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  else {
    $pdo->prepare("UPDATE store_products SET title=?, sku=?, universal_code=?, description=? WHERE id=? AND store_id=?")
        ->execute([$title, $sku?:null, $universalCode?:null, $description?:null, $productId, $storeId]);
    $msg = "Producto actualizado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_stock') {
  $ownQty = (int)($_POST['own_stock_qty'] ?? 0);
  $ownPriceRaw = trim((string)($_POST['own_stock_price'] ?? ''));
  $manualRaw = trim((string)($_POST['manual_price'] ?? ''));
  $ownPriceVal = ($ownPriceRaw === '') ? null : (float)$ownPriceRaw;
  $manualVal = ($manualRaw === '') ? null : (float)$manualRaw;

  $pdo->prepare("UPDATE store_products SET own_stock_qty=?, own_stock_price=?, manual_price=? WHERE id=? AND store_id=?")
      ->execute([$ownQty, $ownPriceVal, $manualVal, $productId, $storeId]);
  if (empty($err)) {
    $msg = "Stock actualizado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_images') {
  $upload_dir = __DIR__.'/../uploads/store_products/'.$productId;
  if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true);
  }
  product_images_process_uploads($pdo, 'store_product', $productId, $_FILES['images'] ?? [], $upload_dir, $image_sizes, $max_image_size_bytes, $image_errors);
  product_images_apply_order($pdo, 'store_product', $productId, (string)($_POST['images_order'] ?? ''));
  if (!$image_errors) {
    $msg = "Imágenes actualizadas.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'delete_image') {
  $image_id = isset($_POST['delete_image_id']) ? (int)$_POST['delete_image_id'] : 0;
  if ($image_id <= 0) {
    $err = "Imagen inválida.";
  } else {
    $upload_dir = __DIR__.'/../uploads/store_products/'.$productId;
    if (product_images_delete($pdo, 'store_product', $productId, $image_id, $upload_dir, $image_sizes)) {
      $msg = "Imagen eliminada.";
    } else {
      $err = "Imagen inválida.";
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'toggle_source') {
  $ppId = (int)($_POST['provider_product_id'] ?? 0);

  if (!$ppId) $err = "Elegí un proveedor.";
  else {
    $pdo->prepare("INSERT INTO store_product_sources(store_product_id,provider_product_id,enabled)
                   VALUES(?,?,1) ON DUPLICATE KEY UPDATE enabled=1")
        ->execute([$productId,$ppId]);
    $msg = "Vínculo agregado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'unlink_source') {
  $ppId = (int)($_POST['provider_product_id'] ?? 0);

  if (!$ppId) $err = "Producto inválido.";
  else {
    $pdo->prepare("DELETE FROM store_product_sources WHERE store_product_id=? AND provider_product_id=? LIMIT 1")
        ->execute([$productId,$ppId]);
    header("Location: producto.php?id=".$productId);
    exit;
  }
}

$productSt->execute([$productId,(int)$seller['id']]);
$product = $productSt->fetch();
$product_images = product_images_fetch($pdo, 'store_product', $productId);

$provStock = provider_stock_sum($pdo, (int)$product['id']);
$sellDetails = current_sell_price_details($pdo, $product, $product);
$sell = (float)$sellDetails['price'];
$minAppliedMsg = '';
if (!empty($sellDetails['min_applied'])) {
  $minAllowed = (float)$sellDetails['min_allowed'];
  $minAppliedMsg = "Mínimo permitido es $".number_format($minAllowed, 2, ',', '.').". Se aplicó el precio automático.";
}
$stockTotal = $provStock + (int)$product['own_stock_qty'];
$sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';
$priceSource = $sellDetails['price_source'] ?? 'provider';
$priceSourceLabel = 'proveedor';
if ($priceSource === 'manual') {
  $priceSourceLabel = 'manual';
} elseif ($priceSource === 'own') {
  $priceSourceLabel = 'stock propio';
}

$linkedSt = $pdo->prepare("
  SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name AS provider_name,
         COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
  FROM store_product_sources sps
  JOIN provider_products pp ON pp.id = sps.provider_product_id
  LEFT JOIN providers p ON p.id = pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE sps.store_product_id = ? AND sps.enabled=1
  GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name
  ORDER BY pp.id DESC
");
$linkedSt->execute([$productId]);
$linkedProducts = $linkedSt->fetchAll();

page_header('Producto');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
if (!empty($image_errors)) {
  echo "<p style='color:#b00'>".h(implode(' ', $image_errors))."</p>";
}

echo "<p><a href='productos.php?store_id=".h((string)$storeId)."'>← Volver al listado</a></p>";

echo "<h3>Editar producto</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='update_info'>
<p>Título: <input name='title' value='".h($product['title'])."' style='width:520px'></p>
<p>SKU: <input name='sku' value='".h((string)($product['sku']??''))."' style='width:220px'></p>
<p>Código universal (8-14 dígitos):
  <span style='display:inline-flex; gap:8px; align-items:center;'>
    <input id='universal-code-input' name='universal_code' value='".h((string)($product['universal_code']??''))."' style='width:220px'>
    <button type='button' id='btnSearchByUniversal'>Buscar producto</button>
  </span>
</p>
<p>Descripción:<br><textarea name='description' rows='4' style='width:90%'>".h((string)($product['description']??''))."</textarea></p>
<button>Guardar cambios</button>
</form><hr>";

echo "<h3>Imágenes</h3>
<form method='post' enctype='multipart/form-data'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' id='images_action' value='update_images'>
<input type='hidden' name='delete_image_id' id='delete_image_id' value=''>
<p><input type='file' name='images[]' multiple accept='image/*'></p>
<input type='hidden' name='images_order' id='images_order' value=''>
<ul id='images-list'>";
if ($product_images) {
  foreach ($product_images as $index => $image) {
    $thumb = product_image_with_size($image['filename_base'], 150);
    $thumb_url = "/uploads/store_products/".h((string)$productId)."/".h($thumb);
    $cover_label = $index === 0 ? "Portada" : "";
    echo "<li data-id='".h((string)$image['id'])."'>
<img src='".$thumb_url."' alt='' width='80' height='80'>
 <span class='cover-label'>".h($cover_label)."</span>
 <button type='button' class='move-up'>↑</button>
 <button type='button' class='move-down'>↓</button>
 <button type='button' class='img-delete' data-image-id='".h((string)$image['id'])."'>X</button>
</li>";
  }
} else {
  echo "<li>No hay imágenes cargadas.</li>";
}
echo "</ul>
<button>Guardar imágenes</button>
</form>
<script>
(function() {
  var list = document.getElementById('images-list');
  var orderInput = document.getElementById('images_order');
  var deleteInput = document.getElementById('delete_image_id');
  var actionInput = document.getElementById('images_action');
  var form = list ? list.closest('form') : null;
  if (!list || !orderInput) return;

  function updateOrder() {
    var ids = [];
    var items = list.querySelectorAll('li[data-id]');
    items.forEach(function(item, index) {
      ids.push(item.getAttribute('data-id'));
      var label = item.querySelector('.cover-label');
      if (label) {
        label.textContent = index === 0 ? 'Portada' : '';
      }
    });
    orderInput.value = ids.join(',');
  }

  list.addEventListener('click', function(event) {
    if (event.target.classList.contains('img-delete')) {
      var imageId = event.target.getAttribute('data-image-id');
      if (!imageId) return;
      if (!confirm('¿Eliminar esta imagen?')) return;
      if (deleteInput) deleteInput.value = imageId;
      if (actionInput) actionInput.value = 'delete_image';
      if (form) form.submit();
      return;
    }
    if (event.target.classList.contains('move-up') || event.target.classList.contains('move-down')) {
      var item = event.target.closest('li');
      if (!item) return;
      if (event.target.classList.contains('move-up')) {
        var prev = item.previousElementSibling;
        if (prev && prev.hasAttribute('data-id')) {
          list.insertBefore(item, prev);
        }
      } else {
        var next = item.nextElementSibling;
        if (next) {
          list.insertBefore(next, item);
        }
      }
      updateOrder();
    }
  });

  updateOrder();
})();
</script><hr>";

echo "<h3>Stock y precio</h3>";
if (!empty($minAppliedMsg)) echo "<p style='color:#b00'>".h($minAppliedMsg)."</p>";
echo "
<p>Stock proveedor: ".h((string)$provStock)." | Precio actual (".h($priceSourceLabel)."): ".h($sellTxt)." (total: ".h((string)$stockTotal).")</p>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='update_stock'>
Own qty <input name='own_stock_qty' value='".h((string)$product['own_stock_qty'])."' style='width:70px'>
Own $ <input name='own_stock_price' value='".h((string)($product['own_stock_price']??''))."' style='width:90px'>
Manual $ <input name='manual_price' value='".h((string)($product['manual_price']??''))."' style='width:90px'>
<button>Guardar</button>
</form><hr>";

echo "<div id='provider-section'>
<h3>Proveedor</h3>
<div id='provider-link-message' style='margin-bottom:8px; color:#b00;'></div>
<form id='provider-link-form' method='post' style='max-width:820px;'>
  <input type='hidden' name='csrf' id='provider-link-csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='product_id' id='provider-store-product-id' value='".h((string)$productId)."'>
  <div style='display:flex; gap:8px; align-items:center;'>
    <input type='text' id='provider-product-search' placeholder='Buscar producto del proveedor…' style='flex:1; padding:6px;'>
    <button type='submit' id='provider-search-btn'>Buscar</button>
  </div>
</form>
<div id='provider-results-wrap' style='margin-top:12px;'>
  <div id='provider-search-empty-state' style='padding:8px; color:#666; display:none;'></div>
</div>
</div>";

echo "<div id='linked-section'>
<h3 id='linked-title'>Productos vinculados</h3>
<div id='linked-table-wrap'>";
if (!$linkedProducts) {
  echo "<p id='linked-products-empty'>No hay productos vinculados a esta publicación.</p>";
} else {
  echo "<table id='linked-products-table' border='1' cellpadding='6' cellspacing='0'>
  <thead><tr><th>Proveedor</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock</th><th>Precio</th><th>Acciones</th></tr></thead><tbody>";
  foreach($linkedProducts as $linked){
    $providerName = $linked['provider_name'] ?: '—';
    $universalCode = $linked['universal_code'] ?: '—';
    $price = $linked['base_price'] !== null ? '$'.number_format((float)$linked['base_price'], 2, ',', '.') : '—';
    echo "<tr>
      <td>".h((string)$providerName)."</td>
      <td>".h((string)$linked['title'])."</td>
      <td>".h((string)($linked['sku'] ?? ''))."</td>
      <td>".h((string)$universalCode)."</td>
      <td>".h((string)$linked['stock'])."</td>
      <td>".h((string)$price)."</td>
      <td>
        <form method='post' style='margin:0' onsubmit='return confirm(\"¿Eliminar vínculo?\")'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='unlink_source'>
          <input type='hidden' name='provider_product_id' value='".h((string)$linked['id'])."'>
          <button type='submit'>Eliminar</button>
        </form>
      </td>
    </tr>";
  }
  echo "</tbody></table>";
}
echo "</div></div>";

echo <<<JS
<script>
(function() {
  const searchInput = document.getElementById('provider-product-search');
  const resultsBox = document.getElementById('provider-results-wrap');
  const searchForm = document.getElementById('provider-link-form');
  const messageBox = document.getElementById('provider-link-message');
  const emptyStateBox = document.getElementById('provider-search-empty-state');
  const universalInput = document.getElementById('universal-code-input');
  const universalSearchButton = document.getElementById('btnSearchByUniversal');
  const csrfToken = document.getElementById('provider-link-csrf').value;
  const productId = document.getElementById('provider-store-product-id').value;

  function escapeHtml(value) {
    return value.replace(/[&<>"']/g, function(match) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      })[match];
    });
  }

  function setMessage(text, color) {
    messageBox.textContent = text || '';
    messageBox.style.color = color || '#b00';
  }

  function formatPrice(value) {
    if (value === null || value === undefined || value === '') return '—';
    const numberValue = Number(value);
    if (Number.isNaN(numberValue)) return '—';
    return '$' + numberValue.toFixed(2);
  }

  const emptyStateMessages = {
    all_linked: 'No hay más productos disponibles para vincular. Todos los productos con stock ya están vinculados.',
    no_results: 'Sin resultados para la búsqueda ingresada.'
  };

  function getResultsTable() {
    return document.getElementById('provider-search-results-table');
  }

  function ensureResultsTable() {
    let table = getResultsTable();
    if (!table) {
      table = document.createElement('table');
      table.id = 'provider-search-results-table';
      table.setAttribute('border', '1');
      table.setAttribute('cellpadding', '6');
      table.setAttribute('cellspacing', '0');
      table.style.width = '100%';
      table.innerHTML = "<thead><tr><th>Proveedor</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock</th><th>Precio</th><th>Acciones</th></tr></thead><tbody></tbody>";
      resultsBox.appendChild(table);
    }
    return table;
  }

  function updateSearchResultsVisibility() {
    const table = getResultsTable();
    const tbody = table ? table.querySelector('tbody') : null;
    const rowCount = tbody ? tbody.querySelectorAll('tr').length : 0;
    if (table) {
      table.style.display = rowCount > 0 ? '' : 'none';
    }
    if (emptyStateBox) {
      emptyStateBox.style.display = rowCount > 0 ? 'none' : '';
    }
    return rowCount;
  }

  function updateSearchResultsVisibilityWithMessage(reason) {
    const rowCount = updateSearchResultsVisibility();
    if (rowCount === 0 && emptyStateBox) {
      const message = emptyStateMessages[reason] || emptyStateMessages.no_results;
      emptyStateBox.textContent = message;
    }
  }

  function renderEmptyState(reason) {
    const table = getResultsTable();
    if (table) {
      const tbody = table.querySelector('tbody');
      if (tbody) {
        tbody.innerHTML = '';
      }
    }
    updateSearchResultsVisibilityWithMessage(reason);
  }

  function renderResults(items, emptyReason) {
    if (!items.length) {
      renderEmptyState(emptyReason || 'no_results');
      return;
    }
    const table = ensureResultsTable();
    const tbody = table.querySelector('tbody');
    const rows = items.map(function(item) {
      return "<tr data-id='" + item.id + "'>" +
        "<td>" + escapeHtml(item.provider_name || '—') + "</td>" +
        "<td>" + escapeHtml(item.title) + "</td>" +
        "<td>" + escapeHtml(item.sku || '') + "</td>" +
        "<td>" + escapeHtml(item.universal_code || '—') + "</td>" +
        "<td>" + escapeHtml(String(item.stock)) + "</td>" +
        "<td>" + escapeHtml(formatPrice(item.price)) + "</td>" +
        "<td><button type='button' class='provider-link-action'>Vincular</button></td>" +
        "</tr>";
    }).join('');
    tbody.innerHTML = rows;
    updateSearchResultsVisibility();
  }

  let lastQuery = '';

  function fetchResults(query) {
    lastQuery = query;
    const params = new URLSearchParams({
      q: query,
      product_id: productId
    });
    fetch('/vendedor/api/provider_products_search.php?' + params.toString(), {
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (Array.isArray(data)) {
          renderResults(data, 'no_results');
        } else if (data && Array.isArray(data.items)) {
          renderResults(data.items, data.empty_reason);
        } else if (data && data.error) {
          setMessage(data.error);
        }
      })
      .catch(function() {
        setMessage('No se pudo buscar.');
      });
  }

  function addLinkedRow(item) {
    const emptyRow = document.getElementById('linked-products-empty');
    if (emptyRow) emptyRow.remove();
    const linkedTableWrap = document.getElementById('linked-table-wrap');
    let table = document.getElementById('linked-products-table');
    if (!table) {
      table = document.createElement('table');
      table.id = 'linked-products-table';
      table.setAttribute('border', '1');
      table.setAttribute('cellpadding', '6');
      table.setAttribute('cellspacing', '0');
      table.innerHTML = "<thead><tr><th>Proveedor</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock</th><th>Precio</th><th>Acciones</th></tr></thead><tbody></tbody>";
      if (linkedTableWrap) {
        linkedTableWrap.appendChild(table);
      }
    }
    const tbody = table.querySelector('tbody') || table;
    const row = document.createElement('tr');
    const providerName = item.provider_name || '—';
    row.innerHTML = "<td>" + escapeHtml(providerName) + "</td>" +
      "<td>" + escapeHtml(item.title) + "</td>" +
      "<td>" + escapeHtml(item.sku || '') + "</td>" +
      "<td>" + escapeHtml(item.universal_code || '—') + "</td>" +
      "<td>" + escapeHtml(String(item.stock)) + "</td>" +
      "<td>" + escapeHtml(formatPrice(item.price)) + "</td>" +
      "<td>" +
      "<form method='post' style='margin:0' onsubmit='return confirm(\"¿Eliminar vínculo?\")'>" +
      "<input type='hidden' name='csrf' value='" + escapeHtml(csrfToken) + "'>" +
      "<input type='hidden' name='action' value='unlink_source'>" +
      "<input type='hidden' name='provider_product_id' value='" + escapeHtml(String(item.id)) + "'>" +
      "<button type='submit'>Eliminar</button>" +
      "</form>" +
      "</td>";
    tbody.appendChild(row);
  }

  function linkProviderProduct(linkedId, rowEl) {
    if (!linkedId) {
      setMessage('Producto inválido.');
      return;
    }
    setMessage('');
    const body = new URLSearchParams({
      product_id: productId,
      linked_product_id: linkedId,
      csrf: csrfToken
    });
    fetch('/vendedor/api/link_product.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function(res) {
        return res.json().then(function(data) {
          if (!res.ok) {
            throw data;
          }
          return data;
        });
      })
      .then(function(data) {
        if (!data || !data.ok) {
          setMessage('No se pudo vincular.');
          return;
        }
        addLinkedRow(data.item);
        setMessage('Vinculado correctamente.', 'green');
        if (rowEl && rowEl.parentNode) {
          rowEl.parentNode.removeChild(rowEl);
        }
        updateSearchResultsVisibilityWithMessage('all_linked');
      })
      .catch(function(err) {
        const errorMessage = err && err.error ? err.error : 'No se pudo vincular.';
        setMessage(errorMessage);
      });
  }

  function runUniversalSearch() {
    if (!universalInput) return;
    const code = universalInput.value.trim();
    if (!code) {
      setMessage('Ingresá un código universal para buscar.');
      return;
    }
    if (searchInput) {
      searchInput.value = code;
    }
    setMessage('');
    fetchResults(code);
  }

  if (searchForm) {
    searchForm.addEventListener('submit', function(event) {
      event.preventDefault();
      const query = searchInput.value.trim();
      setMessage('');
      if (!query) {
        renderEmptyState('no_results');
        return;
      }
      fetchResults(query);
    });
  }

  if (universalSearchButton) {
    universalSearchButton.addEventListener('click', function(event) {
      event.preventDefault();
      runUniversalSearch();
    });
  }

  if (universalInput) {
    universalInput.addEventListener('keydown', function(event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        runUniversalSearch();
      }
    });
  }

  if (resultsBox) {
    resultsBox.addEventListener('click', function(event) {
      const button = event.target.closest('.provider-link-action');
      if (!button) return;
      const row = button.closest('tr[data-id]');
      if (!row) return;
      const linkedId = row.getAttribute('data-id');
      linkProviderProduct(linkedId, row);
    });
  }
})();
</script>
JS;

page_footer();
