<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/backup_lib.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    require_admin();
    $pdo = db();

    if ($action === 'download') {
        $sql = generate_sql_dump($pdo);
        $filename = pos_backup_filename();
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo $sql;
        exit;
    }

    if ($action === 'email') {
        $body = request_body();
        $email = sval($body, 'email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Please enter a valid email address.');
        }
        if (get_setting('smtp_host') === '') {
            fail('SMTP is not configured. Open Backup & Restore → Store & SMTP Settings first.');
        }
        $filename = pos_backup_filename();
        $sql = generate_sql_dump($pdo);
        try {
            $result = send_backup_email(
                $email,
                website_name() . ' Database Backup - ' . date('Y-m-d H:i:s'),
                "Attached is the latest database backup for " . website_name() . ".\nGenerated: " . date('Y-m-d H:i:s') . "\n\nKeep this file in a safe place.",
                $filename,
                $sql
            );
        } catch (Throwable $ex) {
            fail('Email could not be sent: ' . $ex->getMessage(), 502);
        }
        error_log('POS backup email accepted for ' . $email . ' via ' . ($result['method'] ?? 'smtp') . '.');
        json_ok(
            ['method' => $result['method'] ?? 'smtp'],
            'Backup email was accepted by the mail server for ' . $email . '. Check your spam folder if it does not arrive.'
        );
    }

    if ($action === 'upload') {
        if (!isset($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail('Please choose a .sql backup file.');
        }
        if ((int)$_FILES['file']['size'] > 50 * 1024 * 1024) {
            fail('The backup file must be 50 MB or smaller.');
        }
        if (strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'sql') {
            fail('Only .sql backup files are accepted.');
        }
        $content = file_get_contents($_FILES['file']['tmp_name']);
        if ($content === false || strlen($content) < 100) {
            fail('The backup file is empty or too small.');
        }
        $validation = pos_validate_backup($content);
        if (!$validation['ok']) {
            fail($validation['error']);
        }
        $destination = upload_dir('backups') . DIRECTORY_SEPARATOR . 'upload_' . bin2hex(random_bytes(12)) . '.sql';
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            fail('Unable to store the uploaded backup.');
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['restore_backup'] = [
            'token' => $token,
            'path' => $destination,
            'filename' => basename((string)$_FILES['file']['name']),
            'size' => (int)$_FILES['file']['size'],
            'created_at' => time(),
        ];
        json_ok([
            'token' => $token,
            'filename' => basename((string)$_FILES['file']['name']),
            'size' => (int)$_FILES['file']['size'],
        ], 'Backup file validated. Confirm the restore to continue.');
    }

    if ($action === 'restore') {
        $body = request_body();
        $token = sval($body, 'token');
        if (!bval($body, 'confirm')) {
            fail('Please confirm the restore operation.');
        }
        $pending = $_SESSION['restore_backup'] ?? null;
        if (!$pending || !hash_equals((string)($pending['token'] ?? ''), $token)) {
            fail('The restore session has expired. Please upload the backup again.');
        }
        if ((time() - (int)($pending['created_at'] ?? 0)) > 1800) {
            @unlink((string)($pending['path'] ?? ''));
            unset($_SESSION['restore_backup']);
            fail('The restore session has expired. Please upload the backup again.');
        }
        $path = (string)$pending['path'];
        if (!is_file($path)) {
            unset($_SESSION['restore_backup']);
            fail('The uploaded backup file no longer exists. Please upload it again.');
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            fail('Unable to read the uploaded backup.');
        }
        $validation = pos_validate_backup($sql);
        if (!$validation['ok']) {
            fail($validation['error']);
        }

        // Always keep a recovery point of the current database before restoring.
        $autoName = pos_backup_filename('auto_before_restore');
        $autoPath = upload_dir('backups') . DIRECTORY_SEPARATOR . $autoName;
        $currentDump = generate_sql_dump($pdo);
        if (file_put_contents($autoPath, $currentDump, LOCK_EX) === false) {
            fail('Unable to create an automatic safety backup. Restore cancelled.');
        }

        try {
            pos_restore($pdo, $validation['statements']);
            $adminRows = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
            $activeAdminRows = (int)$pdo->query(
                'SELECT COUNT(*) FROM users WHERE role = "admin" AND status = "active"'
            )->fetchColumn();
            if ($adminRows !== 1 || $activeAdminRows !== 1) {
                throw new RuntimeException('Restored database does not contain exactly one active administrator.');
            }
        } catch (Throwable $ex) {
            error_log('POS restore failed: ' . $ex->getMessage());
            $safety = pos_validate_backup($currentDump);
            if ($safety['ok']) {
                try {
                    pos_restore($pdo, $safety['statements']);
                } catch (Throwable $restoreError) {
                    error_log('POS safety restore failed: ' . $restoreError->getMessage());
                }
            }
            fail('Restore failed and the previous database was restored. A safety backup is available as ' . $autoName . '.', 500);
        }

        @unlink($path);
        unset($_SESSION['restore_backup']);
        json_ok(['safety_backup' => $autoName], 'Database restored successfully. A safety backup was saved before the restore.');
    }

    fail('Unknown backup action.', 404);
});
