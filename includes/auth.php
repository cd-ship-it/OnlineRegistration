<?php
/**
 * Admin auth: session-based. Check with require_admin().
 */
if (!defined('APP_URL')) {
    require_once dirname(__DIR__) . '/config.php';
}

function require_admin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: ' . APP_URL . '/admin', true, 302);
        exit;
    }
    csrf_generate(); // ensure a token exists before the page renders
}

// ---------------------------------------------------------------------------
// CSRF helpers
// ---------------------------------------------------------------------------

/**
 * Return the session's CSRF token, generating one if it does not yet exist.
 * Starts the session automatically when needed.
 */
function csrf_generate(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/**
 * Return an HTML hidden input containing the current CSRF token.
 * Drop <?= csrf_input() ?> inside every admin POST form.
 */
function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_generate(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Pure boolean check: does $_POST['csrf_token'] match the session token?
 * Uses hash_equals to prevent timing attacks.
 */
function csrf_is_valid(): bool
{
    return !empty($_SESSION['csrf_token'])
        && !empty($_POST['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

/**
 * Abort with HTTP 403 if the CSRF token is missing or wrong.
 * Call this as the first line of every admin POST handler.
 */
function csrf_verify(): void
{
    if (!csrf_is_valid()) {
        http_response_code(403);
        exit('Forbidden — invalid CSRF token.');
    }
}

function admin_login($username, $password) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $expected_user = defined('ADMIN_USER') ? ADMIN_USER : 'admin';
    if ($username !== $expected_user) {
        return false;
    }
    $hash = defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== '' ? ADMIN_PASSWORD_HASH : null;
    if ($hash === null) {
        require_once __DIR__ . '/db.php';
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'admin_password_hash'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $hash = $row ? $row['value'] : '';
    }
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user'] = $username;
    return true;
}

function admin_logout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
