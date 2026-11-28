<section id="acceptance">
  <h2>1. Acceptance of these terms</h2>
  <p>These Terms &amp; Conditions ("Terms") govern your use of <?= e($websiteName) ?> (the "Service"). By creating an account, signing in, or using any part of the dashboard, you agree to follow these Terms. If you do not agree, do not use the Service.</p>
  <p>The Website Administrator may set additional rules for this website; those rules are part of these Terms while they are posted.</p>
</section>

<section id="accounts">
  <h2>2. Accounts and eligibility</h2>
  <ul>
    <li>You must be at least 18 years old and able to enter a binding agreement to use the Service.</li>
    <li>Provide accurate information when you register and keep your name, email address, and password up to date.</li>
    <li>Your username and password are for your use only. Tell the Website Administrator immediately if you suspect unauthorized access.</li>
    <li>New client accounts are created as inactive until the verification link sent to your email address is opened. Verification links expire after 24 hours; password-reset links expire after one hour.</li>
    <li>The Website Administrator can activate, disable, or remove accounts and controls which accounts have administrator access.</li>
  </ul>
</section>

<section id="acceptable-use">
  <h2>3. Acceptable use</h2>
  <p>You agree to use the Service only for lawful business purposes. You must not:</p>
  <ul>
    <li>attempt to gain unauthorized access to accounts, APIs, databases, backups, or server files;</li>
    <li>probe, scan, scrape, overload, or disrupt the Service or its infrastructure;</li>
    <li>upload malicious files or try to inject scripts, SQL, or other harmful content;</li>
    <li>record untrue prices, stock counts, or sales, or tamper with receipts or reports;</li>
    <li>use the Service to store unlawful content or to violate someone else's rights;</li>
    <li>share your login, impersonate another user, or help someone else do any of the above.</li>
  </ul>
  <p>We may investigate suspected misuse and take action, including limiting or ending access, without prior notice where appropriate.</p>
</section>

<section id="roles-and-data">
  <h2>4. Roles and your data</h2>
  <p>The Service has two roles. A <strong>Website Administrator</strong> manages accounts, website settings, and database backups. A <strong>Client</strong> uses the cashier, inventory, sales history, and personal settings areas.</p>
  <p>You remain responsible for the products, prices, stock counts, store details, and sales records you enter. Check them for accuracy; the Service does not verify them for you.</p>
  <p>The product catalogue and stock levels are shared across client accounts on this website. Sales records are stored with the user who created them and appear in Sales History. Your store name, address, TIN ID, contact number, and receipt footer are private settings used on your receipts.</p>
  <p>Complete database backups made by the Website Administrator include account, product, sales, stock movement, and setting records. Client backups you create from Settings contain only your own sales and sale items.</p>
</section>

<section id="backups">
  <h2>5. Backups and availability</h2>
  <p>The Service offers backups and restore tools, but it does not guarantee that data will never be lost. A restore replaces current data with the contents of the backup file; a safety copy is created before a database restore where supported.</p>
  <p>Download and store your own copies regularly. Keep backup files safe because they contain business records and may contain account information.</p>
  <p>We may perform maintenance or experience downtime. We are not liable for losses caused by events outside our reasonable control, such as hosting failures, internet outages, or hardware faults.</p>
</section>

<section id="emails">
  <h2>6. Email messages</h2>
  <p>The Service sends transactional email only: activation and welcome messages, email-change and password-reset links, and backups you ask to have emailed. These messages are required for account security and features you request; they are not marketing.</p>
  <p>Delivery depends on the SMTP provider configured by the Website Administrator. Check your spam folder if an expected message does not arrive.</p>
</section>

<section id="third-party-services">
  <h2>7. Third-party services and device permissions</h2>
  <p>Icons, charts, and the barcode scanner library are loaded from a public CDN. Your browser connects to that provider directly, and blocking it may break parts of the interface.</p>
  <p>The camera scanner runs in your browser. It needs your permission and generally requires HTTPS. You control camera access through your browser settings and can use a USB barcode scanner or manual entry instead.</p>
</section>

<section id="intellectual-property">
  <h2>8. Intellectual property</h2>
  <p>The Service's software, design, and documentation belong to their respective owners. You may use the Service for your business, but you may not copy, modify, resell, or rehost its code without permission.</p>
  <p>You keep ownership of the business information you enter. You give the Service permission to store and process that information only to provide and support the features you use.</p>
</section>

<section id="disclaimers">
  <h2>9. Disclaimers and limitation of liability</h2>
  <p>The Service is provided "as is" and "as available". To the maximum extent permitted by law, the operators and contributors do not warrant that it will be uninterrupted, error-free, or fit for a particular purpose, and are not liable for indirect or consequential losses, lost profits, or loss of data arising from its use.</p>
  <p>Nothing in these Terms excludes liability that cannot lawfully be excluded.</p>
</section>

<section id="termination">
  <h2>10. Suspension and termination</h2>
  <p>You may stop using the Service at any time and can ask the Website Administrator to close your account. The administrator may suspend or remove an account for misuse, security reasons, or as required by law.</p>
  <p>On closure, access to the account ends. Data may remain in backups until those backups are deleted according to the operator's retention practices. See the <a href="privacy">Privacy Policy</a> for more on data retention and your choices.</p>
</section>

<section id="changes">
  <h2>11. Changes to these Terms</h2>
  <p>These Terms may be updated when the Service changes or to meet legal requirements. The "Last updated" date at the top of this page shows the current version. Continuing to use the Service after an update means you accept the revised Terms.</p>
  <?php if ($contactEmail !== ''): ?>
    <p>If you have questions about these Terms, contact the Website Administrator at <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>.</p>
  <?php else: ?>
    <p>If you have questions about these Terms, contact the Website Administrator using the contact details provided by the operator of this website.</p>
  <?php endif; ?>
</section>
