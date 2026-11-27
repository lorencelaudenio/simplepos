/* Inventory: product cards, stock adjustments, low stock alerts, Excel import/export. */
(function () {
  'use strict';

  const $ = function (id) { return document.getElementById(id); };
  let products = [];
  let searchTimer = null;
  let importToken = '';
  let importRows = [];

  function esc(value) { return window.POS.esc(value); }
  function money(value) { return window.POS.money(value); }

  function filters() {
    return {
      q: $('inventory-search').value.trim(),
      low_stock: $('low-stock-toggle').checked ? 1 : 0,
      status: $('show-archived-toggle').checked ? 'all' : 'active'
    };
  }

  async function loadProducts() {
    try {
      const query = Object.assign({ action: 'list' }, filters());
      const data = await window.POS.api('api/products.php?' + window.POS.parseQuery(query));
      products = data.products || [];
      renderProducts();
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function loadLowStock() {
    try {
      const data = await window.POS.api('api/inventory.php?action=low_stock');
      const count = Number(data.count || 0);
      window.POS.updateLowStock(count);
      const alertBox = $('low-stock-alert');
      alertBox.classList.toggle('hidden', count === 0);
      $('low-stock-alert-text').textContent = count + ' product' + (count === 1 ? '' : 's') + ' at or below the reorder level.';
    } catch (error) { /* dashboard remains usable if this call fails */ }
  }

  function renderProducts() {
    const grid = $('product-grid');
    $('inventory-count').textContent = products.length + ' product' + (products.length === 1 ? '' : 's');
    if (!products.length) {
      grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><i class="bi bi-box-seam"></i>'
        + 'No products found. Add a product or import from Excel.</div>';
      return;
    }
    grid.innerHTML = products.map(function (product) {
      const archived = product.status === 'archived';
      const warning = product.low_stock && !archived
        ? '<div class="low-warning"><i class="bi bi-exclamation-triangle-fill"></i> LOW STOCK — reorder at ' + esc(product.reorder_level) + '</div>'
        : '';
      return '<article class="card product-card" data-id="' + esc(product.id) + '">'
        + '<div class="row-between"><h3>' + esc(product.product_name) + '</h3>'
        + (archived ? '<span class="badge badge-gray">Archived</span>' : (product.low_stock ? '<span class="badge badge-red">Low</span>' : '<span class="badge badge-green">Active</span>')) + '</div>'
        + '<div class="product-meta">'
        + (product.barcode ? '<span><i class="bi bi-upc-scan"></i> ' + esc(product.barcode) + '</span>' : '')
        + '<span><i class="bi bi-rulers"></i> Product / UOM: ' + esc(product.unit) + '</span>'
        + '<span>Reorder at: ' + esc(product.reorder_level) + ' ' + esc(product.unit) + '</span></div>'
        + warning
        + '<div class="product-stock"><div><div class="muted small">Current stock</div>'
        + '<div class="stock-num">' + esc(product.stock_quantity) + ' ' + esc(product.unit) + '</div></div>'
        + '<div class="right"><div class="muted small">Selling price</div><div class="price">' + money(product.selling_price) + '</div></div></div>'
        + '<div class="product-actions">'
        + (archived
          ? '<button class="btn btn-sm" data-action="restore" type="button"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>'
          : '<button class="btn btn-sm btn-primary" data-action="stock" type="button"><i class="bi bi-plus-circle"></i> Add Stock</button>')
        + '<button class="btn btn-sm" data-action="edit" type="button"><i class="bi bi-pencil"></i> Edit</button>'
        + '<button class="btn btn-sm btn-danger" data-action="delete" type="button"><i class="bi bi-trash3"></i> Delete</button>'
        + '</div></article>';
    }).join('');
  }

  function productForm(product) {
    product = product || {};
    return '<input type="hidden" id="f-id" value="' + esc(product.id || '') + '">'
      + '<div class="form-grid">'
      + '<label class="field span-2">Product Name *<input id="f-name" type="text" maxlength="255" value="' + esc(product.product_name || '') + '" required></label>'
      + '<label class="field">Barcode <span class="hint">(optional)</span><input id="f-barcode" type="text" maxlength="100" value="' + esc(product.barcode || '') + '" autocomplete="off"></label>'
      + '<label class="field">Selling Price *<input id="f-price" type="number" min="0" step="0.01" inputmode="decimal" value="' + esc(product.selling_price != null ? Number(product.selling_price).toFixed(2) : '') + '" required></label>'
      + '<label class="field">Unit of Measurement (UOM) *<input id="f-unit" type="text" maxlength="50" placeholder="Piece, Pack, Box, Bottle..." value="' + esc(product.unit || '') + '" required></label>'
      + '<label class="field">Quantity in Stock *<input id="f-stock" type="number" min="0" step="1" inputmode="numeric" value="' + esc(product.stock_quantity != null ? product.stock_quantity : 0) + '" required></label>'
      + '<label class="field">Reorder Level *<input id="f-reorder" type="number" min="0" step="1" inputmode="numeric" value="' + esc(product.reorder_level != null ? product.reorder_level : 0) + '" required></label>'
      + '</div>'
      + '<button class="btn btn-sm" id="product-scan-btn" type="button" style="margin-top:14px"><i class="bi bi-qr-code-scan"></i> Scan Barcode</button>'
      + '<label class="field" style="margin-top:10px">Camera <span class="hint">(switch if preview freezes)</span><select id="product-camera"><option value="">Default camera</option></select></label>'
      + '<div id="product-scanner" style="width:100%;margin-top:10px"></div>';
  }

  async function openProductModal(id) {
    async function show(product) {
      const body = productForm(product);
      const footer = '<button class="btn btn-primary" id="save-product" type="button"><i class="bi bi-check2-circle"></i> Save Product</button>'
        + '<button class="btn btn-ghost" id="cancel-product" type="button">Cancel</button>';
      window.POS.openModal(product ? 'Edit Product' : 'Add Product', body, footer);
      window.POS.onModalClose = function () { window.POS.closeCameraScanner(); };

      function fillCameras() { window.POS.populateCameraSelect('product-camera'); }

      function scanResult(code) {
        $('f-barcode').value = code;
        window.POS.closeCameraScanner();
        $('product-scanner').innerHTML = '';
        window.POS.toast('Barcode captured: ' + code, 'success');
      }

      $('product-scan-btn').addEventListener('click', function () {
        const host = $('product-scanner');
        if (host.innerHTML.trim() !== '') { window.POS.closeCameraScanner(); host.innerHTML = ''; return; }
        window.POS.openCameraScanner('product-scanner', scanResult, $('product-camera').value, fillCameras);
      });
      $('product-camera').addEventListener('change', function () {
        if ($('product-scanner').innerHTML.trim() === '') return;
        window.POS.openCameraScanner('product-scanner', scanResult, this.value, fillCameras);
      });
      $('cancel-product').addEventListener('click', window.POS.closeModal);
      $('save-product').addEventListener('click', async function () {
        const payload = {
          id: Number($('f-id').value || 0),
          product_name: $('f-name').value.trim(),
          barcode: $('f-barcode').value.trim(),
          selling_price: Number($('f-price').value),
          unit: $('f-unit').value.trim(),
          stock_quantity: Number($('f-stock').value),
          reorder_level: Number($('f-reorder').value)
        };
        if (!payload.product_name || !payload.unit || $('f-price').value === '' || $('f-stock').value === '' || $('f-reorder').value === '') {
          window.POS.toast('Please complete all required fields.', 'error');
          return;
        }
        try {
          const data = await window.POS.api('api/products.php?action=save', { method: 'POST', body: payload });
          window.POS.closeModal();
          window.POS.updateLowStock(data.low_stock_count);
          await refresh();
          window.POS.toast(data.message, 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
      });
    }
    if (!id) { show(null); return; }
    try {
      const data = await window.POS.api('api/products.php?' + window.POS.parseQuery({ action: 'get', id: id }));
      show(data.product);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function openStockModal(product) {
    const body = '<div class="stack">'
      + '<div><div class="muted small">Product</div><strong>' + esc(product.product_name) + '</strong></div>'
      + '<div><div class="muted small">Current stock</div><strong>' + esc(product.stock_quantity) + ' ' + esc(product.unit) + '</strong></div>'
      + '<label class="field">Quantity to Add *<input id="add-qty" type="number" min="1" step="1" inputmode="numeric" value="1"></label>'
      + '<label class="field">Notes <span class="hint">(optional)</span><input id="add-notes" type="text" maxlength="250"></label>'
      + '<div class="stock-preview" id="stock-preview">Updated stock: ' + (Number(product.stock_quantity) + 1) + ' ' + esc(product.unit) + '</div>'
      + '<button class="btn btn-sm btn-ghost" id="view-movements" type="button"><i class="bi bi-clock-history"></i> View stock history</button>'
      + '<div id="movements-list"></div></div>';
    const footer = '<button class="btn btn-primary" id="save-stock" type="button"><i class="bi bi-check2-circle"></i> Save Stock</button>'
      + '<button class="btn btn-ghost" id="cancel-stock" type="button">Cancel</button>';
    window.POS.openModal('Add Stock', body, footer);
    $('add-qty').addEventListener('input', function () {
      const qty = Math.max(0, Number($('add-qty').value || 0));
      $('stock-preview').textContent = 'Updated stock: ' + (Number(product.stock_quantity) + qty) + ' ' + product.unit;
    });
    $('view-movements').addEventListener('click', async function () {
      try {
        const data = await window.POS.api('api/inventory.php?' + window.POS.parseQuery({ action: 'movements', id: product.id }));
        const list = data.movements || [];
        $('movements-list').innerHTML = list.length
          ? '<div class="table-wrap" style="max-height:180px;overflow:auto"><table><thead><tr><th>Date</th><th>Type</th><th>Change</th><th>New stock</th></tr></thead><tbody>'
            + list.map(function (m) {
              return '<tr><td>' + esc(m.created_at) + '</td><td>' + esc(m.movement_type) + '</td><td>' + esc(m.quantity)
                + '</td><td>' + esc(m.new_stock) + '</td></tr>';
            }).join('') + '</tbody></table></div>'
          : '<p class="muted small">No stock movements recorded.</p>';
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
    $('cancel-stock').addEventListener('click', window.POS.closeModal);
    $('save-stock').addEventListener('click', async function () {
      const qty = Number($('add-qty').value);
      if (!Number.isInteger(qty) || qty <= 0) { window.POS.toast('Enter a positive whole quantity.', 'error'); return; }
      try {
        const data = await window.POS.api('api/inventory.php?action=add_stock', {
          method: 'POST',
          body: { id: product.id, quantity: qty, notes: $('add-notes').value.trim() }
        });
        window.POS.closeModal();
        await refresh();
        window.POS.toast('Stock updated: ' + data.previous_stock + ' → ' + data.new_stock + '.', 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
  }

  function renderImportPreview() {
    const holder = $('import-preview');
    if (!importRows.length) { holder.innerHTML = ''; return; }
    holder.innerHTML = '<div class="table-wrap" style="margin-top:14px"><table class="preview-table"><thead><tr>'
      + '<th>Row</th><th>Product</th><th>Barcode</th><th>Price</th><th>Unit</th><th>Stock</th><th>Reorder</th><th>Status</th>'
      + '</tr></thead><tbody>'
      + importRows.map(function (row) {
        const status = row.valid
          ? '<span class="badge badge-green">Ready</span>'
          : '<span class="error-text">' + esc(row.errors.join(' ')) + '</span>';
        return '<tr><td>' + esc(row.row) + '</td><td>' + esc(row.product_name) + '</td><td>' + esc(row.barcode)
          + '</td><td>' + esc(row.selling_price) + '</td><td>' + esc(row.unit) + '</td><td>' + esc(row.stock_quantity)
          + '</td><td>' + esc(row.reorder_level) + '</td><td>' + status
          + (row.existing_id && row.valid ? '<div class="muted small">Matches: ' + esc(row.existing_name) + '</div>' : '')
          + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  function openImportModal() {
    importToken = '';
    importRows = [];
    const body = '<p class="muted small">Upload an .xlsx file with columns: Product Name, Barcode, Selling Price, Unit, Quantity in Stock, Reorder Level. Format the Barcode column as text in Excel to preserve leading zeros.</p>'
      + '<button class="btn btn-sm" id="download-template" type="button" style="margin:10px 0"><i class="bi bi-download"></i> Download Excel Template</button>'
      + '<label class="field">Excel File<input type="file" id="import-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>'
      + '<button class="btn btn-sm btn-primary" id="preview-import" type="button" style="margin-top:12px"><i class="bi bi-eye"></i> Preview Import</button>'
      + '<label class="checkbox-line" style="margin-top:14px"><input type="checkbox" id="import-overwrite"> Update existing products</label>'
      + '<div id="import-preview"></div>';
    const footer = '<button class="btn btn-primary" id="commit-import" type="button" disabled><i class="bi bi-upload"></i> Import Products</button>'
      + '<button class="btn btn-ghost" id="cancel-import" type="button">Cancel</button>';
    window.POS.openModal('Import Products from Excel', body, footer, { wide: true });
    $('download-template').addEventListener('click', function () {
      window.POS.downloadFile('api/inventory.php?action=template').catch(function (error) { window.POS.toast(error.message, 'error'); });
    });
    $('preview-import').addEventListener('click', async function () {
      const file = $('import-file').files[0];
      if (!file) { window.POS.toast('Choose an Excel file first.', 'error'); return; }
      const form = new FormData();
      form.append('file', file);
      try {
        const data = await window.POS.api('api/inventory.php?action=import_preview', { method: 'POST', body: form });
        importToken = data.token;
        importRows = data.rows || [];
        renderImportPreview();
        $('commit-import').disabled = false;
        window.POS.toast('Preview ready: ' + data.valid_count + ' valid row(s), ' + data.error_count + ' error(s).', data.error_count ? '' : 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
    $('commit-import').addEventListener('click', async function () {
      if (!importToken) { window.POS.toast('Preview the file before importing.', 'error'); return; }
      if (!(await window.POS.confirm('Import products?', 'Valid rows will be added to inventory. Invalid rows will be skipped.', 'Import'))) return;
      try {
        const data = await window.POS.api('api/inventory.php?action=import_commit', {
          method: 'POST',
          body: { token: importToken, overwrite: $('import-overwrite').checked }
        });
        window.POS.closeModal();
        await refresh();
        window.POS.updateLowStock(data.low_stock_count);
        window.POS.toast(data.message, 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
    $('cancel-import').addEventListener('click', window.POS.closeModal);
  }

  async function refresh() {
    await Promise.all([loadProducts(), loadLowStock()]);
  }

  function init() {
    if (!$('product-grid')) return;
    $('low-stock-pill').addEventListener('click', function () {
      window.POS.activateTab('inventory');
      $('low-stock-toggle').checked = true;
      loadProducts();
    });
    $('inventory-search').addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(loadProducts, 220);
    });
    $('low-stock-toggle').addEventListener('change', loadProducts);
    $('show-archived-toggle').addEventListener('change', loadProducts);
    $('reorder-now-btn').addEventListener('click', function () {
      $('low-stock-toggle').checked = true;
      loadProducts();
    });
    $('add-product-btn').addEventListener('click', function () { openProductModal(0); });
    $('import-btn').addEventListener('click', openImportModal);
    $('export-inventory-btn').addEventListener('click', function () {
      window.POS.downloadFile('api/inventory.php?' + window.POS.parseQuery(Object.assign({ action: 'export' }, filters())))
        .catch(function (error) { window.POS.toast(error.message, 'error'); });
    });
    $('product-grid').addEventListener('click', async function (event) {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      const id = Number(button.closest('.product-card').dataset.id);
      const product = products.find(function (item) { return item.id === id; });
      if (!product) return;
      const action = button.dataset.action;
      if (action === 'edit') { openProductModal(id); return; }
      if (action === 'stock') { openStockModal(product); return; }
      if (action === 'restore') {
        try {
          const data = await window.POS.api('api/products.php?action=restore', { method: 'POST', body: { id: id } });
          await refresh();
          window.POS.updateLowStock(data.low_stock_count);
          window.POS.toast('Product restored to active inventory.', 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
        return;
      }
      if (action === 'delete') {
        const message = product.status === 'archived'
          ? 'Delete "' + product.product_name + '" permanently? This product has no sales history.'
          : 'Remove "' + product.product_name + '"? Products with sales history are archived automatically.';
        if (!(await window.POS.confirm('Delete product?', message, 'Delete'))) return;
        try {
          const data = await window.POS.api('api/products.php?action=delete', { method: 'POST', body: { id: id } });
          await refresh();
          window.POS.updateLowStock(data.low_stock_count);
          window.POS.toast(data.message, 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
      }
    });
  }

  window.Inventory = { init: init, onShow: refresh, refresh: refresh };
})();
