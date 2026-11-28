<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/activation.php';

if (current_user()) {
    header('Location: index');
    exit;
}

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
$error = '';
$success = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (!smtp_configured()) {
        error_log('POS activation resend blocked: SMTP is not configured.');
        $error = 'We could not send the email. Please try again later.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        try {
            $activation = activation_resend(db(), $email);
            try {
                activation_send_email($activation);
            } catch (Throwable $mailError) {
                error_log('POS activation resend email error: ' . $mailError->getMessage());
                throw new RuntimeException('We could not send the email. Please try again later.');
            }
            $success = true;
        } catch (ApiException $ex) {
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('POS resend activation error: ' . $ex->getMessage());
            $error = 'Unable to resend the activation email right now. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="same-origin">
  <?php seo_tags([
      'title' => 'Resend activation - ' . $websiteName,
      'description' => 'Request a new account activation email for ' . $websiteName . '.',
      'path' => 'resend',
      'site_name' => $websiteName,
  ]); ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body class="login-page">
  <main class="card login-card">
    <div class="brand"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($websiteName) ?></span></div>
    <h1>Resend activation email</h1>
    <p>Enter the email address you used to register. A fresh 24-hour link will be sent.</p>
    <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="login-success"><i class="bi bi-envelope-check-fill"></i> A new activation link was sent to <strong><?= e($email) ?></strong>.</div>
    <?php endif; ?>
    <form method="post" action="resend">
      <?= csrf_input() ?>
      <label class="field">Email Address<input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" maxlength="150" required></label>
      <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-envelope-arrow-up"></i> Send New Link</button>
    </form>
    <p class="auth-link" style="margin-top:18px;margin-bottom:0">Back to <a href="login">sign in</a></p>
    <p class="auth-link" style="margin-top:12px;margin-bottom:0;font-size:12px"><?php include __DIR__ . '/includes/legal_links.php'; ?></p>
  </main>
</body>
</html>
