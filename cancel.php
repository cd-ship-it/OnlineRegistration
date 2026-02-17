<?php
require_once __DIR__ . '/config.php';
header('Location: ' . APP_URL . '/register?cancelled=1', true, 302);
exit;
