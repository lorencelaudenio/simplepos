<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    $storeKeys = ['store_name', 'store_address', 'tin_id', 'contact_number', 'receipt_footer'];
    $smtpKeys = [
        'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name',
    ];
    $isAdmin = is_admin();
    $allowedKeys = $isAdmin ? array_merge(['website_name'], $smtpKeys) : $storeKeys;

    $publicSettings = static function () use ($allowedKeys, $isAdmin): array {
        $settings = [];
        foreach ($allowedKeys as $key) {
            if ($key === 'website_name') {
                $settings[$key] = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
            } else {
                $settings[$key] = get_setting($key);
            }
        }
        if ($isAdmin) {
            // Never expose the stored SMTP password to the browser.
            $settings['smtp_pass_configured'] = $settings['smtp_pass'] !== '';
            $settings['smtp_pass'] = '';
        }
        return $settings;
    };

    if ($action === 'get') {
        json_ok(['settings' => $publicSettings()]);
    }

    if ($action === 'save') {
        $body = request_body();
        $values = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $body)) {
                $values[$key] = sval($body, $key);
            }
        }
        if ($values === []) {
            fail('No settings were provided.');
        }

        if (array_key_exists('website_name', $values)
            && ($values['website_name'] === '' || mb_strlen($values['website_name']) > 150)
        ) {
            fail('Website name is required (150 characters maximum).');
        }
        if (array_key_exists('store_name', $values)
            && ($values['store_name'] === '' || mb_strlen($values['store_name']) > 150)
        ) {
            fail('Store name is required (150 characters maximum).');
        }
        if (array_key_exists('store_address', $values) && mb_strlen($values['store_address']) > 255) {
            fail('Store address is too long (255 characters maximum).');
        }
        if (array_key_exists('tin_id', $values) && mb_strlen($values['tin_id']) > 100) {
            fail('TIN ID is too long (100 characters maximum).');
        }
        if (array_key_exists('contact_number', $values) && mb_strlen($values['contact_number']) > 100) {
            fail('Contact number is too long (100 characters maximum).');
        }
        if (array_key_exists('receipt_footer', $values) && mb_strlen($values['receipt_footer']) > 255) {
            fail('Receipt footer is too long (255 characters maximum).');
        }
        if ($isAdmin) {
            if (array_key_exists('smtp_port', $values)
                && (!ctype_digit($values['smtp_port']) || (int)$values['smtp_port'] < 1 || (int)$values['smtp_port'] > 65535)
            ) {
                fail('Enter a valid SMTP port.');
            }
            if (array_key_exists('smtp_secure', $values)
                && !in_array($values['smtp_secure'], ['tls', 'ssl', 'none'], true)
            ) {
                fail('Invalid SMTP security option.');
            }
            if (array_key_exists('smtp_host', $values) && mb_strlen($values['smtp_host']) > 200) {
                fail('SMTP host is too long.');
            }
            if (array_key_exists('smtp_user', $values) && mb_strlen($values['smtp_user']) > 200) {
                fail('SMTP username is too long.');
            }
            if (array_key_exists('smtp_from', $values) && mb_strlen($values['smtp_from']) > 200) {
                fail('From email is too long.');
            }
            if (array_key_exists('smtp_from', $values)
                && $values['smtp_from'] !== ''
                && !filter_var($values['smtp_from'], FILTER_VALIDATE_EMAIL)
            ) {
                fail('Enter a valid From Email address.');
            }
            if (array_key_exists('smtp_from_name', $values) && mb_strlen($values['smtp_from_name']) > 150) {
                fail('From name is too long.');
            }

            // A blank password field means "keep the current password".
            if (array_key_exists('smtp_pass', $values) && $values['smtp_pass'] === '') {
                unset($values['smtp_pass']);
            }
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
        require_admin();
        $email = sval(request_body(), 'email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Enter a valid email address.');
        }
        if (!smtp_configured()) {
            fail('SMTP is not configured. Save your SMTP settings first.');
        }
        $website = get_setting('website_name', 'Simple Online POS') ?: 'Simple Online POS';
        try {
            $result = send_pos_email(
                $email,
                $website . ' email test',
                "This is a test email from " . $website . ".\n\nIf you received this, your SMTP settings are working."
            );
        } catch (ApiException $ex) {
            throw $ex;
        } catch (Throwable $ex) {
            fail('Email could not be sent: ' . $ex->getMessage(), 502);
        }
        error_log('POS test email accepted for ' . $email . ' via ' . ($result['method'] ?? 'smtp') . '.');
        json_ok(
            ['method' => $result['method'] ?? 'smtp'],
            'Test email was accepted by the mail server for ' . $email . '. Check your spam folder if it does not arrive.'
        );
    }

    fail('Unknown settings action.', 404);
});
