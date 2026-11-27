<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/activation.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = false;
$values = ['username' => '', 'full_name' => '', 'email' => ''];

$dbReady = true;
try {
    db();
} catch (Throwable $ex) {
    $dbReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (!$dbReady) {
        $error = 'Registration is temporarily unavailable. Please try again later.';
    } elseif (!smtp_configured()) {
        error_log('POS signup blocked: SMTP is not configured.');
        $error = 'Registration is temporarily unavailable. Please try again later.';
    } else {
        $values['username'] = trim((string)($_POST['username'] ?? ''));
        $values['full_name'] = trim((string)($_POST['full_name'] ?? ''));
        $values['email'] = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $activation = activation_register(
                    db(),
                    $values['username'],
                    $values['full_name'],
                    $values['email'],
                    $password
                );
                try {
                    activation_send_email($activation);
                } catch (Throwable $mailError) {
                    activation_cleanup(db(), (int)$activation['user_id'], (string)$activation['token']);
                    error_log('POS activation email error: ' . $mailError->getMessage());
                    throw new RuntimeException('We could not send the activation email. Please try again later.');
                }
                $success = true;
            } catch (ApiException $ex) {
                $error = $ex->getMessage();
            } catch (Throwable $ex) {
                error_log('POS signup error: ' . $ex->getMessage());
                $error = 'Unable to create the account right now. Please try again.';
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
  <title>Create account - POS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=2">
</head>
<body class="login-page">
  <main class="card login-card">
    <div class="brand"><span class="brand-mark"><i class="bi bi-shop"></i></span><span>Simple Online POS</span></div>
    <h1>Create a client account</h1>
    <p>Register below, then activate your account using the link we email to you.</p>
    <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$dbReady || !smtp_configured()): ?>
      <div class="notice"><i class="bi bi-info-circle"></i> Registration is temporarily unavailable. Please try again later.</div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="login-success"><i class="bi bi-envelope-check-fill"></i> Check <strong><?= e($values['email']) ?></strong> for your activation link. The link expires in 24 hours.</div>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0">Email not arriving? <a href="resend.php">Resend activation</a></p>
    <?php else: ?>
      <form method="post" action="signup.php">
        <?= csrf_input() ?>
        <label class="field">Full Name<input type="text" name="full_name" value="<?= e($values['full_name']) ?>" autocomplete="name" maxlength="150" required></label>
        <label class="field" style="margin-top:14px">Username<input type="text" name="username" value="<?= e($values['username']) ?>" autocomplete="username" maxlength="100" required></label>
        <label class="field" style="margin-top:14px">Email Address<input type="email" name="email" value="<?= e($values['email']) ?>" autocomplete="email" maxlength="150" required></label>
        <label class="field" style="margin-top:14px">Password<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
        <label class="field" style="margin-top:14px">Confirm Password<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
        <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-person-plus"></i> Create Account</button>
      </form>
      <p class="auth-link" style="margin-top:18px;margin-bottom:0">Already have an account? <a href="login.php">Sign in</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
