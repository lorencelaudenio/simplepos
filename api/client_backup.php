<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/client_backup_lib.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    $pdo = db();
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        fail('Your session is invalid. Please log in again.', 401);
    }
    $storeName = get_setting('store_name', 'My Store');

    if ($action === 'download') {
        try {
            $json = client_backup_generate($pdo, $userId);
        } catch (Throwable $ex) {
            error_log('POS client backup error: ' . $ex->getMessage());
            fail('Your backup could not be generated. Please try again.', 500);
        }
        $filename = client_backup_filename('', $storeName);
        $asciiFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    if ($action === 'upload') {
        if (!isset($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail('Please choose a client backup file.');
        }
        if ((int)$_FILES['file']['size'] > 50 * 1024 * 1024) {
            fail('The backup file must be 50 MB or smaller.');
        }
        if (strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'json') {
            fail('Only .json client backup files are accepted.');
        }
        $content = file_get_contents($_FILES['file']['tmp_name']);
        if ($content === false || strlen($content) < 20) {
            fail('The backup file is empty or too small.');
        }
        $validation = client_backup_validate($content, $userId);
        if (!$validation['ok']) {
            fail($validation['error']);
        }
        if ((int)$validation['user_id'] !== $userId) {
            fail('This backup belongs to a different account.');
        }
        $destination = upload_dir('backups') . DIRECTORY_SEPARATOR . 'upload_client_' . bin2hex(random_bytes(12)) . '.json';
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            fail('Unable to store the uploaded backup.');
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['client_restore_backup'] = [
            'token' => $token,
            'path' => $destination,
            'owner_id' => $userId,
            'filename' => basename((string)$_FILES['file']['name']),
            'size' => (int)$_FILES['file']['size'],
            'created_at' => time(),
        ];
        json_ok([
            'token' => $token,
            'filename' => basename((string)$_FILES['file']['name']),
            'size' => (int)$_FILES['file']['size'],
        ], 'Backup validated. Confirm the restore to continue.');
    }

    if ($action === 'restore') {
        $body = request_body();
        $token = sval($body, 'token');
        if (!bval($body, 'confirm')) {
            fail('Please confirm the restore operation.');
        }
        $pending = $_SESSION['client_restore_backup'] ?? null;
        if (!$pending
            || (int)($pending['owner_id'] ?? 0) !== $userId
            || !hash_equals((string)($pending['token'] ?? ''), $token)
        ) {
            fail('The restore session has expired. Please upload the backup again.');
        }
        if ((time() - (int)($pending['created_at'] ?? 0)) > 1800) {
            @unlink((string)($pending['path'] ?? ''));
            unset($_SESSION['client_restore_backup']);
            fail('The restore session has expired. Please upload the backup again.');
        }
        $path = (string)$pending['path'];
        if (!is_file($path)) {
            unset($_SESSION['client_restore_backup']);
            fail('The uploaded backup file no longer exists. Please upload it again.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            fail('Unable to read the uploaded backup.');
        }
        $validation = client_backup_validate($content, $userId);
        if (!$validation['ok']) {
            fail($validation['error']);
        }
        if ((int)$validation['user_id'] !== $userId) {
            fail('This backup belongs to a different account.');
        }

        try {
            $safetyJson = client_backup_generate($pdo, $userId);
        } catch (Throwable $ex) {
            error_log('POS client safety backup error: ' . $ex->getMessage());
            fail('Unable to create a safety backup. Restore cancelled.', 500);
        }
        $safetyName = client_backup_filename('auto_before_client_restore_' . $userId, $storeName);
        $safetyPath = upload_dir('backups') . DIRECTORY_SEPARATOR . $safetyName;
        if (file_put_contents($safetyPath, $safetyJson, LOCK_EX) === false) {
            fail('Unable to create a safety backup. Restore cancelled.', 500);
        }

        try {
            $result = client_backup_restore($pdo, $validation, $userId);
        } catch (Throwable $ex) {
            error_log('POS client restore failed: ' . $ex->getMessage());
            try {
                $safety = client_backup_validate($safetyJson, $userId);
                if ($safety['ok']) {
                    client_backup_restore($pdo, $safety, $userId);
                }
            } catch (Throwable $safetyError) {
                error_log('POS client safety restore failed: ' . $safetyError->getMessage());
            }
            fail('Restore failed and your previous sales were restored. A safety backup is available as ' . $safetyName . '.', 500);
        }

        @unlink($path);
        unset($_SESSION['client_restore_backup']);
        json_ok([
            'safety_backup' => $safetyName,
            'sales' => $result['sales'],
            'items' => $result['items'],
        ], 'Your sales were restored successfully. A safety backup was saved first.');
    }

    fail('Unknown client backup action.', 404);
});
