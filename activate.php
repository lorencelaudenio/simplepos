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

$token = trim((string)($_GET['token'] ?? ''));
$activated = false;
$emailChanged = false;
$error = '';

if ($token === '') {
    $error = 'No activation link was provided.';
} else {
    try {
        $result = activation_activate(db(), $token);
        $activated = true;
        $emailChanged = ($result['purpose'] ?? '') === 'email_change';
    } catch (ApiException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('POS activation error: ' . $ex->getMessage());
        $error = 'Unable to activate the account right now. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Activate account - <?= e($websiteName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body class="login-page">
  <main class="card login-card">
    <div class="brand"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($websiteName) ?></span></div>
    <h1>Account activation</h1>
    <p>Confirm your email to finish setting up your client account.</p>
    <?php if ($activated): ?>
      <?php if ($emailChanged): ?>
        <div class="login-success"><i class="bi bi-check-circle-fill"></i> Your email address has been updated.</div>
        <a class="btn btn-primary btn-block" href="login?email_changed=1"><i class="bi bi-box-arrow-in-right"></i> Go to Sign In</a>
      <?php else: ?>
        <div class="login-success"><i class="bi bi-check-circle-fill"></i> Email confirmed. An administrator will enable your account before you can sign in.</div>
        <a class="btn btn-primary btn-block" href="login?activated=1"><i class="bi bi-box-arrow-in-right"></i> Go to Sign In</a>
      <?php endif; ?>
    <?php else: ?>
      <div class="login-error"><?= e($error) ?></div>
      <a class="btn btn-block" href="resend"><i class="bi bi-envelope-arrow-up"></i> Request a new activation link</a>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0">Back to <a href="login">sign in</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
