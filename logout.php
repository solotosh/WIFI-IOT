<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('Invalid logout request.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $parameters = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
