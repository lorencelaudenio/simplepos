<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/activation.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/captcha.php';

if (current_user()) {
    header('Location: index');
    exit;
}

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
$error = '';
$success = false;
$email = '';

$dbReady = true;
try {
    db();
} catch (Throwable $ex) {
    $dbReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (!verify_captcha($_POST['captcha'] ?? null)) {
        $error = 'Incorrect security check. Please try again.';
    } elseif (!$dbReady || !smtp_configured()) {
        error_log('POS password reset blocked: database or SMTP unavailable.');
        $error = 'Password reset is temporarily unavailable. Please try again later.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        password_reset_request(db(), $email, activation_base_url());
        $success = true;
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
      'title' => 'Forgot password - ' . $websiteName,
      'description' => 'Request a password reset link for your ' . $websiteName . ' account.',
      'path' => 'forgot',
      'site_name' => $websiteName,
  ]); ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body class="login-page">
  <main class="card login-card">
    <a class="brand" href="index" aria-label="<?= e($websiteName) ?> home"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($websiteName) ?></span></a>
    <h1>Forgot your password?</h1>
    <p>Enter the email address registered on your account. We will send a reset link that expires in 1 hour.</p>
    <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="login-success"><i class="bi bi-envelope-check-fill"></i> If that email address is registered and confirmed, a password reset link has been sent.</div>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0">Back to <a href="login">sign in</a></p>
    <?php else: ?>
      <?php if (!$dbReady || !smtp_configured()): ?>
        <div class="notice"><i class="bi bi-info-circle"></i> Password reset is temporarily unavailable. Please try again later.</div>
      <?php endif; ?>
      <form method="post" action="forgot">
        <?= csrf_input() ?>
        <label class="field">Email Address<input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" maxlength="150" required></label>
        <?= captcha_input() ?>
        <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-envelope-arrow-up"></i> Send Reset Link</button>
      </form>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0">Remembered it? <a href="login">Sign in</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
