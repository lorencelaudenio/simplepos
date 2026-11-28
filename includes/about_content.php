<section id="what-it-is">
  <h2>1. What <?= e($websiteName) ?> is</h2>
  <p><?= e($websiteName) ?> is a web-based point-of-sale app that brings the cashier counter, product inventory, sales history, and backups together in one dashboard. Sign in from a computer, tablet, or phone — cashiers do not need to install any software.</p>
  <p>The goal is simple: give small stores a fast, practical way to sell products, track stock, and review daily sales without a complicated setup.</p>
</section>

<section id="who-its-for">
  <h2>2. Who it is for</h2>
  <p>The app is built for sari-sari stores, cafés, and small retail counters. One Website Administrator manages the website, accounts, settings, and full database backups. Client accounts use the POS side of the app to run their own store.</p>
  <p>If you already have an account, sign in. If you are new here, ask the site owner for an invitation or create a client account and confirm your email.</p>
</section>

<section id="features">
  <h2>3. What you can do</h2>
  <ul>
    <li><strong>Cashier:</strong> find products by name or barcode, scan with a USB or browser camera scanner, build a cart, take cash, and print a receipt.</li>
    <li><strong>Inventory:</strong> add and edit products, track stock levels, get low-stock alerts, and import or export items with Excel.</li>
    <li><strong>Sales History:</strong> filter receipts by date, see a daily sales chart, review totals, and export reports.</li>
    <li><strong>Settings:</strong> set your store name, address, optional TIN ID and contact number, receipt footer, and account details.</li>
    <li><strong>Backups:</strong> save your own sales to your device or email, or ask an administrator for a complete database backup and restore it when needed.</li>
  </ul>
</section>

<section id="accounts">
  <h2>4. Accounts and roles</h2>
  <p>New client accounts are verified by email before they become active. Changing an email address also requires confirmation, and password resets use a short-lived link sent to the address on file.</p>
  <p>The Website Administrator can create and manage accounts and configure website-wide settings such as the site name and outgoing email. Clients see only their POS tools and their own store settings.</p>
</section>

<section id="data-and-privacy">
  <h2>5. Your store data</h2>
  <p>Store details you enter are private to your account and appear on your receipts. The product catalogue and stock levels are shared across client accounts on this website. Sales records are stored with the user who created them and appear in Sales History.</p>
  <p>We do not sell your information or use it for advertising. For the full explanation of what is collected and who can see it, read the <a href="privacy">Privacy Policy</a>.</p>
</section>

<section id="security-and-backups">
  <h2>6. Security and backups</h2>
  <p>Accounts use hashed passwords, protected sessions, CSRF checks on write actions, and server-side role checks. Browser camera scanning runs locally in your browser and requires your permission.</p>
  <p>Backups are useful but not a substitute for your own copies. Download your sales backup regularly and keep it somewhere safe. The rules for using the Service, including backups and availability, are in the <a href="terms">Terms &amp; Conditions</a>.</p>
</section>

<section id="get-started">
  <h2>7. Get started</h2>
  <p>Ready to open your store? Create an account or sign in to your dashboard.</p>
  <p style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn btn-sm btn-primary" href="signup"><i class="bi bi-person-plus"></i> Create account</a>
    <a class="btn btn-sm" href="login"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
  </p>
  <?php if ($contactEmail !== ''): ?>
    <p>Questions about this website? Contact the operator at <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>.</p>
  <?php endif; ?>
</section>
