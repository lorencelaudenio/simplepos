<?php
declare(strict_types=1);

/** Session authentication helpers. */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('POSSESSION');
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $id = (int)$_SESSION['user_id'];

    // Refresh from the database so role/status changes take effect immediately.
    if (function_exists('db')) {
        try {
            $stmt = db()->prepare(
                'SELECT id, username, full_name, role FROM users WHERE id = ? AND status = "active" LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                logout_user();
                return null;
            }
            $_SESSION['user'] = [
                'id' => (int)$row['id'],
                'username' => (string)$row['username'],
                'full_name' => (string)$row['full_name'],
                'role' => (string)$row['role'],
            ];
            return $_SESSION['user'];
        } catch (Throwable $ex) {
            // Fail closed: never authorize from a cached role if the DB cannot confirm it.
            logout_user();
            return null;
        }
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user']) && (int)($_SESSION['user']['id'] ?? 0) === $id) {
        return $_SESSION['user'];
    }
    return null;
}

function require_login(string $redirectTo = 'login.php'): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . $redirectTo);
        exit;
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['login_time'] = time();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && ($user['role'] ?? '') === 'admin';
}
