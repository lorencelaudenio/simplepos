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
        const data = await window.POS.api('api/backup.php?action=email', { method: 'POST', body: { email: email } });
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
      const data = await window.POS.api('api/backup.php?action=upload', { method: 'POST', body: form });
      pendingToken = data.token;
      const confirmed = await window.POS.confirm(
        'Restore database?',
        'This replaces all current products, sales, users, and settings with "' + data.filename
          + '". An automatic safety backup will be created first. Continue?',
        'Restore now'
      );
      if (!confirmed) { pendingToken = ''; return; }
      const result = await window.POS.api('api/backup.php?action=restore', {
        method: 'POST',
        body: { token: pendingToken, confirm: true }
      });
      pendingToken = '';
      window.POS.toast(result.message, 'success');
      setTimeout(function () { window.location.reload(); }, 1200);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function loadSettings() {
    try {
      const data = await window.POS.api('api/settings.php?action=get');
      const values = data.settings || {};
      Object.keys(values).forEach(function (key) {
        const input = $('setting-' + key);
        if (input) input.value = values[key] == null ? '' : values[key];
      });
      const passNotice = document.querySelector('#setting-smtp_pass');
      if (passNotice && values.smtp_pass_configured) passNotice.placeholder = 'Password saved — leave blank to keep';
    } catch (error) { /* settings are optional for cashier use */ }
  }

  async function saveSettings() {
    const keys = ['store_name', 'store_address', 'receipt_footer', 'smtp_host', 'smtp_port', 'smtp_secure',
      'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name'];
    const payload = {};
    keys.forEach(function (key) { payload[key] = $('setting-' + key).value.trim(); });
    if (!payload.store_name) { window.POS.toast('Store name is required.', 'error'); return; }
    try {
      const data = await window.POS.api('api/settings.php?action=save', { method: 'POST', body: payload });
      window.POS.toast(data.message, 'success');
      loadSettings();
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function init() {
    if (!$('backup-download-btn')) return;
    $('backup-download-btn').addEventListener('click', function () {
      window.POS.downloadFile('api/backup.php?action=download')
        .catch(function (error) { window.POS.toast(error.message, 'error'); });
    });
    $('backup-email-btn').addEventListener('click', openEmailModal);
    $('restore-btn').addEventListener('click', restoreBackup);
    $('settings-save-btn').addEventListener('click', saveSettings);
  }

  window.Backup = { init: init, onShow: loadSettings };
})();
