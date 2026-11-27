/* Shared client helpers: API calls, toasts, modals, tabs, printing helpers. */
(function () {
  'use strict';

  const config = window.POS_CONFIG || { csrfToken: '', isAdmin: false, storeName: 'My Store' };
  let loadingDepth = 0;
  let qrScanner = null;
  let confirmResolver = null;

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function money(value) {
    const number = Number(value || 0);
    return '\u20B1' + number.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function showLoading() {
    loadingDepth++;
    document.getElementById('loading-overlay').classList.remove('hidden');
  }

  function hideLoading() {
    loadingDepth = Math.max(0, loadingDepth - 1);
    if (loadingDepth === 0) document.getElementById('loading-overlay').classList.add('hidden');
  }

  async function api(url, options) {
    options = options || {};
    const method = (options.method || 'GET').toUpperCase();
    const headers = Object.assign({}, options.headers || {});
    if (method !== 'GET') headers['X-CSRF-Token'] = config.csrfToken;
    let body = options.body;
    if (body !== undefined && body !== null && !(body instanceof FormData) && typeof body !== 'string') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    showLoading();
    try {
      const response = await fetch(url, { method: method, headers: headers, body: body, credentials: 'same-origin' });
      let data;
      try { data = await response.json(); }
      catch (err) { throw new Error('The server returned an unexpected response.'); }
      if (!response.ok || data.ok === false) {
        const error = new Error(data.error || data.message || 'Request failed (' + response.status + ').');
        error.status = response.status;
        error.data = data;
        throw error;
      }
      return data;
    } finally {
      hideLoading();
    }
  }

  async function downloadFile(url) {
    showLoading();
    try {
      const response = await fetch(url, { credentials: 'same-origin' });
      if (!response.ok) {
        const data = await response.json().catch(function () { return {}; });
        throw new Error(data.error || 'Download failed.');
      }
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";]+)"?/i);
      const filename = match ? match[1] : 'download';
      const blob = await response.blob();
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(link.href); }, 2000);
    } finally {
      hideLoading();
    }
  }

  function toast(message, type) {
    const root = document.getElementById('toast-root');
    const item = document.createElement('div');
    item.className = 'toast ' + (type || '');
    const icon = type === 'success' ? 'bi-check-circle-fill' : type === 'error' ? 'bi-x-circle-fill' : 'bi-info-circle-fill';
    item.innerHTML = '<i class="bi ' + icon + '"></i><span></span>';
    item.querySelector('span').textContent = message;
    root.appendChild(item);
    setTimeout(function () {
      item.style.opacity = '0';
      item.style.transition = 'opacity .25s';
      setTimeout(function () { item.remove(); }, 260);
    }, 4500);
  }

  function openModal(title, bodyHtml, footerHtml, options) {
    options = options || {};
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-body').innerHTML = bodyHtml;
    document.getElementById('modal-footer').innerHTML = footerHtml || '';
    const modal = document.getElementById('app-modal');
    modal.classList.toggle('wide', !!options.wide);
    modal.classList.remove('hidden');
    document.getElementById('modal-backdrop').classList.remove('hidden');
    const first = modal.querySelector('input, select, textarea, button');
    if (first && options.focus !== false) setTimeout(function () { first.focus(); }, 50);
    return document.getElementById('modal-body');
  }

  function closeModal() {
    if (window.POS && typeof window.POS.onModalClose === 'function') {
      window.POS.onModalClose();
      window.POS.onModalClose = null;
    }
    document.getElementById('app-modal').classList.add('hidden');
    document.getElementById('modal-backdrop').classList.add('hidden');
    document.getElementById('modal-body').innerHTML = '';
    document.getElementById('modal-footer').innerHTML = '';
  }

  function confirmDialog(title, message, confirmLabel) {
    return new Promise(function (resolve) {
      document.getElementById('confirm-title').textContent = title;
      document.getElementById('confirm-message').textContent = message;
      document.getElementById('confirm-ok').textContent = confirmLabel || 'Confirm';
      document.getElementById('confirm-backdrop').classList.remove('hidden');
      confirmResolver = resolve;
    });
  }

  function resolveConfirm(value) {
    document.getElementById('confirm-backdrop').classList.add('hidden');
    if (confirmResolver) {
      const resolver = confirmResolver;
      confirmResolver = null;
      resolver(value);
    }
  }

  async function openCameraScanner(hostId, onResult) {
    if (typeof window.Html5Qrcode === 'undefined') {
      toast('Barcode scanner library is unavailable. Check your internet connection.', 'error');
      return;
    }
    closeCameraScanner();
    qrScanner = new window.Html5Qrcode(hostId, { verbose: false });
    try {
      await qrScanner.start(
        { facingMode: 'environment' },
        { fps: 12, qrbox: { width: 260, height: 160 } },
        function (decodedText) {
          closeCameraScanner();
          onResult(String(decodedText).trim());
        },
        function () { /* ignore frames without a barcode */ }
      );
    } catch (err) {
      closeCameraScanner();
      toast('Unable to start the camera: ' + (err && err.message ? err.message : 'permission denied'), 'error');
    }
  }

  function closeCameraScanner() {
    if (!qrScanner) return;
    const scanner = qrScanner;
    qrScanner = null;
    try {
      const stopped = scanner.stop();
      if (stopped && typeof stopped.then === 'function') {
        stopped.then(function () { return scanner.clear(); }).catch(function () {});
      } else {
        scanner.clear();
      }
    } catch (err) { /* ignore cleanup errors */ }
  }

  function printReceipt(markup, width) {
    const holder = document.getElementById('receipt-print');
    holder.innerHTML = '<div class="receipt-paper ' + (width === 58 ? 'receipt-58' : 'receipt-80') + '">' + markup + '</div>';
    window.print();
  }

  function updateLowStock(count) {
    const pill = document.getElementById('low-stock-pill');
    const label = document.getElementById('low-stock-pill-count');
    if (!pill || !label) return;
    label.textContent = String(count);
    pill.classList.toggle('hidden', !(count > 0));
  }

  function parseQuery(params) {
    return Object.keys(params).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    }).join('&');
  }

  const POS = {
    config: config,
    esc: esc,
    money: money,
    api: api,
    downloadFile: downloadFile,
    toast: toast,
    openModal: openModal,
    closeModal: closeModal,
    confirm: confirmDialog,
    openCameraScanner: openCameraScanner,
    closeCameraScanner: closeCameraScanner,
    printReceipt: printReceipt,
    updateLowStock: updateLowStock,
    parseQuery: parseQuery,
    showLoading: showLoading,
    hideLoading: hideLoading
  };
  window.POS = POS;

  function activateTab(name) {
    const valid = ['cashier', 'inventory', 'sales', 'backup'];
    if (valid.indexOf(name) === -1 || (name === 'backup' && !config.isAdmin)) name = 'cashier';
    document.querySelectorAll('.nav-tab').forEach(function (button) {
      button.classList.toggle('active', button.dataset.tab === name);
    });
    document.querySelectorAll('.tab-panel').forEach(function (panel) {
      panel.classList.toggle('active', panel.id === 'panel-' + name);
    });
    if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    const module = { cashier: window.Cashier, inventory: window.Inventory, sales: window.Sales, backup: window.Backup }[name];
    if (module && typeof module.onShow === 'function') module.onShow();
  }
  POS.activateTab = activateTab;

  function init() {
    document.querySelectorAll('#main-nav .nav-tab').forEach(function (button) {
      button.addEventListener('click', function () { activateTab(button.dataset.tab); });
    });
    document.getElementById('modal-close').addEventListener('click', closeModal);
    document.getElementById('modal-backdrop').addEventListener('click', closeModal);
    document.getElementById('confirm-cancel').addEventListener('click', function () { resolveConfirm(false); });
    document.getElementById('confirm-ok').addEventListener('click', function () { resolveConfirm(true); });
    document.getElementById('confirm-backdrop').addEventListener('click', function (event) {
      if (event.target === this) resolveConfirm(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      if (!document.getElementById('confirm-backdrop').classList.contains('hidden')) resolveConfirm(false);
      else if (!document.getElementById('app-modal').classList.contains('hidden')) closeModal();
    });

    ['Cashier', 'Inventory', 'Sales', 'Backup'].forEach(function (name) {
      const module = window[name];
      if (module && typeof module.init === 'function') module.init();
    });
    activateTab((location.hash || '').replace('#', ''));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
