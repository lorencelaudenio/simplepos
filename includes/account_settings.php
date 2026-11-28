<div class="card backup-card" style="margin-top:18px">
  <h2 class="card-title"><i class="bi bi-person-gear"></i> Account Settings</h2>
  <div class="settings-grid">
    <label class="field">Full Name<input type="text" id="account-full_name" maxlength="150" autocomplete="name"></label>
    <label class="field">Email Address<input type="email" id="account-email" maxlength="150" autocomplete="email"></label>
    <label class="field">Current Password<input type="password" id="account-current_password" autocomplete="current-password" placeholder="Required to save"></label>
    <label class="field">New Password <span class="hint">(leave blank to keep current)</span><input type="password" id="account-new_password" minlength="8" autocomplete="new-password"></label>
    <label class="field">Confirm New Password<input type="password" id="account-confirm_password" minlength="8" autocomplete="new-password"></label>
  </div>
  <div class="row-between" style="margin-top:14px;gap:12px;flex-wrap:wrap">
    <button class="btn btn-primary" id="account-save-btn" type="button"><i class="bi bi-check2-circle"></i> Save Account</button>
    <span class="hint">Enter your current password to save changes.</span>
  </div>
</div>
