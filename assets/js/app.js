/* Shared client helpers: API calls, toasts, modals, tabs, printing helpers. */
(function () {
  'use strict';

  const config = window.POS_CONFIG || { csrfToken: '', isAdmin: false, storeName: 'My Store' };
  let loadingDepth = 0;
  let qrScanner = null;
  let scannerGeneration = 0;
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

  async function listCameras() {
    if (typeof window.Html5Qrcode === 'undefined' || typeof window.Html5Qrcode.getCameras !== 'function') return [];
    try {
      const cameras = await window.Html5Qrcode.getCameras();
      return Array.isArray(cameras) ? cameras : [];
    } catch (err) { return []; }
  }

  function isMobileDevice() {
    const ua = navigator.userAgent || '';
    return /Android|iPhone|iPad|iPod|Mobile/i.test(ua)
      || (navigator.maxTouchPoints > 1 && window.matchMedia('(max-width: 900px)').matches);
  }

  function browserAllowsCamera() {
    const host = window.location.hostname;
    return window.isSecureContext === true
      || window.location.protocol === 'https:'
      || host === 'localhost'
      || host === '127.0.0.1';
  }

  function preferredCamera(cameras) {
    const back = cameras.find(function (camera) {
      return /back|rear|environment|world|main/i.test(camera.label || '');
    });
    // Phones usually list the front camera first and the back camera last.
    return back || cameras[cameras.length - 1] || null;
  }

  function withTimeout(promise, milliseconds, message) {
    return new Promise(function (resolve, reject) {
      const timer = setTimeout(function () { reject(new Error(message)); }, milliseconds);
      Promise.resolve(promise).then(function (value) {
        clearTimeout(timer);
        resolve(value);
      }, function (err) {
        clearTimeout(timer);
        reject(err);
      });
    });
  }

  function sampleVideoFrame(video) {
    try {
      const size = 16;
      const canvas = document.createElement('canvas');
      canvas.width = size;
      canvas.height = size;
      const context = canvas.getContext('2d', { willReadFrequently: true });
      if (!context) return null;
      context.drawImage(video, 0, 0, size, size);
      const pixels = context.getImageData(0, 0, size, size).data;
      let sum = 0;
      let sumSquares = 0;
      const count = pixels.length / 4;
      for (let index = 0; index < pixels.length; index += 4) {
        const brightness = 0.299 * pixels[index] + 0.587 * pixels[index + 1] + 0.114 * pixels[index + 2];
        sum += brightness;
        sumSquares += brightness * brightness;
      }
      const mean = sum / count;
      return { mean: mean, variance: sumSquares / count - mean * mean };
    } catch (err) { return null; }
  }

  function waitForVideo(hostId, milliseconds) {
    return new Promise(function (resolve) {
      const started = Date.now();
      let initialTime = null;
      let blackSince = 0;
      function check() {
        const host = document.getElementById(hostId);
        const video = host ? host.querySelector('video') : null;
        if (video && video.readyState >= 2 && video.videoWidth > 0) {
          if (video.paused) video.play().catch(function () {});
          if (initialTime === null) {
            initialTime = video.currentTime;
          } else if (video.currentTime > initialTime + 0.01) {
            const frame = sampleVideoFrame(video);
            if (!frame || (frame.mean > 10 && frame.variance > 4)) {
              // Frames are advancing and the image is not a solid black rectangle.
              resolve(true);
              return;
            }
            if (frame.mean <= 10 && frame.variance <= 4) {
              if (!blackSince) blackSince = Date.now();
              if (Date.now() - blackSince >= 1200) {
                resolve(false);
                return;
              }
            }
          }
        }
        if (Date.now() - started >= milliseconds) {
          resolve(false);
          return;
        }
        setTimeout(check, 100);
      }
      check();
    });
  }

  async function populateCameraSelect(selectId) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const cameras = await listCameras();
    const current = select.value;
    select.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = isMobileDevice() ? 'Auto (back preferred)' : 'Default camera';
    select.appendChild(defaultOption);
    [['front', 'Front camera'], ['back', 'Back camera']].forEach(function (choice) {
      const option = document.createElement('option');
      option.value = choice[0];
      option.textContent = choice[1];
      select.appendChild(option);
    });
    cameras.forEach(function (camera, index) {
      const option = document.createElement('option');
      option.value = camera.id;
      option.textContent = camera.label || ('Camera ' + (index + 1));
      select.appendChild(option);
    });
    if (current === 'front' || current === 'back'
      || cameras.some(function (camera) { return camera.id === current; })) {
      select.value = current;
    }
  }

  async function openCameraScanner(hostId, onResult, cameraId, onStarted) {
    if (!browserAllowsCamera()) {
      toast('Camera access requires HTTPS. Use the HTTPS version of this site or enter the barcode manually.', 'error');
      return;
    }
    if (typeof window.Html5Qrcode === 'undefined') {
      toast('Barcode scanner library is unavailable. Check your internet connection.', 'error');
      return;
    }

    let lastError = null;

    async function start(source) {
      closeCameraScanner();
      const scanner = new window.Html5Qrcode(hostId, { verbose: false });
      qrScanner = scanner;
      let completed = false;
      try {
        await withTimeout(scanner.start(
          source,
          { fps: 12, qrbox: { width: 260, height: 160 } },
          function (decodedText) {
            completed = true;
            closeCameraScanner();
            onResult(String(decodedText).trim());
          },
          function () { /* ignore frames without a barcode */ }
        ), 7000, 'Camera start timed out.');
        if (completed) return true;
        if (!(await waitForVideo(hostId, 3000))) {
          throw new Error('Camera preview did not start.');
        }
        if (typeof onStarted === 'function') onStarted();
        return true;
      } catch (err) {
        lastError = err;
        closeCameraScanner();
        return false;
      }
    }

    const mobile = isMobileDevice();

    if (cameraId === 'front') {
      if (await start({ facingMode: 'user' })) return;
    } else if (cameraId === 'back') {
      if (await start({ facingMode: 'environment' })) return;
      if (await start({ facingMode: 'user' })) {
        toast('Back camera did not start; switched to front camera.', '');
        return;
      }
    } else if (cameraId) {
      if (await start({ deviceId: { exact: cameraId } })) return;
    } else if (mobile) {
      // Prefer the back camera, but never leave the scanner stuck if that camera hangs.
      if (await start({ facingMode: 'environment' })) return;
      if (await start({ facingMode: 'user' })) {
        toast('Back camera did not start; switched to the front camera.', '');
        return;
      }
      const cameras = await listCameras();
      const preferred = preferredCamera(cameras);
      if (preferred && await start({ deviceId: { exact: preferred.id } })) return;
      for (const camera of cameras) {
        if (preferred && camera.id === preferred.id) continue;
        if (await start({ deviceId: { exact: camera.id } })) return;
      }
    } else {
      if (await start({ facingMode: 'environment' })) return;
      const cameras = await listCameras();
      for (const camera of cameras) {
        if (await start({ deviceId: { exact: camera.id } })) return;
      }
    }

    const reason = lastError && lastError.message ? lastError.message : 'permission denied';
    const hint = /denied|notallowed|permission/i.test(reason)
      ? ' On iPhone, use HTTPS and allow Camera in Safari Settings → Website Settings for this site, then reload.'
      : '';
    toast('Unable to start the camera: ' + reason + hint, 'error');
  }

  function closeCameraScanner() {
    if (!qrScanner) return;
    const scanner = qrScanner;
    const generation = ++scannerGeneration;
    qrScanner = null;
    try {
      const stopped = scanner.stop();
      if (stopped && typeof stopped.then === 'function') {
        stopped.then(function () {
          // A newer scanner may already be starting; do not clear its preview.
          if (generation === scannerGeneration) scanner.clear();
        }).catch(function () {});
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
    listCameras: listCameras,
    populateCameraSelect: populateCameraSelect,
    printReceipt: printReceipt,
    updateLowStock: updateLowStock,
    parseQuery: parseQuery,
    showLoading: showLoading,
    hideLoading: hideLoading
  };
  window.POS = POS;

  function activateTab(name) {
    const valid = config.isAdmin
      ? ['dashboard', 'users', 'settings', 'backup']
      : ['cashier', 'inventory', 'sales', 'settings'];
    if (valid.indexOf(name) === -1) name = valid[0];
    document.querySelectorAll('.nav-tab').forEach(function (button) {
      button.classList.toggle('active', button.dataset.tab === name);
    });
    document.querySelectorAll('.tab-panel').forEach(function (panel) {
      panel.classList.toggle('active', panel.id === 'panel-' + name);
    });
    if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    const modules = {
      cashier: window.Cashier,
      inventory: window.Inventory,
      sales: window.Sales,
      dashboard: window.AdminDashboard,
      users: window.Users,
      settings: window.WebsiteSettings,
      backup: window.Backup
    };
    const module = modules[name];
    if (module && typeof module.onShow === 'function') module.onShow(name);
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

    ['Cashier', 'Inventory', 'Sales', 'AdminDashboard', 'Users', 'WebsiteSettings', 'Backup'].forEach(function (name) {
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
