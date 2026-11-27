<?php
declare(strict_types=1);

/**
 * Database configuration.
 *
 * Credentials may be set here, in config/database.local.php (written by install.php),
 * or through environment variables. config/ is not web-accessible (see .htaccess).
 */
$localConfig = __DIR__ . DIRECTORY_SEPARATOR . 'database.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('POS_DB_HOST') ?: 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('POS_DB_NAME') ?: 'simple_pos');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('POS_DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('POS_DB_PASS') !== false ? getenv('POS_DB_PASS') : '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

function db_dsn(bool $withDatabase = true): string
{
    $dsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
    if ($withDatabase) {
        $dsn .= ';dbname=' . DB_NAME;
    }
    return $dsn;
}

function db_connect(bool $withDatabase = true): PDO
{
    static $connections = [];
    $key = $withDatabase ? 'database' : 'server';
    if (isset($connections[$key])) {
        return $connections[$key];
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
    $connections[$key] = new PDO(db_dsn($withDatabase), DB_USER, DB_PASS, $options);
    return $connections[$key];
}

function db(): PDO
{
    return db_connect(true);
}
