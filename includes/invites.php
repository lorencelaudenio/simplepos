<?php
declare(strict_types=1);

/** Invite-only public signup helpers. Invite codes are stored in the settings table. */

function invite_prefix(): string
{
    return 'signup_invite_';
}

function invite_normalize_code(string $code): string
{
    $clean = preg_replace('/[^A-Za-z0-9]/', '', $code);
    return strtoupper($clean === null ? '' : $clean);
}

function invite_valid_code(string $code): bool
{
    return (bool)preg_match('/^[A-Z0-9]{6,20}$/', $code);
}

function invite_setting_key(string $code): string
{
    return invite_prefix() . $code;
}

/** @return array{code:string,created_at:string,expires_at:?string,max_uses:int,uses:int,revoked:bool,created_by:int} */
function invite_create(PDO $pdo, int $createdBy, int $days, int $maxUses): array
{
    if (!in_array($days, [0, 1, 7, 30], true)) {
        fail('Choose a valid expiry period.');
    }
    if (!in_array($maxUses, [1, 5, 25, 100], true)) {
        fail('Choose a valid usage limit.');
    }

    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $existsStmt->execute([invite_setting_key($code)]);
        if ((int)$existsStmt->fetchColumn() === 0) {
            break;
        }
        $code = '';
    }
    if ($code === '') {
        fail('Unable to generate a unique invite code. Please try again.', 500);
    }

    $invite = [
        'code' => $code,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
        'max_uses' => $maxUses,
        'uses' => 0,
        'revoked' => false,
        'created_by' => $createdBy,
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    );
    $json = json_encode($invite, JSON_UNESCAPED_UNICODE);
    $stmt->execute([invite_setting_key($code), $json, $json]);
    return $invite;
}

/** @return array<int,array<string,mixed>> */
function invite_list(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $invites = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['setting_key'];
        if (!str_starts_with($key, invite_prefix())) {
            continue;
        }
        $invite = json_decode((string)$row['setting_value'], true);
        if (!is_array($invite)) {
            continue;
        }
        $expiresAt = isset($invite['expires_at']) ? (string)$invite['expires_at'] : null;
        $invite['code'] = substr($key, strlen(invite_prefix()));
        $invite['expired'] = $expiresAt !== null && strtotime($expiresAt) < time();
        $invite['exhausted'] = (int)($invite['uses'] ?? 0) >= (int)($invite['max_uses'] ?? 0);
        $invite['usable'] = empty($invite['revoked']) && !$invite['expired'] && !$invite['exhausted'];
        $invites[] = $invite;
    }
    usort($invites, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    return $invites;
}

function invite_revoke(PDO $pdo, string $code): void
{
    $code = invite_normalize_code($code);
    if (!invite_valid_code($code)) {
        fail('Enter a valid invite code.');
    }
    $key = invite_setting_key($code);
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        fail('Invite code not found.', 404);
    }
    $invite = json_decode((string)$raw, true);
    if (!is_array($invite)) {
        fail('Invite code is unreadable.', 500);
    }
    $invite['revoked'] = true;
    $update = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
    $update->execute([json_encode($invite, JSON_UNESCAPED_UNICODE), $key]);
}

/**
 * Redeem an invite and create a client account in one transaction.
 *
 * @return array{user_id:int,invite:array<string,mixed>}
 */
function invite_register(PDO $pdo, string $code, string $username, string $password, string $fullName): array
{
    $code = invite_normalize_code($code);
    if (!invite_valid_code($code)) {
        fail('Enter a valid invite code.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE');
        $stmt->execute([invite_setting_key($code)]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            fail('That invite code is invalid. Ask your administrator for a new one.', 404);
        }
        $invite = json_decode((string)$raw, true);
        if (!is_array($invite)) {
            fail('That invite code is unreadable.', 500);
        }
        if (!empty($invite['revoked'])) {
            fail('That invite code has been revoked.');
        }
        $expiresAt = isset($invite['expires_at']) ? (string)$invite['expires_at'] : '';
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            fail('That invite code has expired.');
        }
        if ((int)($invite['uses'] ?? 0) >= (int)($invite['max_uses'] ?? 0)) {
            fail('That invite code has reached its usage limit.');
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->execute([$username]);
        if ($check->fetchColumn() !== false) {
            fail('That username is already taken.', 409);
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (username, password, full_name, role, status)
             VALUES (?, ?, ?, "cashier", "active")'
        );
        $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        $userId = (int)$pdo->lastInsertId();

        $invite['uses'] = (int)($invite['uses'] ?? 0) + 1;
        $update = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $update->execute([json_encode($invite, JSON_UNESCAPED_UNICODE), invite_setting_key($code)]);

        $pdo->commit();
        return ['user_id' => $userId, 'invite' => $invite];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
