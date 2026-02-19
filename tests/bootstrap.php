<?php
/**
 * PHPUnit bootstrap — loaded once before any test runs.
 *
 * Defines the DB constants expected by includes/db.php so that config.php
 * (which reads from .env) is bypassed.  All values target the local dev
 * MySQL instance used by the existing test_pricing.php script.
 *
 * Run the pending migration before executing the suite:
 *   mysql -u root -proot crossp11_db1 < migrations/add_confirmation_email_sent.sql
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// --- Database connection constants (mirror test_pricing.php) ---------------
if (!defined('DB_HOST'))     define('DB_HOST',     '127.0.0.1');
if (!defined('DB_PORT'))     define('DB_PORT',     '8889');
if (!defined('DB_NAME'))     define('DB_NAME',     'crossp11_db1');
if (!defined('DB_USER'))     define('DB_USER',     'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', 'root');

// --- Other constants referenced by app includes ----------------------------
if (!defined('APP_URL'))     define('APP_URL',     'http://localhost');
if (!defined('APP_ENV'))     define('APP_ENV',     'test');

// --- Load application helpers ----------------------------------------------
// logger.php defines APP_LOG_LEVEL / APP_LOG_FILE and the app_log() function.
// db.php opens the PDO connection and stores it in $GLOBALS['pdo'].
// db_helper.php registers all the helper functions under test.
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/db.php';       // creates $pdo in local scope
require_once dirname(__DIR__) . '/includes/price.php';    // needed by success_get_registration_with_kids
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/auth.php';     // csrf_generate, csrf_input, csrf_is_valid, csrf_verify
require_once dirname(__DIR__) . '/includes/mailer.php';   // payment_finalize_and_notify, send_registration_confirmation_email

// Make the connection available to all test classes via $GLOBALS['pdo'].
// PHPUnit runs bootstrap.php in its own include scope, so the local $pdo
// from db.php would otherwise be invisible to test methods.
/** @var PDO $pdo */
$GLOBALS['pdo'] = $pdo;
