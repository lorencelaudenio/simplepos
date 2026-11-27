<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/invites.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    require_admin();
    $pdo = db();
    $me = current_user();
    $myId = (int)($me['id'] ?? 0);

    if ($action === 'overview') {
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $clientCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN ("cashier", "client")')->fetchColumn();
        $productCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();
        $lowStock = low_stock_count($pdo);
        $salesRow = $pdo->query('SELECT COUNT(*) AS receipts, COALESCE(SUM(total_amount), 0) AS total FROM sales')->fetch();
        $recentStmt = $pdo->query(
            'SELECT receipt_number, created_at, total_amount FROM sales ORDER BY id DESC LIMIT 5'
        );
        $recent = [];
        foreach ($recentStmt->fetchAll() as $sale) {
            $recent[] = [
                'receipt_number' => (string)$sale['receipt_number'],
                'created_at' => (string)$sale['created_at'],
                'total_amount' => (float)$sale['total_amount'],
            ];
        }
        json_ok([
            'overview' => [
                'users' => $userCount,
                'clients' => $clientCount,
                'products' => $productCount,
                'low_stock' => $lowStock,
                'receipts' => (int)$salesRow['receipts'],
                'sales_total' => round((float)$salesRow['total'], 2),
            ],
            'recent_sales' => $recent,
        ]);
    }

    if ($action === 'users_list') {
        $stmt = $pdo->query(
            'SELECT id, username, full_name, role, status, created_at FROM users ORDER BY id ASC'
        );
        json_ok(['users' => $stmt->fetchAll()]);
    }

    if ($action === 'user_save') {
        $body = request_body();
        $id = ival($body, 'id');
        $username = sval($body, 'username');
        $fullName = sval($body, 'full_name');
        $role = sval($body, 'role');
        $status = sval($body, 'status', 'active');
        $password = (string)($body['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
            fail('Username must be 3-100 characters using letters, numbers, dot, dash, or underscore.');
        }
        if ($fullName === '' || strlen($fullName) > 150) {
            fail('Full name is required (150 characters maximum).');
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

        if ($id > 0) {
            if ($id === $myId && ($storedRole !== 'admin' || $status !== 'active')) {
                fail('You cannot change your own role or disable your own account.');
            }
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
        json_ok([], 'User deleted successfully.');
    }

    if ($action === 'invite_list') {
        json_ok(['invites' => invite_list($pdo)]);
    }

    if ($action === 'invite_create') {
        $body = request_body();
        $days = filter_var($body['days'] ?? null, FILTER_VALIDATE_INT);
        $maxUses = filter_var($body['max_uses'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $maxUses === false) {
            fail('Choose a valid expiry period and usage limit.');
        }
        $invite = invite_create($pdo, $myId, (int)$days, (int)$maxUses);
        json_ok(['invite' => $invite], 'Invite code created: ' . $invite['code']);
    }

    if ($action === 'invite_revoke') {
        invite_revoke($pdo, sval(request_body(), 'code'));
        json_ok([], 'Invite code revoked.');
    }

    fail('Unknown admin action.', 404);
});
