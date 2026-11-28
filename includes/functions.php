<?php
declare(strict_types=1);

/** General helpers shared by pages and API endpoints. */

// Small polyfills so the app also runs on hosts without the mbstring extension.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int
    {
        return strlen($value);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

// PHPMailer is optional; load it when installed so every entry point can send mail.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

class ApiException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($amount): string
{
    return "\u{20B1}" . number_format((float)$amount, 2, '.', ',');
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_ok(array $data = [], string $message = ''): void
{
    json_out(array_merge(['ok' => true, 'message' => $message], $data), 200);
}

function fail(string $message, int $status = 400): void
{
    throw new ApiException($message, $status);
}

/** Shared JSON error wrapper for authenticated and public API endpoints. */
function api_route(callable $handler): void
{
    try {
        $handler();
    } catch (ApiException $ex) {
        json_out(['ok' => false, 'error' => $ex->getMessage()], $ex->httpStatus);
    } catch (Throwable $ex) {
        $ref = strtoupper(bin2hex(random_bytes(3)));
        error_log('POS API error [' . $ref . ']: ' . $ex->getMessage()
            . ' in ' . $ex->getFile() . ':' . $ex->getLine());
        json_out([
            'ok' => false,
            'error' => 'Unable to complete the request. Please try again. (Ref: ' . $ref . ')'
        ], 500);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

function request_body(): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($data)) {
            fail('Invalid JSON request body.');
        }
        return $data;
    }
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    $parsed = [];
    if ($raw !== false && $raw !== '') {
        parse_str($raw, $parsed);
    }
    return is_array($parsed) ? $parsed : [];
}

function sval(array $data, string $key, string $default = ''): string
{
    $value = $data[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function ival(array $data, string $key, int $default = 0): int
{
    $value = $data[$key] ?? $default;
    return is_scalar($value) ? (int)$value : $default;
}

function fval(array $data, string $key, float $default = 0.0): float
{
    $value = $data[$key] ?? $default;
    return is_numeric($value) ? (float)$value : $default;
}

function bval(array $data, string $key): bool
{
    $value = $data[$key] ?? false;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function money_val($value): string
{
    return number_format((float)$value, 2, '.', '');
}

/** Reads a setting from the database (cached per request). */
function get_setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['pos_setting_cache']) || !is_array($GLOBALS['pos_setting_cache'])) {
        $GLOBALS['pos_setting_cache'] = [];
    }
    $cache =& $GLOBALS['pos_setting_cache'];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value === false ? $default : (string)$value;
    } catch (Throwable $ex) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function clear_setting_cache(): void
{
    $GLOBALS['pos_setting_cache'] = [];
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    );
    $stmt->execute([$key, $value, $value]);
    unset($GLOBALS['pos_setting_cache'][$key]);
}

function require_admin(): void
{
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        fail('Administrator access is required.', 403);
    }
}

/** POS users are stored with the "cashier" role and shown as Clients in the UI. */
function is_pos_user(): bool
{
    $user = current_user();
    return $user !== null && in_array((string)($user['role'] ?? ''), ['cashier', 'client'], true);
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Website Admin',
        'cashier', 'client' => 'Client',
        default => ucfirst($role),
    };
}

function require_pos_user(): void
{
    if (!is_pos_user()) {
        fail('This area is available to POS client accounts only.', 403);
    }
}

function generate_receipt_number(PDO $pdo): string
{
    $date = date('Ymd');
    $prefix = 'RCPT-' . $date . '-';
    $stmt = $pdo->prepare("SELECT receipt_number FROM sales WHERE receipt_number LIKE ? ORDER BY receipt_number DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)$stmt->fetchColumn();
    $next = 1;
    if ($last !== '') {
        $next = (int)substr($last, strlen($prefix)) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function record_stock_movement(
    PDO $pdo,
    int $productId,
    string $type,
    int $delta,
    int $previousStock,
    int $newStock,
    string $notes = ''
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, movement_type, quantity, previous_stock, new_stock, notes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$productId, $type, $delta, $previousStock, $newStock, mb_substr($notes, 0, 250)]);
}

function low_stock_count(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM products WHERE status = "active" AND stock_quantity <= reorder_level'
    );
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function product_exists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

/** Date-range helper shared by reports and exports. */
function resolve_date_range(string $filter, string $from = '', string $to = ''): array
{
    $timezone = date_default_timezone_get();
    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
    switch ($filter) {
        case 'today':
            return [$now->setTime(0, 0, 0), $now->setTime(23, 59, 59)];
        case 'yesterday':
            $y = $now->modify('-1 day');
            return [$y->setTime(0, 0, 0), $y->setTime(23, 59, 59)];
        case 'week':
            $start = $now->modify('-' . ((int)$now->format('N') - 1) . ' days')->setTime(0, 0, 0);
            return [$start, $now->setTime(23, 59, 59)];
        case 'month':
            return [$now->setDate((int)$now->format('Y'), (int)$now->format('m'), 1)->setTime(0, 0, 0), $now->setTime(23, 59, 59)];
        case 'year':
            return [$now->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0, 0), $now->setTime(23, 59, 59)];
        case 'custom':
            $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $from, new DateTimeZone($timezone));
            $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $to, new DateTimeZone($timezone));
            $errors = DateTimeImmutable::getLastErrors();
            if (!$startDate || !$endDate || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                fail('Please provide a valid custom date range.');
            }
            $start = $startDate->setTime(0, 0, 0);
            $end = $endDate->setTime(23, 59, 59);
            if ($start > $end) {
                fail('Start date must not be after end date.');
            }
            return [$start, $end];
        case 'all':
            return [new DateTimeImmutable('2000-01-01', new DateTimeZone($timezone)), $now->setTime(23, 59, 59)];
        default:
            fail('Unknown date filter.');
    }
}

function sales_where(array $range): array
{
    return [
        'sql' => 'created_at BETWEEN ? AND ?',
        'params' => [$range[0]->format('Y-m-d H:i:s'), $range[1]->format('Y-m-d H:i:s')],
    ];
}

function smtp_configured(): bool
{
    return get_setting('smtp_host') !== '';
}

/** Sends email with PHPMailer when installed, native SMTP otherwise. */
function send_pos_email(
    string $to,
    string $subject,
    string $body,
    ?string $filename = null,
    ?string $attachment = null,
    string $mimeType = 'application/sql'
): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        fail('Please enter a valid email address.');
    }
    if (!smtp_configured()) {
        fail('Email delivery is not configured. Ask the administrator to set up SMTP.', 500);
    }

    $host = get_setting('smtp_host');
    $port = (int)(get_setting('smtp_port', '587') ?: 587);
    $secure = strtolower(get_setting('smtp_secure', 'tls'));
    $username = get_setting('smtp_user');
    $password = get_setting('smtp_pass');
    $from = get_setting('smtp_from') ?: $username;
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        fail('The From Email is missing or invalid. Set a valid sender address under Website Settings.', 500);
    }
    $fromName = get_setting('smtp_from_name', 'Simple Online POS');

    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPSecure = match ($secure) {
            'ssl' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
            default => false,
        };
        $mail->SMTPAuth = $username !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        if ($filename !== null && $attachment !== null) {
            $mail->addStringAttachment($attachment, $filename, 'base64', $mimeType);
        }
        $mail->send();
        return ['method' => 'phpmailer'];
    }

    require_once __DIR__ . '/SmtpMailer.php';
    $mailer = new SmtpMailer();
    $mailer->host = $host;
    $mailer->port = $port;
    $mailer->secure = $secure;
    $mailer->username = $username;
    $mailer->password = $password;
    $mailer->from = $from;
    $mailer->fromName = $fromName;
    $mailer->to = $to;
    $mailer->subject = $subject;
    $mailer->body = $body;
    if ($filename !== null && $attachment !== null) {
        $mailer->addAttachment($filename, $attachment, $mimeType);
    }
    $mailer->send();
    return ['method' => 'builtin-smtp'];
}

/** Sends a generated backup file by email. */
function send_backup_email(
    string $to,
    string $subject,
    string $body,
    string $filename,
    string $sql,
    string $mimeType = 'application/sql'
): array {
    return send_pos_email($to, $subject, $body, $filename, $sql, $mimeType);
}

function upload_dir(string $name): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
