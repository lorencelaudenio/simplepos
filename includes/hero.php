<?php
declare(strict_types=1);
/** Public landing page shown at the root when no user is logged in. */
$heroStore = get_setting('store_name', 'Simple Online POS');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#15803d">
  <meta name="description" content="Simple Online POS - cashier, inventory, sales history, and secure backups in one lightweight web app.">
  <title><?= e($heroStore) ?> - Point of Sale</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body class="hero-page">
  <header class="hero-nav">
    <div class="brand"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($heroStore) ?></span></div>
    <nav class="hero-nav-links" aria-label="Landing navigation">
      <a href="#features">Features</a>
      <a class="btn btn-sm" href="login"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
      <a class="btn btn-sm btn-primary" href="signup"><i class="bi bi-person-plus"></i> Sign up</a>
    </nav>
  </header>

  <main>
    <section class="hero">
      <div class="hero-inner">
        <div class="hero-copy">
          <span class="hero-eyebrow"><i class="bi bi-lightning-charge-fill"></i> Lightweight. Fast. Built for small stores.</span>
          <h1>Run your counter from one simple dashboard.</h1>
          <p class="hero-lead">Sell faster, track every product, review daily sales, and keep a safe backup of your business — all in one place.</p>
          <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="login"><i class="bi bi-cash-coin"></i> Open Cashier</a>
            <a class="btn btn-lg" href="signup"><i class="bi bi-person-plus"></i> New here? Sign up</a>
          </div>
          <div class="hero-points">
            <span><i class="bi bi-check-circle-fill"></i> No installs for cashiers</span>
            <span><i class="bi bi-check-circle-fill"></i> Barcode scanning</span>
            <span><i class="bi bi-check-circle-fill"></i> Works on mobile</span>
          </div>
        </div>
        <div class="hero-visual" aria-hidden="true">
          <div class="visual-card visual-main">
            <div class="visual-card-head"><span>Quick sale</span><span class="badge badge-green">Ready</span></div>
            <div class="visual-row"><span>Coffee 1kg</span><strong>₱450.00</strong></div>
            <div class="visual-row"><span>Milk 500ml</span><strong>₱65.00</strong></div>
            <div class="visual-row"><span>Barcode scan</span><span class="badge badge-gray"><i class="bi bi-upc-scan"></i> Detected</span></div>
            <div class="visual-total"><span>Total</span><strong>₱515.00</strong></div>
          </div>
          <div class="visual-card visual-float">
            <i class="bi bi-graph-up-arrow"></i>
            <div><span>Daily sales</span><strong>₱12,480.00</strong></div>
          </div>
          <div class="visual-card visual-small">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>3 products low on stock</span>
          </div>
        </div>
      </div>
    </section>

    <section class="hero-features" id="features">
      <div class="hero-features-inner">
        <div class="hero-section-heading">
          <span class="hero-eyebrow">Everything your store needs</span>
          <h2>One tool, four essentials.</h2>
          <p>Designed for sari-sari stores, cafés, and small retail counters.</p>
        </div>
        <div class="feature-grid">
          <article class="feature-card">
            <span class="feature-icon"><i class="bi bi-cash-coin"></i></span>
            <h3>Cashier</h3>
            <p>Search by name or barcode, scan with a camera, collect cash, and print a receipt instantly.</p>
          </article>
          <article class="feature-card">
            <span class="feature-icon"><i class="bi bi-box-seam"></i></span>
            <h3>Inventory</h3>
            <p>Add products, update stock, get low-stock alerts, and import items from Excel.</p>
          </article>
          <article class="feature-card">
            <span class="feature-icon"><i class="bi bi-bar-chart-line"></i></span>
            <h3>Sales History</h3>
            <p>Filter receipts by date, view a daily chart, and export detailed sales reports.</p>
          </article>
          <article class="feature-card">
            <span class="feature-icon"><i class="bi bi-shield-lock"></i></span>
            <h3>Backup &amp; Restore</h3>
            <p>Download or email a SQL backup and restore safely with an automatic safety copy.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="hero-cta">
      <div>
        <h2>Ready to open your store?</h2>
        <p>Already set up? Sign in to your dashboard. New cashier? Ask your admin for an invite code.</p>
      </div>
      <div class="hero-actions">
        <a class="btn btn-lg btn-primary" href="login"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
        <a class="btn btn-lg" href="signup"><i class="bi bi-person-plus"></i> Create account</a>
      </div>
    </section>
  </main>

  <footer class="hero-footer">
    <span><?= e($heroStore) ?></span>
    <span>Simple Online POS &middot; Native PHP + MySQL</span>
  </footer>
</body>
</html>
