/* Cashier: fast product lookup, cart, payment, and receipt printing. */
(function () {
  'use strict';

  const $ = function (id) { return document.getElementById(id); };
  let cart = [];
  let results = [];
  let searchTimer = null;
  let lastSale = null;
  let busy = false;

  function esc(value) { return window.POS.esc(value); }
  function money(value) { return window.POS.money(value); }

  function cartTotal() {
    return cart.reduce(function (sum, item) { return sum + Math.round(item.price * item.qty * 100) / 100; }, 0);
  }

  function cartCount() {
    return cart.reduce(function (sum, item) { return sum + item.qty; }, 0);
  }

  function updateChange() {
    const cash = Number($('cash-received').value || 0);
    const total = cartTotal();
    const difference = Math.round((cash - total) * 100) / 100;
    $('change-due').value = difference >= 0 ? money(difference) : '₱0.00';
    $('pay-btn').disabled = busy || cart.length === 0 || difference < 0;
  }

  function renderCart() {
    const holder = $('cart-items');
    if (!cart.length) {
      holder.innerHTML = '<div class="empty-state"><i class="bi bi-cart"></i>Your cart is empty.</div>';
    } else {
      holder.innerHTML = cart.map(function (item) {
        return '<div class="cart-item" data-id="' + esc(item.id) + '">'
          + '<div class="cart-item-top"><div><strong>' + esc(item.name) + '</strong>'
          + '<div class="muted small">' + money(item.price) + ' / ' + esc(item.unit) + ' · ' + esc(item.stock) + ' in stock</div></div>'
          + '<div class="subtotal">' + money(item.price * item.qty) + '</div></div>'
          + '<div class="cart-item-bottom"><div class="qty-control">'
          + '<button type="button" data-action="minus" aria-label="Decrease quantity">−</button>'
          + '<span class="qty">' + item.qty + '</span>'
          + '<button type="button" data-action="plus" aria-label="Increase quantity">+</button>'
          + '</div><button class="btn btn-sm btn-ghost" data-action="remove" type="button"><i class="bi bi-trash3"></i> Remove</button></div>'
          + '</div>';
      }).join('');
    }
    $('cart-total').textContent = money(cartTotal());
    $('cart-count').textContent = String(cartCount());
    $('print-receipt-btn').disabled = !lastSale;
    updateChange();
  }

  function renderResults(items) {
    results = items;
    const holder = $('product-results');
    if (!items.length) {
      const term = $('product-search').value.trim();
      holder.innerHTML = '<div class="empty-state"><i class="bi bi-search"></i>'
        + (term ? 'No active products match "' + esc(term) + '".' : 'Start typing to find products.') + '</div>';
      return;
    }
    holder.innerHTML = items.map(function (product) {
      const out = product.stock_quantity <= 0;
      return '<button class="product-hit' + (out ? ' out' : '') + '" type="button" data-id="' + esc(product.id) + '"'
        + (out ? ' disabled' : '') + '>'
        + '<span><strong>' + esc(product.product_name) + '</strong>'
        + '<span class="meta">' + (product.barcode ? 'Barcode: ' + esc(product.barcode) + ' · ' : '')
        + 'Stock: ' + esc(product.stock_quantity) + ' ' + esc(product.unit)
        + (product.low_stock ? ' · <span style="color:#b91c1c;font-weight:700">LOW STOCK</span>' : '') + '</span></span>'
        + '<span class="price">' + money(product.selling_price) + '</span></button>';
    }).join('');
  }

  async function searchProducts() {
    const term = $('product-search').value.trim();
    if (term === '') { renderResults([]); return; }
    try {
      const data = await window.POS.api('api/products.php?' + window.POS.parseQuery({ action: 'search', q: term }));
      const exactBarcode = (data.products || []).find(function (product) { return product.barcode === term; });
      if (term.length >= 6 && exactBarcode) {
        // USB scanners usually send Enter; auto-add also handles scanners that do not.
        addProduct(exactBarcode, true);
        return;
      }
      renderResults(data.products || []);
    } catch (error) {
      window.POS.toast(error.message, 'error');
    }
  }

  function addProduct(product, fromScanner) {
    if (product.status !== 'active') { window.POS.toast('This product is archived.', 'error'); return; }
    if (product.stock_quantity <= 0) { window.POS.toast(product.product_name + ' is out of stock.', 'error'); return; }
    let line = cart.find(function (item) { return item.id === product.id; });
    const nextQty = (line ? line.qty : 0) + 1;
    if (nextQty > product.stock_quantity) {
      window.POS.toast('Only ' + product.stock_quantity + ' ' + product.unit + '(s) of ' + product.product_name + ' available.', 'error');
      return;
    }
    if (!line) {
      line = {
        id: product.id, name: product.product_name, price: Number(product.selling_price),
        unit: product.unit, stock: Number(product.stock_quantity), qty: 0
      };
      cart.push(line);
    }
    line.qty = nextQty;
    line.stock = Number(product.stock_quantity);
    renderCart();
    if (fromScanner) {
      $('product-search').value = '';
      renderResults([]);
    }
    $('product-search').focus();
  }

  async function addByBarcode(code) {
    const barcode = String(code || '').trim();
    if (!barcode) return;
    try {
      const data = await window.POS.api('api/products.php?' + window.POS.parseQuery({ action: 'search', q: barcode }));
      const exact = (data.products || []).find(function (product) { return product.barcode === barcode; });
      const product = exact || (data.products || [])[0];
      if (!product) { window.POS.toast('No product found for barcode: ' + barcode, 'error'); return; }
      addProduct(product, true);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function adjustQuantity(id, delta) {
    const line = cart.find(function (item) { return item.id === id; });
    if (!line) return;
    if (line.qty + delta > line.stock) {
      window.POS.toast('Stock limit reached for ' + line.name + ' (' + line.stock + ' ' + line.unit + ').', 'error');
      return;
    }
    line.qty += delta;
    if (line.qty <= 0) cart = cart.filter(function (item) { return item.id !== id; });
    renderCart();
  }

  function receiptMarkup(sale) {
    const rows = (sale.items || []).map(function (item) {
      return '<tr><td>' + esc(item.product_name) + '</td><td style="text-align:center">' + esc(item.quantity)
        + '</td><td style="text-align:right">' + money(item.unit_price) + '</td><td style="text-align:right">'
        + money(item.subtotal) + '</td></tr>';
    }).join('');
    return '<div style="text-align:center">'
      + '<div class="store">' + esc(sale.store_name || window.POS.config.storeName) + '</div>'
      + (sale.store_address ? '<div>' + esc(sale.store_address) + '</div>' : '')
      + '<div>RECEIPT</div></div>'
      + '<div class="divider"></div>'
      + '<div>No: ' + esc(sale.receipt_number) + '</div>'
      + '<div>Date: ' + esc(String(sale.created_at).replace('T', ' ')) + '</div>'
      + '<div class="divider"></div>'
      + '<table style="width:100%"><thead><tr><th style="text-align:left">Item</th><th style="text-align:center">Qty</th>'
      + '<th style="text-align:right">Price</th><th style="text-align:right">Amount</th></tr></thead><tbody>'
      + rows + '</tbody></table>'
      + '<div class="divider"></div>'
      + '<div class="totals"><div><span>TOTAL</span><span>' + money(sale.total_amount) + '</span></div>'
      + '<div><span>Cash</span><span>' + money(sale.cash_received) + '</span></div>'
      + '<div><span>Change</span><span>' + money(sale.change_amount) + '</span></div></div>'
      + '<div class="divider"></div>'
      + '<div style="text-align:center">' + esc(sale.receipt_footer || '') + '</div>';
  }

  function showReceiptModal(sale) {
    const markup = receiptMarkup(sale);
    const body = '<style>.receipt-preview{background:#fff;border:1px dashed #9ca3af;padding:12px;margin:0 auto}'
      + '.receipt-preview table{font-size:inherit}</style>'
      + '<div class="receipt-preview receipt-paper receipt-80" id="receipt-preview">' + markup + '</div>';
    const footer = '<button class="btn" id="print-58" type="button"><i class="bi bi-printer"></i> Print 58mm</button>'
      + '<button class="btn btn-primary" id="print-80" type="button"><i class="bi bi-printer"></i> Print 80mm</button>'
      + '<button class="btn btn-ghost" id="receipt-close" type="button">Close</button>';
    window.POS.openModal('Receipt ' + sale.receipt_number, body, footer, { wide: false });
    $('print-58').addEventListener('click', function () { window.POS.printReceipt(markup, 58); });
    $('print-80').addEventListener('click', function () { window.POS.printReceipt(markup, 80); });
    $('receipt-close').addEventListener('click', window.POS.closeModal);
  }

  async function loadReceipt(id) {
    try {
      const data = await window.POS.api('api/sales.php?' + window.POS.parseQuery({ action: 'get', id: id }));
      showReceiptModal(data.sale);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function checkout() {
    if (busy || !cart.length) return;
    const cash = Number($('cash-received').value || 0);
    if (cash + 0.00001 < cartTotal()) { window.POS.toast('Cash received is less than the total amount.', 'error'); return; }
    busy = true;
    $('pay-btn').disabled = true;
    try {
      const tokenData = await window.POS.api('api/sales.php?action=token');
      const data = await window.POS.api('api/sales.php?action=checkout', {
        method: 'POST',
        body: {
          checkout_token: tokenData.checkout_token,
          cash_received: cash,
          items: cart.map(function (item) { return { id: item.id, quantity: item.qty }; })
        }
      });
      lastSale = data.sale;
      cart = [];
      $('cash-received').value = '';
      $('product-search').value = '';
      renderResults([]);
      renderCart();
      window.POS.updateLowStock(data.low_stock_count);
      window.POS.toast(data.message || 'Payment successful.', 'success');
      if (window.Inventory) window.Inventory.refresh();
    } catch (error) {
      window.POS.toast(error.message, 'error');
      if (error.status === 409) window.POS.toast('Please review your cart and try again.', 'error');
    } finally {
      busy = false;
      updateChange();
    }
  }

  function scanModal() {
    const body = '<p class="muted small">Allow camera access when prompted, then point the camera at a barcode.</p>'
      + '<div id="cashier-scanner" style="width:100%;min-height:220px"></div>'
      + '<label class="field" style="margin-top:14px">Or enter barcode manually'
      + '<input id="manual-barcode" type="text" placeholder="Type or paste barcode" autocomplete="off"></label>';
    const footer = '<button class="btn btn-primary" id="use-manual-barcode" type="button">Add Product</button>'
      + '<button class="btn btn-ghost" id="scan-close" type="button">Cancel</button>';
    window.POS.openModal('Scan Barcode', body, footer);
    window.POS.onModalClose = function () { window.POS.closeCameraScanner(); };
    window.POS.openCameraScanner('cashier-scanner', function (code) { addByBarcode(code); window.POS.closeModal(); });
    $('use-manual-barcode').addEventListener('click', function () {
      const code = $('manual-barcode').value.trim();
      if (!code) { window.POS.toast('Enter a barcode first.', 'error'); return; }
      window.POS.closeModal();
      addByBarcode(code);
    });
    $('scan-close').addEventListener('click', window.POS.closeModal);
  }

  function init() {
    $('product-search').addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(searchProducts, 180);
    });
    $('product-search').addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      clearTimeout(searchTimer);
      const term = $('product-search').value.trim();
      if (!term) return;
      if (!results.length) {
        addByBarcode(term);
      } else {
        const exact = results.find(function (p) { return p.barcode === term; }) || results[0];
        addProduct(exact, true);
      }
    });
    $('product-results').addEventListener('click', function (event) {
      const button = event.target.closest('.product-hit');
      if (!button || button.disabled) return;
      const product = results.find(function (p) { return p.id === Number(button.dataset.id); });
      if (product) addProduct(product, false);
    });
    $('cart-items').addEventListener('click', function (event) {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      const id = Number(button.closest('.cart-item').dataset.id);
      if (button.dataset.action === 'plus') adjustQuantity(id, 1);
      if (button.dataset.action === 'minus') adjustQuantity(id, -1);
      if (button.dataset.action === 'remove') {
        cart = cart.filter(function (item) { return item.id !== id; });
        renderCart();
      }
    });
    $('clear-cart-btn').addEventListener('click', async function () {
      if (!cart.length) return;
      if (await window.POS.confirm('Clear cart?', 'Remove all items from the current cart?', 'Clear cart')) {
        cart = [];
        renderCart();
      }
    });
    $('cash-received').addEventListener('input', updateChange);
    $('cash-received').addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); checkout(); }
    });
    $('pay-btn').addEventListener('click', checkout);
    $('print-receipt-btn').addEventListener('click', function () {
      if (lastSale) loadReceipt(lastSale.id);
    });
    $('cashier-scan-btn').addEventListener('click', scanModal);
    renderCart();
  }

  window.Cashier = {
    init: init,
    onShow: function () { $('product-search').focus(); },
    showReceiptModal: showReceiptModal,
    loadReceipt: loadReceipt,
    receiptMarkup: receiptMarkup
  };
})();
