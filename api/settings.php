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

    if ($action === 'get') {
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = get_setting($key);
        }
        // Never expose the stored SMTP password to the browser.
        $settings['smtp_pass_configured'] = $settings['smtp_pass'] !== '';
        $settings['smtp_pass'] = '';
        json_ok(['settings' => $settings]);
    }

    if ($action === 'save') {
        $body = request_body();
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = sval($body, $key);
        }
        if ($values['store_name'] === '' || strlen($values['store_name']) > 150) {
            fail('Store name is required (150 characters maximum).');
        }
        if (!ctype_digit($values['smtp_port']) || (int)$values['smtp_port'] < 1 || (int)$values['smtp_port'] > 65535) {
            fail('Enter a valid SMTP port.');
        }
        if (!in_array($values['smtp_secure'], ['tls', 'ssl', 'none'], true)) {
            fail('Invalid SMTP security option.');
        }
        if (strlen($values['smtp_host']) > 200 || strlen($values['smtp_user']) > 200 || strlen($values['smtp_from']) > 200) {
            fail('SMTP fields are too long.');
        }
        // A blank password field means "keep the current password".
        if ($values['smtp_pass'] === '') {
            unset($values['smtp_pass']);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                set_setting($key, $value);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        json_ok([], 'Settings saved successfully.');
    }

    fail('Unknown settings action.', 404);
});
