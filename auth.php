<?php
declare(strict_types=1);

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $sessionPath = __DIR__ . '/.sessions';
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Unable to create the session directory.');
    }
    session_save_path($sessionPath);
    session_name('wifi_scanner_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isAdminAuthenticated(): bool
{
    startSecureSession();
    return ($_SESSION['admin_authenticated'] ?? false) === true;
}

function requireAdmin(): void
{
    if (isAdminAuthenticated()) return;
    $destination = basename((string) ($_SERVER['REQUEST_URI'] ?? 'settings.php'));
    header('Location: login.php?' . http_build_query(['next' => $destination]));
    exit;
}

function csrfToken(): string
{
    startSecureSession();
    if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function validCsrfToken(string $token): bool
{
    return $token !== '' && hash_equals(csrfToken(), $token);
}

function safeDestination(string $destination): string
{
    $allowed = ['settings.php', 'change-password.php'];
    $path = parse_url($destination, PHP_URL_PATH);
    $file = is_string($path) ? basename($path) : '';
    return in_array($file, $allowed, true) ? $file : 'settings.php';
}
