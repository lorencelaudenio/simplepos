# Simple Online POS & Inventory Management System

A lightweight, responsive point-of-sale system built with native PHP 8.1+, MySQL, HTML/CSS/JavaScript, Fetch API, and Chart.js. No framework, no Node.js build step.

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, `zip`, `simplexml`, and `fileinfo` enabled
- MySQL 5.7+ or MariaDB 10.4+
- Apache (`.htaccess` files are included) or Nginx with equivalent deny rules
- Internet access for CDN assets: Bootstrap Icons, Chart.js, and html5-qrcode (browser camera barcode scanner)
- HTTPS (or localhost) for browser camera barcode scanning on desktop or mobile. Browsers block the camera on plain HTTP, so iPhone will deny access even if Safari settings allow it.
- On iPhone, camera access must also be allowed in **Safari → Settings → Website Settings → Camera** for this site

## Installation

1. Upload all files to your web root (or a `/pos` subfolder).
2. Make sure `config/` and `uploads/` are writable by the web server.
3. Open `https://your-domain/install.php` in a browser.
4. Enter database credentials and create the administrator account.
5. Log in at `login.php`, then open **Backup & Restore → Store & SMTP Settings** to set the store name, receipt footer, and SMTP details.
6. For security, delete `install.php` after installation (the installer disables itself once `config/database.local.php` exists).

If you need to change database credentials later, edit `config/database.local.php` or set `POS_DB_HOST`, `POS_DB_NAME`, `POS_DB_USER`, and `POS_DB_PASS` environment variables.

Set the correct timezone in `php.ini` (for example `date.timezone = Asia/Manila`) so receipts and reports use local time.

## Features

- **Public hero page:** the root URL (`index.php`) shows a responsive landing page with Sign in / Sign up links when you are logged out. After login, the same URL becomes the role-aware dashboard — no separate dashboard pages.
- **Open signup with email activation:** clients register themselves, then activate a 24-hour link sent through SMTP; accounts stay inactive until confirmed.
- **Multi-user, one app:** Website Administrators see an Admin Dashboard (overview, users, website settings, backup/restore). POS users are labeled “Client” and see Cashier, Inventory, and Sales History. API endpoints enforce these roles server-side.
- **Cashier:** live name/barcode search, USB barcode scanner support (keyboard input + Enter), desktop/mobile browser camera scanner with camera selection (phones open the back camera by default), cart with stock limits, server-validated payments, automatic stock deduction, duplicate checkout prevention, 58mm/80mm printable receipts.
- **Inventory:** product cards, add/edit/archive/restore, add-stock modal with live preview, stock movement history, low-stock warnings and alert banner, Excel import with preview/validation/duplicate detection, Excel export, downloadable Excel template.
- **Sales History:** Today/Yesterday/Week/Month/Year/Custom/All filters, total sales, receipt count, average transaction, Chart.js daily sales chart, transaction list, reprintable receipts, item-level Excel export respecting filters.
- **Backup & Restore:** timestamped SQL download, SMTP email backup (PHPMailer when installed, built-in SMTP client otherwise), validated `.sql` upload, confirmation dialog, automatic safety backup before restore, admin-only access.
- **Security:** PHP sessions, `password_hash()`/`password_verify()`, PDO prepared statements, CSRF tokens on all writes, server-side validation, escaped output, file upload checks, role enforcement, and web access denied to `config/`, `includes/`, `uploads/`, and `vendor/`.

## Client signup and email activation

Registration is open, but new accounts cannot use the POS until their email is activated:

1. Configure SMTP under **Website Settings** (or using `smtp_host`, `smtp_port`, `smtp_secure`, `smtp_user`, `smtp_pass`, `smtp_from`).
2. A cashier opens `signup.php` from the login/hero page and submits name, username, email, and password.
3. The account is created as an inactive **Client** and a 24-hour activation link is emailed to them.
4. The cashier opens the link in `activate.php`; the account becomes active and they can sign in.
5. If the email is lost, they can request a fresh link from `resend.php` (one request per minute).

Public signups always create Client accounts, never Website Administrators. If the activation email cannot be sent, the pending account is removed automatically. Admins can still enable/disable or delete accounts from **Users**.

## Optional: PHPMailer

Composer is not required. The built-in SMTP client handles TLS, SSL, LOGIN authentication, and attachments. To use PHPMailer instead, run `composer install` in the project root; `vendor/autoload.php` will then be loaded automatically.

## Backup notes

Backups contain `users`, `products`, `sales`, `sale_items`, `stock_movements`, and `settings`. Restore accepts only SQL files generated in the Simple POS format and rejects unsupported statements. Because MySQL DDL cannot be rolled back, an automatic `auto_before_restore_*.sql` safety backup is written to `uploads/backups/` before any restore.

## Before going live - verification checklist

1. Configure SMTP, open `signup.php`, register a Client, and confirm the activation email arrives.
2. Click the activation link, sign in as the new Client, and confirm only POS tabs are visible.
3. Add, edit, archive, restore, and delete products (with and without sales history).
4. Add stock and confirm the movement history and low-stock alert update.
5. Search by name and barcode; test a USB scanner and the browser camera scanner on desktop and mobile.
6. Check out with exact cash and with overpayment; verify change, receipt, and stock deduction.
7. Attempt overselling and underpayment; confirm both are rejected by the server.
8. Test low-stock filtering, Excel template/import preview/import/export, and duplicate barcode detection.
9. Check sales filters, chart, receipt reprinting, and filtered Excel export.
10. Generate a backup, restore it, and verify the safety backup exists.
11. Check the layout on desktop, tablet, and phone widths.
