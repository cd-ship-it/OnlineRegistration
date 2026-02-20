<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_GET['code'])) {
    die('No code provided');
}

$code = $_GET['code'];

// Exchange code for token
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URL,
    'grant_type' => 'authorization_code'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['access_token'])) {
    die('Failed to get access token: ' . $response);
}

$accessToken = $data['access_token'];

// Get user info
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfoResponse = curl_exec($ch);
curl_close($ch);

$userInfo = json_decode($userInfoResponse, true);

if (!isset($userInfo['email'])) {
    die('Failed to get user email');
}

$email = $userInfo['email'];
$whitelist = array_map('trim', explode(',', ADMIN_WHITELIST));

if (in_array($email, $whitelist)) {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user'] = $email;
    header('Location: ' . APP_URL . '/admin/settings');
    exit;
} else {
    die('Access denied for email: ' . htmlspecialchars($email));
}

