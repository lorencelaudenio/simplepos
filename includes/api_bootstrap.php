<?php
declare(strict_types=1);

/** Bootstrap and guard for every /api endpoint. */

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please log in again.']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !verify_csrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

function api_route(callable $handler): void
{
    try {
        $handler();
    } catch (ApiException $ex) {
        json_out(['ok' => false, 'error' => $ex->getMessage()], $ex->httpStatus);
    } catch (Throwable $ex) {
        error_log('POS API error: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
        json_out(['ok' => false, 'error' => 'Unable to complete the request. Please try again.'], 500);
    }
}
