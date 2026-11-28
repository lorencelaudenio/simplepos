<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Guests see a public hero/landing page; signed-in users get their dashboard.
if (!current_user()) {
    include __DIR__ . '/includes/hero.php';
    exit;
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php if (!is_admin()): ?>
<section class="tab-panel active" id="panel-cashier" aria-label="Cashier">
  <div class="page-heading">
    <div><h1>Cashier</h1><p>Search a product or scan its barcode, then collect payment.</p></div>
    <span class="badge badge-green"><i class="bi bi-lightning-charge"></i> Fast checkout</span>
  </div>
  <div class="cashier-grid">
    <div class="card cashier-search-card">
      <div class="row-between" style="margin-bottom:12px">
        <h2 class="card-title" style="margin:0"><i class="bi bi-search"></i> Product Search</h2>
        <button class="btn btn-sm" id="cashier-scan-btn" type="button"><i class="bi bi-qr-code-scan"></i> Scan</button>
      </div>
      <div class="search-wrap">
        <i class="bi bi-search"></i>
        <input id="product-search" type="text" autocomplete="off" spellcheck="false"
               placeholder="Type product name or scan barcode..." aria-label="Search products">
      </div>
      <p class="muted small" style="margin:9px 0 0">USB scanners type into this field automatically and press Enter to add.</p>
      <div class="product-results" id="product-results">
        <div class="empty-state"><i class="bi bi-bag"></i>Start typing to find products.</div>
      </div>
    </div>

    <div class="card cart-card">
      <div class="row-between" style="margin-bottom:12px">
        <h2 class="card-title" style="margin:0"><i class="bi bi-cart3"></i> Shopping Cart <span class="badge badge-gray" id="cart-count">0</span></h2>
        <button class="btn btn-sm btn-ghost" id="clear-cart-btn" type="button"><i class="bi bi-trash3"></i> Clear</button>
      </div>
      <div class="cart-items" id="cart-items">
        <div class="empty-state"><i class="bi bi-cart"></i>Your cart is empty.</div>
      </div>
      <div class="cart-totals">
        <div class="total-line"><span class="muted">Total</span><span class="big" id="cart-total">₱0.00</span></div>
        <div class="payment-grid">
          <label class="field">Cash Received
            <input id="cash-received" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
          </label>
          <label class="field">Change
            <input id="change-due" type="text" value="₱0.00" readonly>
          </label>
        </div>
        <div class="payment-actions">
          <button class="btn btn-primary" id="pay-btn" type="button" disabled><i class="bi bi-cash-stack"></i> PAY</button>
          <button class="btn" id="print-receipt-btn" type="button" disabled><i class="bi bi-printer"></i> PRINT RECEIPT</button>
        </div>
        <input type="hidden" id="last-receipt-id" value="">
      </div>
    </div>
  </div>
</section>

<section class="tab-panel" id="panel-inventory" aria-label="Inventory">
  <div class="page-heading">
    <div><h1>Products &amp; Inventory</h1><p>Manage products, stock levels, and reorder alerts.</p></div>
    <div class="heading-actions">
      <button class="btn btn-primary" id="add-product-btn" type="button"><i class="bi bi-plus-lg"></i> Add Product</button>
      <button class="btn" id="import-btn" type="button"><i class="bi bi-file-earmark-arrow-up"></i> Import from Excel</button>
      <button class="btn" id="export-inventory-btn" type="button"><i class="bi bi-file-earmark-arrow-down"></i> Export to Excel</button>
    </div>
  </div>
  <div class="low-stock-alert hidden" id="low-stock-alert">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:20px"></i>
    <strong id="low-stock-alert-text">Low stock alert</strong>
    <div style="flex:1"></div>
    <button class="btn btn-sm" id="reorder-now-btn" type="button">Reorder Now</button>
  </div>
  <div class="inventory-toolbar">
    <div class="search-wrap"><i class="bi bi-search"></i><input id="inventory-search" type="search" placeholder="Search by name or barcode..."></div>
    <label class="checkbox-line"><input type="checkbox" id="low-stock-toggle"> Low stock only</label>
    <label class="checkbox-line"><input type="checkbox" id="show-archived-toggle"> Show archived</label>
    <span class="muted small" id="inventory-count"></span>
  </div>
  <div class="product-grid" id="product-grid">
    <div class="empty-state"><i class="bi bi-box-seam"></i>No products found.</div>
  </div>
</section>

<section class="tab-panel" id="panel-sales" aria-label="Sales History">
  <div class="page-heading">
    <div><h1>Sales History</h1><p>Review transactions, summaries, and daily sales.</p></div>
    <button class="btn" id="export-sales-btn" type="button"><i class="bi bi-file-earmark-excel"></i> Export to Excel</button>
  </div>
  <div class="filter-bar" id="sales-filters">
    <button class="btn btn-sm filter-btn active" data-filter="today" type="button">Today</button>
    <button class="btn btn-sm filter-btn" data-filter="yesterday" type="button">Yesterday</button>
    <button class="btn btn-sm filter-btn" data-filter="week" type="button">This Week</button>
    <button class="btn btn-sm filter-btn" data-filter="month" type="button">This Month</button>
    <button class="btn btn-sm filter-btn" data-filter="year" type="button">This Year</button>
    <button class="btn btn-sm filter-btn" data-filter="all" type="button">All</button>
    <button class="btn btn-sm filter-btn" data-filter="custom" type="button">Custom</button>
    <div class="date-range hidden" id="custom-range">
      <input type="date" id="date-from" aria-label="From date">
      <span class="muted">to</span>
      <input type="date" id="date-to" aria-label="To date">
      <button class="btn btn-sm btn-primary" id="apply-custom" type="button">Apply</button>
    </div>
  </div>
  <div class="summary-grid">
    <div class="card summary-card">
      <h2 class="card-title"><i class="bi bi-bar-chart-line"></i> Sales Summary</h2>
      <div class="summary-stat"><div class="label">Total Sales Amount</div><div class="value" id="sum-total">₱0.00</div></div>
      <div class="summary-stat"><div class="label">Number of Receipts</div><div class="value" id="sum-receipts">0</div></div>
      <div class="summary-stat"><div class="label">Average Transaction Value</div><div class="value" id="sum-average">₱0.00</div></div>
    </div>
    <div class="card summary-card">
      <h2 class="card-title"><i class="bi bi-graph-up"></i> Daily Sales</h2>
      <div class="chart-box"><canvas id="sales-chart"></canvas></div>
    </div>
  </div>
  <div class="card" style="padding:16px">
    <div class="row-between" style="margin-bottom:12px"><h2 class="card-title" style="margin:0">Transactions</h2><span class="badge badge-gray" id="sales-count">0 receipts</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Receipt #</th><th>Date &amp; Time</th><th>Items</th><th>Total</th><th>Cash</th><th>Change</th><th></th></tr></thead>
        <tbody id="sales-list"><tr><td colspan="7" class="center muted">No sales found for this period.</td></tr></tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="panel-settings" aria-label="Settings">
  <div class="page-heading">
    <div><h1>Settings</h1><p>Manage store receipt details, your account, and your sales backup.</p></div>
  </div>
  <div class="card backup-card">
    <h2 class="card-title"><i class="bi bi-shop"></i> Store Settings</h2>
    <p class="muted small">Store name, address, TIN ID, and contact number appear in the header of printed receipts. Leave optional fields blank to hide them.</p>
    <div class="settings-grid" style="margin-top:12px">
      <label class="field">Store Name<input type="text" id="setting-store_name"><span class="muted small">Shown on your receipts and logged-in POS.</span></label>
      <label class="field">Store Address<input type="text" id="setting-store_address"></label>
      <label class="field">TIN ID (optional)<input type="text" id="setting-tin_id" maxlength="100" placeholder="e.g. 123-456-789"></label>
      <label class="field">Contact Number (optional)<input type="text" id="setting-contact_number" maxlength="100" placeholder="e.g. 09xx xxx xxxx"></label>
      <label class="field span-2">Receipt Footer<input type="text" id="setting-receipt_footer"></label>
    </div>
    <div class="row-between" style="margin-top:14px;gap:12px;flex-wrap:wrap">
      <button class="btn btn-primary" id="settings-save-btn" type="button"><i class="bi bi-check2-circle"></i> Save Store Settings</button>
    </div>
  </div>
  <?php include __DIR__ . '/includes/account_settings.php'; ?>
  <div class="backup-grid" style="margin-top:18px">
    <div class="card backup-card">
      <h2 class="card-title"><i class="bi bi-cloud-download"></i> My Backup</h2>
      <p class="muted small">Choose how to save a backup of only your sales and sale items. Shared products, stock, users, and settings are not included.</p>
      <button class="btn btn-primary btn-block" id="client-backup-download-btn" type="button" style="margin-top:12px"><i class="bi bi-download"></i> Save to Device</button>
      <button class="btn btn-block" id="client-backup-email-btn" type="button" style="margin-top:8px"><i class="bi bi-envelope-paper"></i> Send to My Email</button>
      <p class="muted small" id="client-backup-email-label" style="margin-top:8px"></p>
    </div>
    <div class="card backup-card">
      <h2 class="card-title"><i class="bi bi-cloud-upload"></i> My Restore</h2>
      <p class="muted small">Upload a client backup to replace only your sales and sale items. A safety backup of your current sales is created first.</p>
      <label class="field" style="margin-top:12px">Backup File<input type="file" id="client-restore-file" accept=".json,application/json"></label>
      <button class="btn btn-danger btn-block" id="client-restore-btn" type="button" style="margin-top:12px"><i class="bi bi-exclamation-octagon"></i> Restore My Sales</button>
    </div>
  </div>
  <div class="card backup-card" style="margin-top:18px">
    <div class="row-between" style="gap:12px;flex-wrap:wrap">
      <h2 class="card-title"><i class="bi bi-clock-history"></i> Backup History</h2>
      <button class="btn btn-sm" id="client-backup-history-refresh" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>
    <p class="muted small">Your most recent 50 backups and automatic safety copies, with where each one was saved.</p>
    <div class="table-wrap" style="margin-top:12px">
      <table>
        <thead><tr><th>Date</th><th>File</th><th>Saved To</th><th>Size</th><th>Sales</th></tr></thead>
        <tbody id="client-backup-history-list"><tr><td colspan="5" class="center muted">Loading history...</td></tr></tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (is_admin()): ?>
<section class="tab-panel active" id="panel-dashboard" aria-label="Admin Dashboard">
  <div class="page-heading">
    <div><h1>Website Admin Dashboard</h1><p>Manage accounts, website settings, and database backups.</p></div>
    <span class="badge badge-green"><i class="bi bi-shield-lock"></i> Administrator</span>
  </div>
  <div class="card summary-card" style="margin-bottom:16px">
    <h2 class="card-title"><i class="bi bi-people"></i> Accounts</h2>
    <div class="summary-stat"><div class="label">Total Users</div><div class="value" id="admin-users">0</div></div>
    <div class="summary-stat"><div class="label">POS Clients</div><div class="value" id="admin-clients">0</div></div>
  </div>
</section>

<section class="tab-panel" id="panel-users" aria-label="Users">
  <div class="page-heading">
    <div><h1>Users</h1><p>Create website administrators and POS client accounts.</p></div>
    <button class="btn btn-primary" id="add-user-btn" type="button"><i class="bi bi-person-plus"></i> Add User</button>
  </div>
  <div class="notice"><i class="bi bi-info-circle"></i> Client accounts use the Cashier, Inventory, Sales History, and Settings tabs. New signups become active as soon as they confirm their email. Website administrators only see this admin dashboard.</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody id="users-list"><tr><td colspan="7" class="center muted">Loading users...</td></tr></tbody>
    </table>
  </div>
</section>

<section class="tab-panel" id="panel-settings" aria-label="Website Settings">
  <div class="page-heading">
    <div><h1>Website Settings</h1><p>Outgoing mail configuration and your account.</p></div>
  </div>
  <div class="card backup-card">
    <div class="settings-grid">
      <label class="field">Website Name<input type="text" id="setting-website_name" placeholder="Simple Online POS"><span class="muted small">Shown to visitors and on account emails.</span></label>
      <label class="field">SMTP Host<input type="text" id="setting-smtp_host" placeholder="smtp.example.com"></label>
      <label class="field">SMTP Port<input type="number" id="setting-smtp_port" value="587"></label>
      <label class="field">Security
        <select id="setting-smtp_secure"><option value="tls">TLS (STARTTLS)</option><option value="ssl">SSL</option><option value="none">None</option></select>
      </label>
      <label class="field">SMTP Username<input type="text" id="setting-smtp_user" autocomplete="off"></label>
      <label class="field">SMTP Password<input type="password" id="setting-smtp_pass" placeholder="Leave blank to keep current" autocomplete="new-password"></label>
      <label class="field">From Email<input type="email" id="setting-smtp_from" placeholder="you@example.com"><span class="muted small">Use your SMTP login address or a verified sender.</span></label>
      <label class="field">From Name<input type="text" id="setting-smtp_from_name"></label>
    </div>
    <div class="row-between" style="margin-top:14px;gap:12px;flex-wrap:wrap">
      <button class="btn btn-primary" id="settings-save-btn" type="button"><i class="bi bi-check2-circle"></i> Save Settings</button>
      <div style="display:flex;gap:8px;flex:1;min-width:240px;justify-content:flex-end">
        <input type="email" id="test-email-address" class="field" placeholder="your@email.com" aria-label="Test email address" style="flex:1;max-width:280px">
        <button class="btn" id="settings-test-btn" type="button"><i class="bi bi-envelope-paper"></i> Send Test Email</button>
      </div>
    </div>
  </div>
  <?php include __DIR__ . '/includes/account_settings.php'; ?>
</section>

<section class="tab-panel" id="panel-backup" aria-label="Backup and Restore">
  <div class="page-heading">
    <div><h1>Backup &amp; Restore</h1><p>Protect your data and restore a previous database snapshot.</p></div>
    <span class="badge badge-green"><i class="bi bi-shield-lock"></i> Admin only</span>
  </div>
  <div class="notice"><i class="bi bi-info-circle"></i> A complete backup includes products, sales, sale items, stock movements, users, and settings.</div>
  <div class="backup-grid">
    <div class="card backup-card">
      <h2 class="card-title"><i class="bi bi-cloud-download"></i> Backup</h2>
      <div class="backup-option">
        <i class="bi bi-download"></i>
        <div style="flex:1"><strong>Download to Device</strong><div class="muted small">Generate a timestamped .sql file and save it locally.</div></div>
        <button class="btn btn-sm btn-primary" id="backup-download-btn" type="button">Download</button>
      </div>
      <div class="backup-option">
        <i class="bi bi-envelope-paper"></i>
        <div style="flex:1"><strong>Send Backup to Email</strong><div class="muted small">Attach the SQL backup via configured SMTP.</div></div>
        <button class="btn btn-sm" id="backup-email-btn" type="button">Email</button>
      </div>
    </div>
    <div class="card backup-card">
      <h2 class="card-title"><i class="bi bi-cloud-upload"></i> Restore</h2>
      <p class="muted small">Upload a previously generated .sql backup. A safety backup of the current database is created automatically before restoring.</p>
      <label class="field">Backup File<input type="file" id="restore-file" accept=".sql,application/sql"></label>
      <button class="btn btn-danger btn-block" id="restore-btn" type="button" style="margin-top:12px"><i class="bi bi-exclamation-octagon"></i> Restore Backup</button>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
