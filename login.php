<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$notice = '';
if (isset($_GET['installed'])) {
    $notice = 'Installation complete. Please log in with your administrator account.';
}
if (isset($_GET['logged_out'])) {
    $notice = 'You have been logged out.';
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
        $error = 'The database is not configured. Please run install.php first.';
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
                header('Location: index.php');
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
  <title>Log in - POS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=1">
</head>
<body class="login-page">
  <main class="card login-card">
    <div class="brand"><span class="brand-mark"><i class="bi bi-shop"></i></span><span>Simple Online POS</span></div>
    <h1>Welcome back</h1>
    <p>Sign in to manage your store.</p>
    <?php if ($notice !== ''): ?><div class="login-error" style="background:var(--green-soft);color:var(--green-dark)"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$dbReady): ?>
      <div class="notice"><i class="bi bi-info-circle"></i> Database not configured. <a href="install.php">Run the installer</a>.</div>
    <?php endif; ?>
    <form method="post" action="login.php">
      <?= csrf_input() ?>
      <label class="field">Username<input type="text" name="username" autocomplete="username" required autofocus></label>
      <label class="field" style="margin-top:14px">Password<input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
    </form>
  </main>
</body>
</html>
