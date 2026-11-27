<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    require_admin();

    $keys = [
        'store_name', 'store_address', 'receipt_footer',
        'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name',
    ];

    $publicSettings = static function () use ($keys): array {
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = get_setting($key);
        }
        // Never expose the stored SMTP password to the browser.
        $settings['smtp_pass_configured'] = $settings['smtp_pass'] !== '';
        $settings['smtp_pass'] = '';
        return $settings;
    };

    if ($action === 'get') {
        json_ok(['settings' => $publicSettings()]);
    }

    if ($action === 'save') {
        $body = request_body();
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = sval($body, $key);
        }

        if ($values['store_name'] === '' || mb_strlen($values['store_name']) > 150) {
            fail('Store name is required (150 characters maximum).');
        }
        if (!ctype_digit($values['smtp_port']) || (int)$values['smtp_port'] < 1 || (int)$values['smtp_port'] > 65535) {
            fail('Enter a valid SMTP port.');
        }
        if (!in_array($values['smtp_secure'], ['tls', 'ssl', 'none'], true)) {
            fail('Invalid SMTP security option.');
        }
        if (mb_strlen($values['smtp_host']) > 200 || mb_strlen($values['smtp_user']) > 200 || mb_strlen($values['smtp_from']) > 200) {
            fail('SMTP fields are too long.');
        }

        // A blank password field means "keep the current password".
        if ($values['smtp_pass'] === '') {
            unset($values['smtp_pass']);
        }

        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        );
        $pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                $stmt->execute([$key, $value, $value]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            clear_setting_cache();
            fail('Settings could not be saved: ' . $ex->getMessage(), 500);
        }

        clear_setting_cache();
        json_ok(['settings' => $publicSettings()], 'Settings saved successfully.');
    }

    if ($action === 'test_email') {
        $email = sval(request_body(), 'email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Enter a valid email address.');
        }
        if (!smtp_configured()) {
            fail('SMTP is not configured. Save your SMTP settings first.');
        }
        $store = get_setting('store_name', 'Simple Online POS');
        try {
            send_pos_email(
                $email,
                $store . ' email test',
                "This is a test email from " . $store . ".\n\nIf you received this, your SMTP settings are working."
            );
        } catch (ApiException $ex) {
            throw $ex;
        } catch (Throwable $ex) {
            fail('Email could not be sent: ' . $ex->getMessage(), 502);
        }
        json_ok([], 'Test email sent successfully.');
    }

    fail('Unknown settings action.', 404);
});
