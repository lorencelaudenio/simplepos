<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/activation.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    require_admin();
    $pdo = db();
    $me = current_user();
    $myId = (int)($me['id'] ?? 0);

    if ($action === 'overview') {
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $clientCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("cashier", "client")')->fetchColumn();
        json_ok([
            'overview' => [
                'users' => $userCount,
                'clients' => $clientCount,
            ],
        ]);
    }

    if ($action === 'users_list') {
        $emails = [];
        $settings = $pdo->query('SELECT setting_key, setting_value FROM settings');
        foreach ($settings->fetchAll() as $row) {
            $key = (string)$row['setting_key'];
            if (!str_starts_with($key, activation_email_prefix())) {
                continue;
            }
            $data = json_decode((string)$row['setting_value'], true);
            if (is_array($data)) {
                $emails[(int)substr($key, strlen(activation_email_prefix()))] = (string)($data['email'] ?? '');
            }
        }

        $stmt = $pdo->query(
            'SELECT id, username, full_name, role, status, created_at FROM users ORDER BY id ASC'
        );
        $users = $stmt->fetchAll();
        foreach ($users as &$user) {
            $user['email'] = $emails[(int)$user['id']] ?? '';
        }
        unset($user);
        json_ok(['users' => $users]);
    }

    if ($action === 'user_save') {
        $body = request_body();
        $id = ival($body, 'id');
        $username = sval($body, 'username');
        $fullName = sval($body, 'full_name');
        $email = sval($body, 'email');
        $role = sval($body, 'role');
        $status = sval($body, 'status', 'active');
        $password = (string)($body['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
            fail('Username must be 3-100 characters using letters, numbers, dot, dash, or underscore.');
        }
        if ($fullName === '' || strlen($fullName) > 150) {
            fail('Full name is required (150 characters maximum).');
        }
        if ($email === '' && $id <= 0) {
            fail('Email address is required.');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
            fail('Enter a valid email address (150 characters maximum).');
        }
        if (!in_array($role, ['admin', 'cashier', 'client'], true)) {
            fail('Invalid user role.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            fail('Invalid account status.');
        }
        // "client" is an alias stored as "cashier" for compatibility with existing schemas.
        $storedRole = $role === 'client' ? 'cashier' : $role;
        if ($id <= 0 && strlen($password) < 8) {
            fail('Password must be at least 8 characters.');
        }
        if ($id > 0 && $password !== '' && strlen($password) < 8) {
            fail('Password must be at least 8 characters, or leave it blank to keep the current one.');
        }

        $current = null;
        if ($id > 0) {
            $currentStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch() ?: null;
            if ($current === null) {
                fail('User not found.', 404);
            }
        }

        if ($email !== '' && activation_email_conflict($pdo, $email, $id)) {
            fail('That email address is already registered.', 409);
        }

        $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
        $isCurrentAdmin = $current !== null && $current['role'] === 'admin';
        if ($storedRole === 'admin' && !$isCurrentAdmin && $adminCount >= 1) {
            fail('Only one website administrator is allowed.');
        }
        if ($isCurrentAdmin && ($storedRole !== 'admin' || $status !== 'active') && $adminCount <= 1) {
            fail('The website administrator cannot be demoted or disabled.');
        }

        $check = $pdo->prepare(
            'SELECT id FROM users WHERE username = ?' . ($id > 0 ? ' AND id <> ?' : '')
        );
        $checkParams = [$username];
        if ($id > 0) {
            $checkParams[] = $id;
        }
        $check->execute($checkParams);
        if ($check->fetchColumn()) {
            fail('That username is already taken.');
        }

        if ($id > 0 && $id === $myId && ($storedRole !== 'admin' || $status !== 'active')) {
            fail('You cannot change your own role or disable your own account.');
        }

        // Admin-entered addresses are trusted, so they are stored as verified.
        // A blank value while editing keeps any existing address unchanged.
        $emailJson = null;
        if ($email !== '') {
            $emailJson = json_encode([
                'email' => $email,
                'verified' => true,
                'verified_at' => date('Y-m-d H:i:s'),
                'source' => 'admin',
            ], JSON_UNESCAPED_UNICODE);
            if ($emailJson === false) {
                fail('Email address could not be saved.');
            }
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE users SET username = ?, full_name = ?, role = ?, status = ?, password = ? WHERE id = ?'
                    );
                    $stmt->execute([$username, $fullName, $storedRole, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users SET username = ?, full_name = ?, role = ?, status = ? WHERE id = ?'
                    );
                    $stmt->execute([$username, $fullName, $storedRole, $status, $id]);
                }
                $message = 'User updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $storedRole, $status]);
                $id = (int)$pdo->lastInsertId();
                $message = 'User created successfully.';
            }

            if ($emailJson !== null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?'
                );
                $stmt->execute([activation_email_key($id), $emailJson, $emailJson]);
                activation_delete_for_user($pdo, $id);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('POS user save error: ' . $ex->getMessage());
            fail('User could not be saved. Please try again.', 500);
        }

        clear_setting_cache();
        json_ok(['id' => $id], $message);
    }

    if ($action === 'user_toggle') {
        $id = ival(request_body(), 'id');
        if ($id === $myId) {
            fail('You cannot disable your own account.');
        }
        $stmt = $pdo->prepare('SELECT status, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            fail('User not found.', 404);
        }
        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        if ($user['role'] === 'admin' && $newStatus === 'inactive') {
            $admins = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND status = "active"')->fetchColumn();
            if ($admins <= 1) {
                fail('At least one administrator must remain active.');
            }
        }
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $id]);
        json_ok([], $newStatus === 'active' ? 'Account activated.' : 'Account disabled.');
    }

    if ($action === 'user_delete') {
        $id = ival(request_body(), 'id');
        if ($id === $myId) {
            fail('You cannot delete your own account.');
        }
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $role = $stmt->fetchColumn();
        if ($role === false) {
            fail('User not found.', 404);
        }
        if ($role === 'admin') {
            $admins = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
            if ($admins <= 1) {
                fail('The last administrator cannot be deleted.');
            }
        }
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
        activation_delete_for_user($pdo, $id);
        $stmt = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
        $stmt->execute([activation_email_key($id)]);
        clear_setting_cache();
        json_ok([], 'User deleted successfully.');
    }

    fail('Unknown admin action.', 404);
});
