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
          .catch(function (error) { window.POS.toast(error.message, 'error'); });
      });
    }
    if ($('backup-email-btn')) $('backup-email-btn').addEventListener('click', openEmailModal);
    if ($('restore-btn')) $('restore-btn').addEventListener('click', restoreBackup);
    if ($('client-restore-btn')) $('client-restore-btn').addEventListener('click', restoreClientBackup);
  }

  window.Backup = { init: init, onShow: function () {} };
})();
