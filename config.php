<?php
/**
 * Load .env and provide app config. Keys are trimmed (supports "Key = value").
 */
$envPath = __DIR__ . '/.env';
if (!is_file($envPath)) {
    throw new RuntimeException('.env file not found');
}
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0) {
        continue;
    }
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (!array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Force Los Angeles time for all PHP date/time — overrides server timezone so DB gets correct timestamps
date_default_timezone_set('America/Los_Angeles');

function env($key, $default = null)
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

define('APP_ENV', env('APP_ENV', 'development'));
define('APP_URL', rtrim(env('APP_URL', 'http://localhost'), '/'));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', ''));
define('DB_USER', env('DB_USER', ''));
define('DB_PASSWORD', env('DB_PASSWORD', ''));
define('STRIPE_PUBLIC_KEY', trim(env('StripePublicKey', '') ?: env('STRIPE_PUBLIC_KEY', '')));
define('STRIPE_SECRET_KEY', trim(env('StripeSecretKey', '') ?: env('STRIPE_SECRET_KEY', '')));
define('ADMIN_USER', env('ADMIN_USER', 'admin'));
define('ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD_HASH', ''));

define('GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET', ''));
define('GOOGLE_REDIRECT_URL', APP_URL . '/admin/google-callback');
define('ADMIN_WHITELIST', env('ADMIN_WHITELIST', 'fng@crosspointchurchsv.org'));


