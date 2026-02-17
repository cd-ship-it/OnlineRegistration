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
