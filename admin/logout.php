<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
admin_logout();
header('Location: ' . APP_URL . '/admin', true, 302);
exit;
