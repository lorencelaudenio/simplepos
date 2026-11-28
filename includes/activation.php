<?php
declare(strict_types=1);

/** Email activation helpers for public client signups. Tokens live in the settings table. */

function activation_token_prefix(): string
{
    return 'signup_activation_';
}

function activation_email_prefix(): string
{
    return 'signup_email_';
}

function activation_email_key(int $userId): string
{
    return activation_email_prefix() . $userId;
}

function activation_token_key(string $token): string
{
    return activation_token_prefix() . $token;
}

/** Return the email currently saved for a user, or an empty string. */
function activation_get_email(PDO $pdo, int $userId, bool $verifiedOnly = false): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([activation_email_key($userId)]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return '';
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || ($verifiedOnly && empty($data['verified']))) {
        return '';
    }
    return (string)($data['email'] ?? '');
}

/**
 * @return array<string,mixed>|null
 *
 * Unverified email-change claims are ignored by default so one account cannot
 * reserve another person's address just by entering it in its own settings.
 */
function activation_find_email(PDO $pdo, string $email, bool $ignoreUnverifiedChanges = true): ?array
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['setting_key'];
        if (!str_starts_with($key, activation_email_prefix())) {
            continue;
        }
        $data = json_decode((string)$row['setting_value'], true);
        if (!is_array($data) || strcasecmp((string)($data['email'] ?? ''), $email) !== 0) {
            continue;
        }
        if ($ignoreUnverifiedChanges
            && ($data['purpose'] ?? '') === 'email_change'
            && empty($data['verified'])
        ) {
            continue;
        }
        $data['user_id'] = (int)substr($key, strlen(activation_email_prefix()));
        return $data;
    }
    return null;
}

function activation_email_exists(PDO $pdo, string $email, bool $ignoreUnverifiedChanges = true): bool
{
    return activation_find_email($pdo, $email, $ignoreUnverifiedChanges) !== null;
}

/** True when another account already owns or is pending verification for this address. */
function activation_email_conflict(PDO $pdo, string $email, int $userId): bool
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['setting_key'];
        if (!str_starts_with($key, activation_email_prefix())) {
            continue;
        }
        $ownerId = (int)substr($key, strlen(activation_email_prefix()));
        if ($ownerId === $userId) {
            continue;
        }
        $data = json_decode((string)$row['setting_value'], true);
        if (!is_array($data) || strcasecmp((string)($data['email'] ?? ''), $email) !== 0) {
            continue;
        }
        // Another user's unconfirmed email-change claim is not proof of ownership.
        if (!empty($data['verified']) || ($data['purpose'] ?? '') !== 'email_change') {
            return true;
        }
    }
    return false;
}

function activation_delete_for_user(PDO $pdo, int $userId): void
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['setting_key'];
        if (!str_starts_with($key, activation_token_prefix())) {
            continue;
        }
        $data = json_decode((string)$row['setting_value'], true);
        if (is_array($data) && (int)($data['user_id'] ?? 0) === $userId) {
            $delete->execute([$key]);
        }
    }
}

/** @return array<string,mixed> */
function activation_create_token(
    PDO $pdo,
    int $userId,
    string $email,
    string $fullName,
    string $purpose = 'signup'
): array {
    activation_delete_for_user($pdo, $userId);

    $token = bin2hex(random_bytes(24));
    $activation = [
        'token' => $token,
        'user_id' => $userId,
        'username' => '',
        'full_name' => $fullName,
        'email' => $email,
        'purpose' => $purpose === 'email_change' ? 'email_change' : 'signup',
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 24 * 3600),
        'last_sent_at' => date('Y-m-d H:i:s'),
    ];
    $json = json_encode($activation, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    );
    $stmt->execute([activation_token_key($token), $json, $json]);
    return $activation;
}

/** @return array<string,mixed>|null */
function activation_find_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([activation_token_key($token)]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return null;
    }
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

function activation_base_url(): string
{
    $configured = trim((string)(getenv('POS_APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    // Never trust an arbitrary Host header. Use the server-configured name,
    // or an explicit allowlist when the app sits behind a proxy.
    $serverName = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = $serverName;
    $allowed = array_filter(array_map('trim', explode(',', (string)(getenv('POS_ALLOWED_HOSTS') ?: ''))));
    if ($allowed !== []) {
        $sent = (string)($_SERVER['HTTP_HOST'] ?? $serverName);
        $host = in_array($sent, $allowed, true) ? $sent : $allowed[0];
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function activation_url(string $token): string
{
    $configured = trim((string)(getenv('POS_APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/activate?token=' . rawurlencode($token);
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index'));
    $base = dirname($script);
    // Email confirmations can be triggered from /api/profile, so climb out
    // of the API directory when deriving the public app base path.
    if (basename($base) === 'api') {
        $base = dirname($base);
    }
    $base = rtrim($base === '.' ? '' : $base, '/');
    return activation_base_url() . $base . '/activate?token=' . rawurlencode($token);
}

/** @param array<string,mixed> $activation */
function activation_send_email(array $activation): array
{
    $website = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
    $name = (string)($activation['full_name'] ?? 'there');
    $link = activation_url((string)$activation['token']);
    $emailChange = ($activation['purpose'] ?? '') === 'email_change';
    $subject = $emailChange
        ? 'Confirm your new email address - ' . $website
        : 'Activate your ' . $website . ' account';
    $body = "Hello " . $name . ",\n\n"
        . ($emailChange
            ? "Confirm the new email address on your account by opening this link:\n\n"
            : "Your client account has been created. Confirm your email below to activate your account and start signing in:\n\n")
        . $link . "\n\n"
        . "This link expires in 24 hours. If you did not request this, you can ignore this email.\n\n"
        . "— " . $website;
    return send_pos_email((string)$activation['email'], $subject, $body);
}

/**
 * Create a pending client account and its activation token.
 *
 * @return array<string,mixed>
 */
function activation_register(PDO $pdo, string $username, string $fullName, string $email, string $password): array
{
    if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
        fail('Username must be 3-100 characters using letters, numbers, dot, dash, or underscore.');
    }
    if ($fullName === '' || mb_strlen($fullName) > 150) {
        fail('Full name is required (150 characters maximum).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        fail('Enter a valid email address.');
    }
    if (strlen($password) < 8) {
        fail('Password must be at least 8 characters.');
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $check->execute([$username]);
    if ($check->fetchColumn() !== false) {
        fail('That username is already taken.', 409);
    }
    if (activation_email_exists($pdo, $email)) {
        fail('That email address is already registered.', 409);
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password, full_name, role, status)
             VALUES (?, ?, ?, "cashier", "inactive")'
        );
        $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        $userId = (int)$pdo->lastInsertId();

        $emailJson = json_encode(['email' => $email, 'verified' => false], JSON_UNESCAPED_UNICODE);
        $emailStmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        );
        $emailStmt->execute([activation_email_key($userId), $emailJson, $emailJson]);

        $activation = activation_create_token($pdo, $userId, $email, $fullName, 'signup');
        $activation['username'] = $username;
        $pdo->commit();
        return $activation;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/** Remove a pending account when its activation email cannot be delivered. */
function activation_cleanup(PDO $pdo, int $userId, string $token): void
{
    $stmt = $pdo->prepare('DELETE FROM settings WHERE setting_key IN (?, ?)');
    $stmt->execute([activation_token_key($token), activation_email_key($userId)]);
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND status = "inactive"');
    $stmt->execute([$userId]);
}

/** @return array<string,mixed> */
function activation_activate(PDO $pdo, string $token): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE');
        $stmt->execute([activation_token_key($token)]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            fail('This activation link is invalid or has already been used.', 404);
        }
        $activation = json_decode((string)$raw, true);
        if (!is_array($activation)) {
            fail('This activation link is invalid or has expired.', 400);
        }
        if (strtotime((string)$activation['expires_at']) < time()) {
            fail('This activation link has expired. Request a new one.', 400);
        }

        $userId = (int)$activation['user_id'];
        $userCheck = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $userCheck->execute([$userId]);
        if ($userCheck->fetchColumn() === false) {
            fail('This activation link is invalid or has expired.', 400);
        }

        $purpose = (string)($activation['purpose'] ?? 'signup');
        if ($purpose === 'email_change'
            && activation_email_conflict($pdo, (string)$activation['email'], $userId)
        ) {
            fail('That email address is already registered by another account.', 409);
        }

        $emailKey = activation_email_key($userId);
        $emailStateStmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $emailStateStmt->execute([$emailKey]);
        $emailStateRaw = $emailStateStmt->fetchColumn();
        $emailState = is_string($emailStateRaw) ? json_decode($emailStateRaw, true) : null;
        $emailAlreadyVerified = is_array($emailState) && !empty($emailState['verified']);

        // Email-change confirmations do not alter account status. Only the first
        // signup confirmation activates; admin-disabled accounts stay disabled.
        $emailData = json_encode([
            'email' => (string)$activation['email'],
            'verified' => true,
            'verified_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        $emailStmt = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $emailStmt->execute([$emailData, $emailKey]);

        if ($purpose === 'signup' && !$emailAlreadyVerified) {
            // Confirming the email is the final approval for a public client account.
            $statusStmt = $pdo->prepare(
                'UPDATE users SET status = "active" WHERE id = ? AND role = "cashier" AND status = "inactive"'
            );
            $statusStmt->execute([$userId]);
        }

        $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
        $delete->execute([activation_token_key($token)]);

        $pdo->commit();
        return $activation;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/** Issue a fresh token for a pending account, with a one-minute cooldown. */
function activation_resend(PDO $pdo, string $email): array
{
    $record = activation_find_email($pdo, $email);
    if ($record === null) {
        fail('No pending account was found for that email address.', 404);
    }
    $userId = (int)$record['user_id'];
    $stmt = $pdo->prepare('SELECT username, full_name, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        fail('No pending account was found for that email address.', 404);
    }
    if ($user['status'] === 'active') {
        fail('This account is already active. You can sign in.', 400);
    }
    if (!empty($record['verified'])) {
        fail('This account is disabled. Please contact the administrator.', 400);
    }

    $existing = null;
    $tokens = $pdo->query('SELECT setting_key, setting_value FROM settings');
    foreach ($tokens->fetchAll() as $row) {
        if (!str_starts_with((string)$row['setting_key'], activation_token_prefix())) {
            continue;
        }
        $data = json_decode((string)$row['setting_value'], true);
        if (is_array($data) && (int)($data['user_id'] ?? 0) === $userId) {
            $existing = $data;
            break;
        }
    }
    if ($existing && isset($existing['last_sent_at'])
        && strtotime((string)$existing['last_sent_at']) > time() - 60) {
        fail('Please wait a minute before requesting another activation email.');
    }

    return activation_create_token($pdo, $userId, (string)$record['email'], (string)$user['full_name'], 'signup');
}
