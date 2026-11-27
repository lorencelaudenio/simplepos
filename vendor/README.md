This directory is optional. Run `composer install` in the project root to install PHPMailer here.

The application automatically uses PHPMailer when `vendor/autoload.php` exists. If it does not exist, backups are emailed with the built-in SMTP client in `includes/SmtpMailer.php`, so Composer is not required for normal installation.
