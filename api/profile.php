<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/activation.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    $pdo = db();
    $user = current_user();
    if (!$user) {
        fail('Your session has expired. Please log in again.', 401);
    }
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        fail('Your session is invalid. Please log in again.', 401);
    }

    $readProfile = static function () use ($pdo, $id): array {
        $stmt = $pdo->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            fail('Account not found.', 404);
        }

        $email = '';
        $emailStmt = $pdo->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
        );
        $emailStmt->execute([activation_email_key($id)]);
        $raw = $emailStmt->fetchColumn();
        if ($raw !== false) {
            $data = json_decode((string)$raw, true);
            if (is_array($data)) {
                $email = (string)($data['email'] ?? '');
            }
        }

        return [
            'username' => (string)$row['username'],
            'full_name' => (string)$row['full_name'],
            'email' => $email,
        ];
    };

    if ($action === 'get') {
        json_ok(['profile' => $readProfile()]);
    }

    if ($action === 'save') {
        $body = request_body();
        $fullName = sval($body, 'full_name');
        $email = sval($body, 'email');
        $currentPassword = (string)($body['current_password'] ?? '');
        $newPassword = (string)($body['new_password'] ?? '');
        $confirmPassword = (string)($body['confirm_password'] ?? '');

        if ($fullName === '' || mb_strlen($fullName) > 150) {
            fail('Full name is required (150 characters maximum).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            fail('Enter a valid email address (150 characters maximum).');
        }
        if ($currentPassword === '') {
            fail('Enter your current password to save changes.');
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            fail('New password must be at least 8 characters.');
        }
        if ($newPassword !== '' && $newPassword !== $confirmPassword) {
            fail('New passwords do not match.');
        }

        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || !password_verify($currentPassword, (string)$hash)) {
            fail('Your current password is incorrect.', 403);
        }

        $existing = activation_find_email($pdo, $email);
        if ($existing !== null && (int)($existing['user_id'] ?? 0) !== $id) {
            fail('That email address is already registered.', 409);
        }

        $emailStmt = $pdo->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
        );
        $emailStmt->execute([activation_email_key($id)]);
        $rawEmail = $emailStmt->fetchColumn();
        $currentEmail = '';
        $wasVerified = false;
        if ($rawEmail !== false) {
            $data = json_decode((string)$rawEmail, true);
            if (is_array($data)) {
                $currentEmail = (string)($data['email'] ?? '');
                $wasVerified = !empty($data['verified']);
            }
        }
        $emailChanged = strcasecmp($currentEmail, $email) !== 0;
        $needsVerification = $emailChanged || !$wasVerified;
        $smtpReady = get_setting('smtp_host') !== '';
        if ($needsVerification && !$smtpReady && $currentEmail !== '') {
            fail('SMTP is not configured. Ask an administrator to configure it before changing your email.', 400);
        }
        $sendVerification = $needsVerification && $smtpReady;

        $verificationToken = '';
        if ($sendVerification) {
            $activation = activation_create_token($pdo, $id, $email, $fullName, 'email_change');
            $verificationToken = (string)$activation['token'];
            try {
                activation_send_email($activation);
            } catch (Throwable $ex) {
                error_log('POS email verification error: ' . $ex->getMessage());
                $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
                $delete->execute([activation_token_key($verificationToken)]);
                fail('Unable to send the verification email. Your details were not changed.', 502);
            }
        }

        $emailData = [
            'email' => $email,
            'verified' => !$needsVerification,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($needsVerification) {
            $emailData['purpose'] = 'email_change';
        }
        $emailJson = json_encode($emailData, JSON_UNESCAPED_UNICODE);

        $pdo->beginTransaction();
        try {
            if ($newPassword !== '') {
                $stmt = $pdo->prepare(
                    'UPDATE users SET full_name = ?, password = ? WHERE id = ?'
                );
                $stmt->execute([$fullName, password_hash($newPassword, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?');
                $stmt->execute([$fullName, $id]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?'
            );
            $stmt->execute([activation_email_key($id), $emailJson, $emailJson]);
            if (!$sendVerification) {
                activation_delete_for_user($pdo, $id);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($verificationToken !== '') {
                $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
                $delete->execute([activation_token_key($verificationToken)]);
            }
            error_log('POS account update error: ' . $ex->getMessage());
            fail('Account could not be updated. Please try again.', 500);
        }

        clear_setting_cache();
        $_SESSION['user']['full_name'] = $fullName;
        if ($sendVerification) {
            $message = 'Account updated. Check your email address to confirm it.';
        } elseif ($needsVerification) {
            $message = 'Account updated. Ask an administrator to configure SMTP so your email can be confirmed.';
        } else {
            $message = 'Account updated successfully.';
        }
        json_ok(['profile' => $readProfile()], $message);
    }

    fail('Unknown account action.', 404);
});
