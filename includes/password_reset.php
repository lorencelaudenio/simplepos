<?php
declare(strict_types=1);

/** Public password-reset tokens. Tokens live in the settings table. */

function password_reset_key(string $token): string
{
    return 'password_reset_' . $token;
}

function password_reset_delete_for_user(PDO $pdo, int $userId): void
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['setting_key'];
        if (!str_starts_with($key, 'password_reset_')) {
            continue;
        }
        $data = json_decode((string)$row['setting_value'], true);
        if (is_array($data) && (int)($data['user_id'] ?? 0) === $userId) {
            $delete->execute([$key]);
        }
    }
}

/** @return array<string,mixed>|null */
function password_reset_find_for_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['setting_key'];
        if (!str_starts_with($key, 'password_reset_')) {
            continue;
        }
        $data = json_decode((string)$row['setting_value'], true);
        if (is_array($data) && (int)($data['user_id'] ?? 0) === $userId) {
            return $data;
        }
    }
    return null;
}

/**
 * @return array<string,mixed>
 */
function password_reset_create(
    PDO $pdo,
    int $userId,
    string $email,
    string $fullName,
    string $baseUrl
): array {
    password_reset_delete_for_user($pdo, $userId);

    $token = bin2hex(random_bytes(24));
    $reset = [
        'token' => $token,
        'user_id' => $userId,
        'full_name' => $fullName,
        'email' => $email,
        'base_url' => $baseUrl,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'last_sent_at' => date('Y-m-d H:i:s'),
    ];
    $json = json_encode($reset, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    );
    $stmt->execute([password_reset_key($token), $json, $json]);
    return $reset;
}

/** @return array<string,mixed>|null */
function password_reset_find(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $key = password_reset_key($token);
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return null;
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return null;
    }
    if (strtotime((string)($data['expires_at'] ?? '')) < time()) {
        $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
        $delete->execute([$key]);
        return null;
    }
    return $data;
}

function password_reset_url(string $token, string $baseUrl = ''): string
{
    $configured = trim((string)(getenv('POS_APP_URL') ?: ''));
    if ($configured === '') {
        $configured = trim($baseUrl);
    }
    if ($configured !== '') {
        return rtrim($configured, '/') . '/reset?token=' . rawurlencode($token);
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index'));
    $base = dirname($script);
    if (basename($base) === 'api') {
        $base = dirname($base);
    }
    $base = rtrim($base === '.' ? '' : $base, '/');
    return activation_base_url() . $base . '/reset?token=' . rawurlencode($token);
}

/** @param array<string,mixed> $reset */
function password_reset_send_email(array $reset): void
{
    $website = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
    $email = (string)($reset['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $name = trim((string)($reset['full_name'] ?? ''));
    $link = password_reset_url((string)$reset['token'], (string)($reset['base_url'] ?? ''));
    $body = "Hello " . ($name !== '' ? $name : 'there') . ",\n\n"
        . "We received a request to reset your " . $website . " password. Open this link within 1 hour:\n\n"
        . $link . "\n\n"
        . "If you did not request this, you can ignore this email. Your password will not change.\n\n"
        . "— " . $website;
    send_pos_email($email, 'Reset your ' . $website . ' password', $body);
}

/**
 * Always exits quietly so the page cannot reveal whether an email is registered.
 */
function password_reset_request(PDO $pdo, string $email, string $baseUrl): void
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $record = activation_find_email($pdo, $email);
    if (!$record || empty($record['verified'])) {
        return;
    }
    $userId = (int)($record['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id, full_name, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || ($user['status'] ?? '') !== 'active') {
        return;
    }

    $existing = password_reset_find_for_user($pdo, $userId);
    if ($existing !== null) {
        $sentAt = strtotime((string)($existing['last_sent_at'] ?? ''));
        if ($sentAt !== false && time() - $sentAt < 60) {
            return;
        }
    }

    $reset = password_reset_create($pdo, $userId, $email, (string)($user['full_name'] ?? ''), $baseUrl);
    try {
        password_reset_send_email($reset);
    } catch (Throwable $ex) {
        password_reset_delete_for_user($pdo, $userId);
        error_log('POS password reset email error [user ' . $userId . ']: ' . $ex->getMessage());
    }
}

function password_reset_complete(PDO $pdo, string $token, string $password): void
{
    $reset = password_reset_find($pdo, $token);
    if ($reset === null) {
        fail('This password reset link is invalid or has expired.', 400);
    }
    if (strlen($password) < 8) {
        fail('Password must be at least 8 characters.', 400);
    }

    $userId = (int)($reset['user_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || ($user['status'] ?? '') !== 'active') {
        fail('This account is no longer available. Request a new reset link.', 400);
    }

    $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $update->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    password_reset_delete_for_user($pdo, $userId);
}
