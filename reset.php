<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/activation.php';
require_once __DIR__ . '/includes/password_reset.php';

if (current_user()) {
    header('Location: index');
    exit;
}

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
$error = '';
$success = false;
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$dbReady = true;
$reset = null;
try {
    db();
    $reset = password_reset_find(db(), $token);
} catch (Throwable $ex) {
    $dbReady = false;
    $error = 'Password reset is temporarily unavailable. Please try again later.';
}

if ($reset === null && $error === '') {
    $error = 'This password reset link is invalid or has expired. Request a new one from the forgot password page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif ($reset === null) {
        $error = 'This password reset link is invalid or has expired. Request a new one from the forgot password page.';
    } elseif (!$dbReady) {
        $error = 'Password reset is temporarily unavailable. Please try again later.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                password_reset_complete(db(), $token, $password);
                $success = true;
            } catch (ApiException $ex) {
                $error = $ex->getMessage();
            } catch (Throwable $ex) {
                error_log('POS password reset error: ' . $ex->getMessage());
                $error = 'Unable to reset the password right now. Please try again.';
            }
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
      'title' => 'Reset password - ' . $websiteName,
      'description' => 'Choose a new password for your ' . $websiteName . ' account.',
      'path' => 'reset',
      'site_name' => $websiteName,
  ]); ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body class="login-page">
  <main class="card login-card">
    <a class="brand" href="index" aria-label="<?= e($websiteName) ?> home"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($websiteName) ?></span></a>
    <h1>Choose a new password</h1>
    <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="login-success"><i class="bi bi-check-circle-fill"></i> Your password has been updated. You can sign in now.</div>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0"><a href="login">Go to sign in</a></p>
    <?php elseif ($reset !== null): ?>
      <p>Enter a new password for your account. Use at least 8 characters.</p>
      <form method="post" action="reset">
        <?= csrf_input() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="field">New Password<input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus></label>
        <label class="field" style="margin-top:14px">Confirm Password<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
        <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-key"></i> Update Password</button>
      </form>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0">Back to <a href="login">sign in</a></p>
    <?php else: ?>
      <p class="auth-link" style="margin-bottom:0"><a href="forgot">Request a new reset link</a> · <a href="login">Sign in</a></p>
    <?php endif; ?>
    <p class="auth-link" style="margin-top:12px;margin-bottom:0;font-size:12px"><?php include __DIR__ . '/includes/legal_links.php'; ?></p>
  </main>
</body>
</html>
