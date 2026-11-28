<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    header('Location: index');
    exit;
}

$websiteName = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
$error = '';
$notice = '';
if (isset($_GET['installed'])) {
    $notice = 'Installation complete. Please log in with your administrator account.';
}
if (isset($_GET['logged_out'])) {
    $notice = 'You have been logged out.';
}
if (isset($_GET['signedup'])) {
    $notice = 'Account created. Check your email for the activation link.';
}
if (isset($_GET['activated'])) {
    $notice = 'Email confirmed. Your account is active; you can sign in now.';
}
if (isset($_GET['email_changed'])) {
    $notice = 'Your email address has been updated.';
}

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
        $error = 'Sign-in is temporarily unavailable. Please try again later.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND status = "active" LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, (string)$user['password'])) {
                $error = 'Incorrect username or password.';
            } else {
                login_user($user);
                header('Location: index');
                exit;
            }
        } catch (Throwable $ex) {
            error_log('POS login error: ' . $ex->getMessage());
            $error = 'Unable to sign in right now. Please try again.';
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
      'title' => 'Log in - ' . $websiteName,
      'description' => 'Sign in to ' . $websiteName . ' to manage your store.',
      'path' => 'login',
      'site_name' => $websiteName,
  ]); ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body class="login-page">
  <main class="card login-card">
    <a class="brand" href="index" aria-label="<?= e($websiteName) ?> home"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($websiteName) ?></span></a>
    <h1>Welcome back</h1>
    <p>Sign in to manage your store.</p>
    <?php if ($notice !== ''): ?><div class="login-error" style="background:var(--green-soft);color:var(--green-dark)"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$dbReady): ?>
      <div class="notice"><i class="bi bi-info-circle"></i> Sign-in is temporarily unavailable. Please try again later.</div>
    <?php endif; ?>
    <form method="post" action="login">
      <?= csrf_input() ?>
      <label class="field">Username<input type="text" name="username" autocomplete="username" required autofocus></label>
      <label class="field" style="margin-top:14px">Password<input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
    </form>
    <p class="auth-link" style="margin-top:18px;margin-bottom:0">New here? <a href="signup">Create a client account</a><br><span style="display:inline-block;margin-top:8px">Need to activate? <a href="resend">Resend activation email</a></span></p>
  </main>
</body>
</html>
