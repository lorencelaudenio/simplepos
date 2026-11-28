<section id="overview">
  <h2>1. Overview</h2>
  <p><?= e($websiteName) ?> (the "Service") is a web-based point-of-sale app for small stores. The operator of this website (the "Website Administrator") is responsible for the information stored in the Service. This policy explains what information is collected, why it is used, who can see it, and what choices you have.</p>
  <p>By creating an account or using the dashboard, you agree to the practices described here. If you do not agree, please stop using the Service.</p>
</section>

<section id="information-we-collect">
  <h2>2. Information we collect</h2>
  <p><strong>Account and verification details.</strong> When you sign up, we store your username, full name, email address, a hashed password (your actual password is never stored), your role and account status, and the verification records used to confirm your email address or reset your password.</p>
  <p><strong>Store and business records you enter.</strong> We store your store name, address, optional TIN ID and contact number, receipt footer, product names, barcodes, prices, units, stock levels and stock movements, plus receipts, item lines, sale totals, cash received, change, and transaction dates and times.</p>
  <p><strong>Backup and email activity.</strong> If you ask for a backup to be emailed, we use the verified email address on your account and keep a record of the request and its delivery status. Generated backup files may be stored on the server and, when you download them, on your device.</p>
  <p><strong>Technical information.</strong> The web server may record your IP address, browser type, and request times in access logs. The app uses one essential session cookie (POSSESSION) to keep you signed in, along with short-lived session values for security tokens and the signup captcha. We do not use advertising or analytics cookies.</p>
  <p><strong>Camera and barcode scanning.</strong> If you use the browser camera scanner, barcode decoding happens locally in your browser. Camera video and images are not uploaded; only the decoded barcode text is sent to the server to look up a product. Camera access is controlled by your browser and can be revoked at any time.</p>
  <p class="legal-note">The Service does not ask for customer card details, government IDs, or payment card numbers. It records cash-payment totals for receipts and is not a payment processor.</p>
</section>

<section id="how-we-use-it">
  <h2>3. How we use your information</h2>
  <p>We use the information above to:</p>
  <ul>
    <li>create, verify, authenticate, and administer accounts, including signup confirmation, email changes, and password resets;</li>
    <li>provide the cashier, inventory, sales history, and reporting features you use;</li>
    <li>keep accurate stock and sales records and print receipts with your store details;</li>
    <li>send service messages such as activation links, welcome emails, reset links, and backups you request by email;</li>
    <li>create and restore backups when you ask for them;</li>
    <li>protect the Service, prevent abuse, and enforce our <a href="terms">Terms &amp; Conditions</a>.</li>
  </ul>
  <p>We do not sell your information and we do not use it for advertising.</p>
</section>

<section id="who-can-see-it">
  <h2>4. Who can see your information</h2>
  <ul>
    <li><strong>Website Administrator.</strong> The administrator manages accounts, website settings, and complete database backups, and can therefore see account details and store/business records stored in the database.</li>
    <li><strong>Other users of this website.</strong> The product catalogue and stock levels are shared across client accounts. Sales History shows receipts recorded in the Service to signed-in POS users. A client backup contains only the sales linked to your own account.</li>
    <li><strong>Email provider.</strong> When an email is sent, your address and the message are handled by the SMTP provider configured by the Website Administrator.</li>
    <li><strong>Third-party browser resources.</strong> Icons, charts, and the barcode scanner library are loaded from a public CDN (jsDelivr). Your browser connects to it directly, so the CDN may log technical data such as your IP address.</li>
    <li><strong>Authorities.</strong> Information may be disclosed if required by law or to protect the rights, safety, and security of the Service and its users.</li>
  </ul>
</section>

<section id="cookies-and-third-parties">
  <h2>5. Cookies and third-party services</h2>
  <p>The only cookie set by the Service is the essential session cookie described above. It expires when your session ends. We do not set tracking pixels, advertising cookies, or analytics cookies.</p>
  <p>The Service links to external libraries from jsDelivr and uses your configured email provider for outgoing mail. Those providers have their own privacy practices. Blocking third-party scripts may prevent parts of the dashboard from working.</p>
</section>

<section id="retention">
  <h2>6. How long we keep information</h2>
  <p>Account, product, and sales records are kept until you or the Website Administrator removes them, or until the website is retired. Verification and reset links are short-lived and stop working after they expire or are used. Backup files remain on the server until they are deleted.</p>
  <p>Because this Service is self-hosted by its operator, the Website Administrator controls the exact retention and deletion schedule for this website.</p>
</section>

<section id="security">
  <h2>7. Security</h2>
  <p>We use hashed passwords, prepared database queries, CSRF tokens on write actions, server-side role checks, regenerated session IDs on sign-in, and an HttpOnly, SameSite session cookie. Access to the browser camera scanner requires HTTPS on most devices.</p>
  <p>No online system can guarantee perfect security. Use a strong, unique password, sign out on shared devices, and download your own backups regularly.</p>
</section>

<section id="your-choices">
  <h2>8. Your choices</h2>
  <ul>
    <li>Update your full name, email address, and password under <strong>Account Settings</strong>. Changing your email requires confirming the new address.</li>
    <li>Download a backup to your device, or request that one be emailed to you, from <strong>Settings</strong>.</li>
    <li>Ask the Website Administrator to correct, export, or delete your account data, or to disable your account.</li>
    <li>Refuse or revoke camera permission in your browser; you can still type or scan barcodes with a USB scanner.</li>
  </ul>
  <p>Emails sent by the Service are transactional (account security, verification, and backups you request). We do not send marketing email, so there is no newsletter to unsubscribe from.</p>
</section>

<section id="additional-information">
  <h2>9. Additional information</h2>
  <p>The Service is intended for business use by adults and is not directed at children under 13. If you believe a child has created an account, contact the Website Administrator.</p>
  <p>We may update this policy when the Service or its operation changes. The "Last updated" date at the top of this page shows the current version.</p>
  <?php if ($contactEmail !== ''): ?>
    <p>Questions about this policy or about your data can be sent to <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>.</p>
  <?php else: ?>
    <p>Questions about this policy or about your data should be sent to the Website Administrator using the contact details provided by the operator of this website.</p>
  <?php endif; ?>
</section>
