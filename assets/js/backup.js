/* Backup & Restore: SQL download, SMTP email, validated restore, store/SMTP settings. */
(function () {
  'use strict';

  const $ = function (id) { return document.getElementById(id); };
  let pendingToken = '';

  function openEmailModal() {
    const body = '<p class="muted small">The database backup will be generated now and attached to an email using the SMTP settings below.</p>'
      + '<label class="field" style="margin-top:12px">Destination Email<input type="email" id="backup-email" placeholder="owner@example.com" required></label>';
    const footer = '<button class="btn btn-primary" id="send-backup" type="button"><i class="bi bi-envelope-paper"></i> Send Backup</button>'
      + '<button class="btn btn-ghost" id="cancel-email" type="button">Cancel</button>';
    window.POS.openModal('Email Database Backup', body, footer);
    $('cancel-email').addEventListener('click', window.POS.closeModal);
    $('send-backup').addEventListener('click', async function () {
      const email = $('backup-email').value.trim();
      if (!email) { window.POS.toast('Enter a destination email address.', 'error'); return; }
      try {
        const data = await window.POS.api('api/backup?action=email', { method: 'POST', body: { email: email } });
        window.POS.closeModal();
        window.POS.toast(data.message, 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
  }

  async function restoreBackup() {
    const file = $('restore-file').files[0];
    if (!file) { window.POS.toast('Choose a .sql backup file first.', 'error'); return; }
    const form = new FormData();
    form.append('file', file);
    pendingToken = '';
    try {
      const data = await window.POS.api('api/backup?action=upload', { method: 'POST', body: form });
      pendingToken = data.token;
      const confirmed = await window.POS.confirm(
        'Restore database?',
        'This replaces all current products, sales, users, and settings with "' + data.filename
          + '". An automatic safety backup will be created first. Continue?',
        'Restore now'
      );
      if (!confirmed) { pendingToken = ''; return; }
      const result = await window.POS.api('api/backup?action=restore', {
        method: 'POST',
        body: { token: pendingToken, confirm: true }
      });
      pendingToken = '';
      window.POS.toast(result.message, 'success');
      setTimeout(function () { window.location.reload(); }, 1200);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function restoreClientBackup() {
    const file = $('client-restore-file').files[0];
    if (!file) { window.POS.toast('Choose a client backup file first.', 'error'); return; }
    const form = new FormData();
    form.append('file', file);
    pendingToken = '';
    try {
      const data = await window.POS.api('api/client_backup?action=upload', { method: 'POST', body: form });
      pendingToken = data.token;
      const confirmed = await window.POS.confirm(
        'Restore your sales?',
        'This replaces only your sales and sale items from "' + data.filename
          + '". Shared products, stock, users, and settings are untouched. A safety backup of your current sales will be created first. Continue?',
        'Restore my sales'
      );
      if (!confirmed) { pendingToken = ''; return; }
      const result = await window.POS.api('api/client_backup?action=restore', {
        method: 'POST',
        body: { token: pendingToken, confirm: true }
      });
      pendingToken = '';
      window.POS.toast(result.message, 'success');
      setTimeout(function () { window.location.reload(); }, 1200);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function emailClientBackup() {
    try {
      const data = await window.POS.api('api/client_backup?action=email', { method: 'POST', body: {} });
      window.POS.toast(data.message, 'success');
      loadClientBackupHistory();
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function formatBackupSize(bytes) {
    const size = Number(bytes) || 0;
    if (size < 1024) return size + ' B';
    if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB';
    return (size / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function historyCell(row, text, className) {
    const td = document.createElement('td');
    td.textContent = text == null ? '' : String(text);
    if (className) td.className = className;
    row.appendChild(td);
    return td;
  }

  function historyMessageRow(message) {
    const row = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 5;
    td.className = 'center muted';
    td.textContent = message;
    row.appendChild(td);
    return row;
  }

  function historySavedTo(row, entry) {
    const td = document.createElement('td');
    const badge = document.createElement('span');
    if (entry.method === 'device') {
      badge.className = 'badge badge-green';
      badge.textContent = 'Device';
    } else if (entry.method === 'email') {
      badge.className = 'badge badge-amber';
      badge.textContent = 'Email (' + (entry.status === 'accepted' ? 'accepted' : 'sent') + ')';
    } else {
      badge.className = 'badge badge-gray';
      badge.textContent = 'Server (safety)';
    }
    td.appendChild(badge);
    if (entry.recipient) {
      td.appendChild(document.createElement('br'));
      const small = document.createElement('span');
      small.className = 'muted small';
      small.textContent = entry.recipient;
      td.appendChild(small);
    }
    row.appendChild(td);
  }

  async function loadClientBackupHistory() {
    const tbody = $('client-backup-history-list');
    if (!tbody) return;
    try {
      const data = await window.POS.api('api/client_backup?action=history');
      const history = Array.isArray(data.history) ? data.history : [];
      tbody.replaceChildren();
      if (!history.length) {
        tbody.appendChild(historyMessageRow('No backups yet. Save a backup to see it here.'));
        return;
      }
      history.forEach(function (entry) {
        const row = document.createElement('tr');
        historyCell(row, entry.created_at || '');
        historyCell(row, entry.filename || '');
        historySavedTo(row, entry);
        historyCell(row, formatBackupSize(entry.size));
        const sales = Number(entry.sales) || 0;
        const items = Number(entry.items) || 0;
        historyCell(row, sales + ' sale' + (sales === 1 ? '' : 's') + ' / ' + items + ' item' + (items === 1 ? '' : 's'));
        tbody.appendChild(row);
      });
    } catch (error) {
      tbody.replaceChildren();
      tbody.appendChild(historyMessageRow(error.message || 'Unable to load backup history.'));
    }
  }

  function init() {
    if ($('backup-download-btn')) {
      $('backup-download-btn').addEventListener('click', function () {
        window.POS.downloadFile('api/backup?action=download')
          .catch(function (error) { window.POS.toast(error.message, 'error'); });
      });
    }
    if ($('client-backup-download-btn')) {
      $('client-backup-download-btn').addEventListener('click', function () {
        window.POS.downloadFile('api/client_backup?action=download')
          .then(function () { loadClientBackupHistory(); })
          .catch(function (error) { window.POS.toast(error.message, 'error'); });
      });
    }
    if ($('client-backup-email-btn')) $('client-backup-email-btn').addEventListener('click', emailClientBackup);
    if ($('backup-email-btn')) $('backup-email-btn').addEventListener('click', openEmailModal);
    if ($('restore-btn')) $('restore-btn').addEventListener('click', restoreBackup);
    if ($('client-restore-btn')) $('client-restore-btn').addEventListener('click', restoreClientBackup);
    if ($('client-backup-history-refresh')) {
      $('client-backup-history-refresh').addEventListener('click', loadClientBackupHistory);
    }
  }

  window.Backup = {
    init: init,
    onShow: loadClientBackupHistory,
    loadClientBackupHistory: loadClientBackupHistory
  };
})();
