<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/backup_lib.php';

$localConfig = __DIR__ . '/config/database.local.php';
$installed = is_file($localConfig);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($installed) {
        $error = 'This installation is already configured. Delete config/database.local.php to reinstall.';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $host = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbName = trim((string)($_POST['db_name'] ?? 'simple_pos'));
        $dbUser = trim((string)($_POST['db_user'] ?? 'root'));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $adminUser = trim((string)($_POST['admin_username'] ?? ''));
        $adminPass = (string)($_POST['admin_password'] ?? '');
        $adminName = trim((string)($_POST['admin_name'] ?? 'Administrator'));
        $storeName = trim((string)($_POST['store_name'] ?? 'My Store'));

        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            $error = 'Database name may only contain letters, numbers, and underscores.';
        } elseif ($adminUser === '' || strlen($adminUser) > 100) {
            $error = 'Enter an administrator username.';
        } elseif (strlen($adminPass) < 8) {
            $error = 'Administrator password must be at least 8 characters.';
        } elseif ($storeName === '' || strlen($storeName) > 150) {
            $error = 'Enter a store name.';
        } elseif (!is_writable(__DIR__ . '/config')) {
            $error = 'The config directory is not writable. Set it to 775 before installing.';
        } else {
            try {
                $pdo = new PDO(
                    'mysql:host=' . $host . ';charset=utf8mb4',
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec('USE `' . $dbName . '`');
                $schema = file_get_contents(__DIR__ . '/database.sql');
                if ($schema === false) {
                    throw new RuntimeException('database.sql is missing.');
                }
                foreach (pos_sql_parse($schema) as $statement) {
                    $pdo->exec($statement);
                }
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, "admin", "active")'
                );
                $stmt->execute([$adminUser, $hash, $adminName !== '' ? $adminName : 'Administrator']);
                $stmt = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = "store_name"');
                $stmt->execute([$storeName]);

                $contents = "<?php\n"
                    . 'define(\'DB_HOST\', ' . var_export($host, true) . ");\n"
                    . 'define(\'DB_NAME\', ' . var_export($dbName, true) . ");\n"
                    . 'define(\'DB_USER\', ' . var_export($dbUser, true) . ");\n"
                    . 'define(\'DB_PASS\', ' . var_export($dbPass, true) . ");\n";
                if (file_put_contents($localConfig, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write config/database.local.php.');
                }
                header('Location: login.php?installed=1');
                exit;
            } catch (Throwable $ex) {
                error_log('POS install error: ' . $ex->getMessage());
                $error = 'Installation failed: ' . $ex->getMessage();
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
  <title>Install - Simple Online POS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=1">
</head>
<body class="login-page">
  <main class="card login-card" style="width:min(560px,100%)">
    <div class="brand"><span class="brand-mark"><i class="bi bi-gear-wide-connected"></i></span><span>Install POS</span></div>
    <?php if ($installed): ?>
      <h1>Already installed</h1>
      <p>The database configuration file already exists. For security, reinstallation is disabled.</p>
      <a class="btn btn-primary btn-block" href="login.php">Go to Login</a>
    <?php else: ?>
      <h1>Database Setup</h1>
      <p>Create the database tables and your administrator account.</p>
      <?php if ($error !== ''): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="install.php">
        <?= csrf_input() ?>
        <div class="form-grid">
          <label class="field">Database Host<input type="text" name="db_host" value="<?= e(DB_HOST) ?>" required></label>
          <label class="field">Database Name<input type="text" name="db_name" value="<?= e(DB_NAME) ?>" required></label>
          <label class="field">Database User<input type="text" name="db_user" value="<?= e(DB_USER) ?>" required></label>
          <label class="field">Database Password<input type="password" name="db_pass" autocomplete="new-password"></label>
          <label class="field">Store Name<input type="text" name="store_name" value="My Store" required></label>
          <label class="field">Admin Full Name<input type="text" name="admin_name" value="Administrator"></label>
          <label class="field">Admin Username<input type="text" name="admin_username" required autocomplete="username"></label>
          <label class="field">Admin Password <span class="hint">(min 8 chars)</span><input type="password" name="admin_password" required autocomplete="new-password"></label>
        </div>
        <button class="btn btn-primary btn-block" type="submit"><i class="bi bi-check2-circle"></i> Install System</button>
      </form>
      <p class="muted small" style="margin-top:14px">After installation, restrict access to install.php or delete it from the server.</p>
    <?php endif; ?>
  </main>
</body>
</html>
