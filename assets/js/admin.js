/* Admin-only dashboard, user management, and website settings. */
(function () {
  'use strict';

  const $ = function (id) { return document.getElementById(id); };
  let users = [];
  let invites = [];

  function esc(value) { return window.POS.esc(value); }
  function money(value) { return window.POS.money(value); }

  /* ---------- Admin overview ---------- */
  async function loadOverview() {
    if (!$('admin-users')) return;
    try {
      const data = await window.POS.api('api/admin.php?action=overview');
      const o = data.overview || {};
      $('admin-users').textContent = String(o.users || 0);
      $('admin-clients').textContent = String(o.clients || 0);
      $('admin-products').textContent = String(o.products || 0);
      $('admin-lowstock').textContent = String(o.low_stock || 0);
      $('admin-sales-total').textContent = money(o.sales_total);
      $('admin-receipts').textContent = (o.receipts || 0) + ' receipt' + (o.receipts === 1 ? '' : 's');
      const sales = data.recent_sales || [];
      $('admin-recent-sales').innerHTML = sales.length
        ? sales.map(function (sale) {
          return '<tr><td><strong>' + esc(sale.receipt_number) + '</strong></td><td>'
            + esc(String(sale.created_at).replace('T', ' ')) + '</td><td>' + money(sale.total_amount) + '</td></tr>';
        }).join('')
        : '<tr><td colspan="3" class="center muted">No sales recorded yet.</td></tr>';
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  window.AdminDashboard = { init: function () {}, onShow: loadOverview };

  /* ---------- User management ---------- */
  async function loadUsers() {
    if (!$('users-list')) return;
    try {
      const data = await window.POS.api('api/admin.php?action=users_list');
      users = data.users || [];
      const body = $('users-list');
      if (!users.length) {
        body.innerHTML = '<tr><td colspan="6" class="center muted">No users found.</td></tr>';
        return;
      }
      body.innerHTML = users.map(function (user) {
        const role = user.role === 'admin' ? 'Website Admin' : 'Client';
        const roleClass = user.role === 'admin' ? 'badge-green' : 'badge-gray';
        const statusClass = user.status === 'active' ? 'badge-green' : 'badge-red';
        return '<tr><td><strong>' + esc(user.username) + '</strong></td><td>' + esc(user.full_name) + '</td>'
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
    const body = '<input type="hidden" id="u-id" value="' + esc(user.id || '') + '">'
      + '<div class="form-grid">'
      + '<label class="field">Username *<input id="u-username" type="text" maxlength="100" value="' + esc(user.username || '') + '" autocomplete="off"></label>'
      + '<label class="field">Full Name *<input id="u-fullname" type="text" maxlength="150" value="' + esc(user.full_name || '') + '"></label>'
      + '<label class="field">Role *<select id="u-role">'
      + '<option value="client"' + (user.role === 'cashier' || user.role === 'client' || !editing ? ' selected' : '') + '>Client (POS user)</option>'
      + '<option value="admin"' + (user.role === 'admin' ? ' selected' : '') + '>Website Admin</option>'
      + '</select></label>'
      + '<label class="field">Status *<select id="u-status">'
      + '<option value="active"' + (user.status !== 'inactive' ? ' selected' : '') + '>Active</option>'
      + '<option value="inactive"' + (user.status === 'inactive' ? ' selected' : '') + '>Inactive</option>'
      + '</select></label>'
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
        role: $('u-role').value,
        status: $('u-status').value,
        password: $('u-password').value
      };
      try {
        const data = await window.POS.api('api/admin.php?action=user_save', { method: 'POST', body: payload });
        window.POS.closeModal();
        await loadUsers();
        window.POS.toast(data.message, 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
  }

  function initUsers() {
    if (!$('users-list')) return;
    $('add-user-btn').addEventListener('click', function () { openUserModal(null); });
    $('generate-invite-btn').addEventListener('click', openInviteModal);
    $('invites-list').addEventListener('click', async function (event) {
      const button = event.target.closest('button[data-action="revoke"]');
      if (!button) return;
      const code = button.dataset.code;
      const ok = await window.POS.confirm(
        'Revoke invite code?',
        'Code "' + code + '" will stop working immediately. Existing accounts remain active.',
        'Revoke code'
      );
      if (!ok) return;
      try {
        const data = await window.POS.api('api/admin.php?action=invite_revoke', {
          method: 'POST',
          body: { code: code }
        });
        await loadInvites();
        window.POS.toast(data.message, 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
    $('users-list').addEventListener('click', async function (event) {
      const button = event.target.closest('button[data-action]');
      if (!button) return;
      const id = Number(button.dataset.id);
      const user = users.find(function (item) { return Number(item.id) === id; });
      if (!user) return;
      if (button.dataset.action === 'edit') { openUserModal(user); return; }
      if (button.dataset.action === 'toggle') {
        try {
          const data = await window.POS.api('api/admin.php?action=user_toggle', { method: 'POST', body: { id: id } });
          await loadUsers();
          window.POS.toast(data.message, 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
        return;
      }
      if (button.dataset.action === 'delete') {
        const ok = await window.POS.confirm('Delete user?', 'Permanently delete "' + user.username + '"? This cannot be undone.', 'Delete');
        if (!ok) return;
        try {
          const data = await window.POS.api('api/admin.php?action=user_delete', { method: 'POST', body: { id: id } });
          await loadUsers();
          window.POS.toast(data.message, 'success');
        } catch (error) { window.POS.toast(error.message, 'error'); }
      }
    });
  }

  async function loadInvites() {
    if (!$('invites-list')) return;
    try {
      const data = await window.POS.api('api/admin.php?action=invite_list');
      invites = data.invites || [];
      const body = $('invites-list');
      if (!invites.length) {
        body.innerHTML = '<tr><td colspan="6" class="center muted">No invite codes yet. Generate one to allow self-signup.</td></tr>';
        return;
      }
      body.innerHTML = invites.map(function (invite) {
        const status = invite.revoked ? 'Revoked' : invite.expired ? 'Expired'
          : invite.exhausted ? 'Used up' : 'Active';
        const statusClass = invite.usable ? 'badge-green' : 'badge-red';
        const expires = invite.expires_at ? String(invite.expires_at).replace('T', ' ') : 'Never';
        return '<tr><td><strong>' + esc(invite.code) + '</strong></td>'
          + '<td>' + esc(String(invite.created_at || '').replace('T', ' ')) + '</td>'
          + '<td>' + esc(expires) + '</td>'
          + '<td>' + esc(invite.uses || 0) + ' / ' + esc(invite.max_uses || 0) + '</td>'
          + '<td><span class="badge ' + statusClass + '">' + status + '</span></td>'
          + '<td>' + (invite.revoked ? '<span class="muted">—</span>'
            : '<button class="btn btn-sm btn-danger" data-action="revoke" data-code="' + esc(invite.code) + '" type="button"><i class="bi bi-x-circle"></i> Revoke</button>') + '</td></tr>';
      }).join('');
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function openInviteModal() {
    const body = '<div class="form-grid">'
      + '<label class="field">Expires After<select id="i-days">'
      + '<option value="1">1 day</option><option value="7" selected>7 days</option>'
      + '<option value="30">30 days</option><option value="0">Never</option>'
      + '</select></label>'
      + '<label class="field">Maximum Uses<select id="i-uses">'
      + '<option value="1">1 signup</option><option value="5" selected>5 signups</option>'
      + '<option value="25">25 signups</option><option value="100">100 signups</option>'
      + '</select></label>'
      + '</div><p class="muted small" style="margin-top:12px">The code will only create Client accounts. Copy it after creation and share it privately.</p>';
    const footer = '<button class="btn btn-primary" id="save-invite" type="button"><i class="bi bi-ticket-perforated"></i> Generate</button>'
      + '<button class="btn btn-ghost" id="cancel-invite" type="button">Cancel</button>';
    window.POS.openModal('Generate Invite Code', body, footer);
    $('cancel-invite').addEventListener('click', window.POS.closeModal);
    $('save-invite').addEventListener('click', async function () {
      try {
        const data = await window.POS.api('api/admin.php?action=invite_create', {
          method: 'POST',
          body: { days: Number($('i-days').value), max_uses: Number($('i-uses').value) }
        });
        window.POS.closeModal();
        await loadInvites();
        window.POS.toast('Invite code ' + data.invite.code + ' created.', 'success');
      } catch (error) { window.POS.toast(error.message, 'error'); }
    });
  }

  window.Users = {
    init: initUsers,
    onShow: function () { loadUsers(); loadInvites(); }
  };

  /* ---------- Website settings ---------- */
  async function loadSettings() {
    if (!$('setting-store_name')) return;
    try {
      const data = await window.POS.api('api/settings.php?action=get');
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
    const keys = ['store_name', 'store_address', 'receipt_footer', 'smtp_host', 'smtp_port', 'smtp_secure',
      'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name'];
    const payload = {};
    keys.forEach(function (key) {
      const input = $('setting-' + key);
      payload[key] = input ? input.value.trim() : '';
    });
    try {
      const data = await window.POS.api('api/settings.php?action=save', { method: 'POST', body: payload });
      window.POS.toast(data.message, 'success');
      loadSettings();
    } catch (error) { window.POS.toast(error.message, 'error'); }
  }

  function initSettings() {
    if (!$('settings-save-btn')) return;
    $('settings-save-btn').addEventListener('click', saveSettings);
  }

  window.WebsiteSettings = { init: initSettings, onShow: loadSettings };
})();
