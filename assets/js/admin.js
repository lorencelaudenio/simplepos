/* Admin-only dashboard, user management, and website settings. */
(function () {
  'use strict';

  const $ = function (id) { return document.getElementById(id); };
  let users = [];

  function esc(value) { return window.POS.esc(value); }

  /* ---------- Admin overview ---------- */
  async function loadOverview() {
    if (!$('admin-users')) return;
    try {
      const data = await window.POS.api('api/admin?action=overview');
      const o = data.overview || {};
      $('admin-users').textContent = String(o.users || 0);
      $('admin-clients').textContent = String(o.clients || 0);
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  window.AdminDashboard = { init: function () {}, onShow: loadOverview };

  /* ---------- User management ---------- */
  async function loadUsers() {
    if (!$('users-list')) return;
    try {
      const data = await window.POS.api('api/admin?action=users_list');
      users = data.users || [];
      const body = $('users-list');
      if (!users.length) {
        body.innerHTML = '<tr><td colspan="7" class="center muted">No users found.</td></tr>';
        return;
      }
      body.innerHTML = users.map(function (user) {
        const role = user.role === 'admin' ? 'Website Admin' : 'Client';
        const roleClass = user.role === 'admin' ? 'badge-green' : 'badge-gray';
        const statusClass = user.status === 'active' ? 'badge-green' : 'badge-red';
        return '<tr><td><strong>' + esc(user.username) + '</strong></td><td>' + esc(user.full_name) + '</td>'
          + '<td>' + esc(user.email || '—') + '</td>'
          + '<td><span class="badge ' + roleClass + '">' + role + '</span></td>'
          + '<td><span class="badge ' + statusClass + '">' + esc(user.status) + '</span></td>'
          + '<td>' + esc(String(user.created_at).replace('T', ' ')) + '</td>'
          + '<td><button class="btn btn-sm" data-action="edit" data-id="' + esc(user.id) + '" type="button"><i class="bi bi-pencil"></i> Edit</button> '
          + '<button class="btn btn-sm" data-action="toggle" data-id="' + esc(user.id) + '" type="button">'
          + (user.status === 'active' ? 'Disable' : 'Enable') + '</button> '
          + '<button class="btn btn-sm btn-danger" data-action="delete" data-id="' + esc(user.id) + '" type="button"><i class="bi bi-trash3"></i></button></td></tr>';
      }).join('');
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function openUserModal(user) {
    user = user || {};
    const editing = !!user.id;
    const isAdminUser = editing && user.role === 'admin';
    const roleOptions = isAdminUser
      ? '<option value="admin" selected>Website Admin</option>'
      : '<option value="client" selected>Client (POS user)</option>';
    const statusOptions = isAdminUser
      ? '<option value="active" selected>Active</option>'
      : '<option value="active"' + (user.status !== 'inactive' ? ' selected' : '') + '>Active</option>'
        + '<option value="inactive"' + (user.status === 'inactive' ? ' selected' : '') + '>Inactive</option>';
    const body = '<input type="hidden" id="u-id" value="' + esc(user.id || '') + '">'
      + '<div class="form-grid">'
      + '<label class="field">Username *<input id="u-username" type="text" maxlength="100" value="' + esc(user.username || '') + '" autocomplete="off"></label>'
      + '<label class="field">Full Name *<input id="u-fullname" type="text" maxlength="150" value="' + esc(user.full_name || '') + '"></label>'
      + '<label class="field span-2">Email Address ' + (editing ? '<span class="hint">(leave blank to keep current)</span>' : '*')
      + '<input id="u-email" type="email" maxlength="150" value="' + esc(user.email || '') + '" autocomplete="email"></label>'
      + '<label class="field">Role *<select id="u-role">' + roleOptions + '</select>'
      + '<span class="hint">Only one website administrator is allowed.</span></label>'
      + '<label class="field">Status *<select id="u-status">' + statusOptions + '</select></label>'
      + '<label class="field span-2">Password ' + (editing ? '<span class="hint">(leave blank to keep current)</span>' : '*')
      + '<input id="u-password" type="password" minlength="8" autocomplete="new-password"></label>'
      + '</div>';
    const footer = '<button class="btn btn-primary" id="save-user" type="button"><i class="bi bi-check2-circle"></i> Save User</button>'
      + '<button class="btn btn-ghost" id="cancel-user" type="button">Cancel</button>';
    window.POS.openModal(editing ? 'Edit User' : 'Add User', body, footer);
    $('cancel-user').addEventListener('click', window.POS.closeModal);
    $('save-user').addEventListener('click', async function () {
      const payload = {
        id: Number($('u-id').value || 0),
        username: $('u-username').value.trim(),
        full_name: $('u-fullname').value.trim(),
        email: $('u-email').value.trim(),
        role: $('u-role').value,
        status: $('u-status').value,
        password: $('u-password').value
      };
      try {
        const data = await window.POS.api('api/admin?action=user_save', { method: 'POST', body: payload });
        window.POS.closeModal();
        await loadUsers();
        window.POS.toast(data.message, 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
  }

  function initUsers() {
    if (!$('users-list')) return;
    $('add-user-btn').addEventListener('click', function () { openUserModal(null); });
    $('users-list').addEventListener('click', async function (event) {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      const id = Number(button.dataset.id);
      const user = users.find(function (item) { return Number(item.id) === id; });
      if (!user) return;
      if (button.dataset.action === 'edit') { openUserModal(user); return; }
      if (button.dataset.action === 'toggle') {
        try {
          const data = await window.POS.api('api/admin?action=user_toggle', { method: 'POST', body: { id: id } });
          await loadUsers();
          window.POS.toast(data.message, 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
        return;
      }
      if (button.dataset.action === 'delete') {
        const ok = await window.POS.confirm('Delete user?', 'Permanently delete "' + user.username + '"? This cannot be undone.', 'Delete');
        if (!ok) return;
        try {
          const data = await window.POS.api('api/admin?action=user_delete', { method: 'POST', body: { id: id } });
          await loadUsers();
          window.POS.toast(data.message, 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
      }
    });
  }

  window.Users = { init: initUsers, onShow: loadUsers };

  /* ---------- Settings and account ---------- */
  const settingKeys = ['store_name', 'store_address', 'receipt_footer', 'smtp_host', 'smtp_port', 'smtp_secure',
    'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name'];

  async function loadSettings() {
    if (!$('settings-save-btn')) return;
    try {
      const data = await window.POS.api('api/settings?action=get');
      const values = data.settings || {};
      Object.keys(values).forEach(function (key) {
        const input = $('setting-' + key);
        if (input) input.value = values[key] == null ? '' : values[key];
      });
      const pass = $('setting-smtp_pass');
      if (pass && values.smtp_pass_configured) pass.placeholder = 'Password saved — leave blank to keep';
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function saveSettings() {
    const payload = {};
    settingKeys.forEach(function (key) {
      const input = $('setting-' + key);
      if (input) payload[key] = input.value.trim();
    });
    if (Object.keys(payload).length === 0) return;
    try {
      const data = await window.POS.api('api/settings?action=save', { method: 'POST', body: payload });
      const saved = data.settings || {};
      Object.keys(saved).forEach(function (key) {
        const input = $('setting-' + key);
        if (input) input.value = saved[key] == null ? '' : saved[key];
      });
      const pass = $('setting-smtp_pass');
      if (pass) pass.placeholder = saved.smtp_pass_configured
        ? 'Password saved — leave blank to keep'
        : 'Leave blank to keep current';
      window.POS.toast(data.message || 'Settings saved.', 'success');
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function loadAccount() {
    if (!$('account-save-btn')) return;
    try {
      const data = await window.POS.api('api/profile?action=get');
      const profile = data.profile || {};
      ['full_name', 'email'].forEach(function (key) {
        const input = $('account-' + key);
        if (input) input.value = profile[key] == null ? '' : profile[key];
      });
      const clientEmailLabel = $('client-backup-email-label');
      const clientEmailBtn = $('client-backup-email-btn');
      const backupEmail = profile.backup_email || '';
      if (clientEmailLabel) {
        clientEmailLabel.textContent = backupEmail
          ? 'Backup email: ' + backupEmail
          : 'No verified email on file. Add and confirm one under Account Settings first.';
      }
      if (clientEmailBtn) clientEmailBtn.disabled = backupEmail === '';
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function saveAccount() {
    if (!$('account-save-btn')) return;
    const payload = {
      full_name: $('account-full_name').value.trim(),
      email: $('account-email').value.trim(),
      current_password: $('account-current_password').value,
      new_password: $('account-new_password').value,
      confirm_password: $('account-confirm_password').value
    };
    try {
      const data = await window.POS.api('api/profile?action=save', { method: 'POST', body: payload });
      ['account-current_password', 'account-new_password', 'account-confirm_password'].forEach(function (id) {
        const input = $(id);
        if (input) input.value = '';
      });
      window.POS.toast(data.message || 'Account updated.', 'success');
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  async function sendTestEmail() {
    const input = $('test-email-address');
    const email = input ? input.value.trim() : '';
    if (!email) { window.POS.toast('Enter an email address to test.', 'error'); return; }
    try {
      const data = await window.POS.api('api/settings?action=test_email', {
        method: 'POST',
        body: { email: email }
      });
      window.POS.toast(data.message || 'Test email sent.', 'success');
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function initSettings() {
    const saveBtn = $('settings-save-btn');
    if (saveBtn) saveBtn.addEventListener('click', saveSettings);
    const testBtn = $('settings-test-btn');
    if (testBtn) testBtn.addEventListener('click', sendTestEmail);
    const accountBtn = $('account-save-btn');
    if (accountBtn) accountBtn.addEventListener('click', saveAccount);
  }

  window.WebsiteSettings = {
    init: initSettings,
    onShow: function () {
      loadSettings();
      loadAccount();
    }
  };
})();
