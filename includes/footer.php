<?php
declare(strict_types=1);
$footerUser = current_user();
$footerRole = $footerUser ? (string)$footerUser['role'] : '';
$footerStoreName = $footerUser
    ? (client_store_settings(db(), (int)$footerUser['id'])['store_name'] ?: 'My Store')
    : 'My Store';
?>
</main>

<footer style="border-top:1px solid var(--line);padding:14px 22px;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;background:var(--card);color:var(--muted);font-size:12px">
  <span><?= e(get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS') ?></span>
  <span aria-hidden="true">·</span>
  <?php include __DIR__ . '/legal_links.php'; ?>
</footer>

<div id="receipt-print" class="receipt-print"></div>

<div class="modal-backdrop hidden" id="modal-backdrop"></div>
<div class="modal hidden" id="app-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal-header">
    <h3 id="modal-title"></h3>
    <button class="icon-btn" id="modal-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="modal-body" id="modal-body"></div>
  <div class="modal-footer" id="modal-footer"></div>
</div>

<div class="confirm-backdrop hidden" id="confirm-backdrop">
  <div class="confirm-box" role="alertdialog" aria-modal="true">
    <h3 id="confirm-title">Are you sure?</h3>
    <p id="confirm-message"></p>
    <div class="confirm-actions">
      <button class="btn" id="confirm-cancel" type="button">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok" type="button">Confirm</button>
    </div>
  </div>
</div>

<div class="loading-overlay hidden" id="loading-overlay"><div class="spinner" aria-label="Loading"></div></div>
<div class="toast-root" id="toast-root" aria-live="polite"></div>

<script>
  window.POS_CONFIG = {
    csrfToken: <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    isAdmin: <?= is_admin() ? 'true' : 'false' ?>,
    storeName: <?= json_encode($footerStoreName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    userRole: <?= json_encode($footerRole) ?>
  };
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="assets/js/app.js?v=22"></script>
<script src="assets/js/cashier.js?v=22"></script>
<script src="assets/js/inventory.js?v=22"></script>
<script src="assets/js/sales.js?v=22"></script>
<script src="assets/js/admin.js?v=22"></script>
<script src="assets/js/backup.js?v=22"></script>
</body>
</html>
