<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
startSecureSession();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Robots-Tag: noindex, nofollow');

$next = safeDestination((string) ($_GET['next'] ?? $_POST['next'] ?? 'settings.php'));
if (isAdminAuthenticated()) {
    header('Location: ' . $next);
    exit;
}

$error = null;
$now = time();
$lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($lockedUntil > $now) {
        $error = 'Too many attempts. Try again in ' . ($lockedUntil - $now) . ' seconds.';
    } else {
        $config = require __DIR__ . '/config.php';
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $validUsername = hash_equals((string) ($config['admin_username'] ?? ''), $username);
        $validPassword = password_verify($password, (string) ($config['admin_password_hash'] ?? ''));

        if ($validUsername && $validPassword) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_username'] = $username;
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            header('Location: ' . $next);
            exit;
        }

        $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_locked_until'] = $now + 60;
            $_SESSION['login_attempts'] = 0;
            $error = 'Too many attempts. Login is locked for 60 seconds.';
        } else {
            $error = 'Incorrect username or password.';
        }
    }
}

function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator Login · WiFi Scanner</title>
    <style>
        :root { font-family: Inter, system-ui, sans-serif; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: linear-gradient(135deg, #e8eef7, #f7f9fc); }
        .login { width: min(100%, 430px); background: #fff; border-radius: 18px; box-shadow: 0 20px 55px rgba(23,32,51,.14); overflow: hidden; }
        header { padding: 28px; color: #fff; background: linear-gradient(135deg, #172033, #253b67); text-align: center; }
        .icon { font-size: 2.4rem; }
        h1 { margin: 8px 0 5px; font-size: 1.5rem; }
        header p { margin: 0; color: #d9e4f7; }
        form { padding: 28px; }
        label { display: block; margin: 0 0 7px; font-weight: 700; }
        input { width: 100%; padding: 12px 13px; margin-bottom: 18px; border: 1px solid #cbd4e1; border-radius: 9px; font: inherit; }
        input:focus { outline: 3px solid #dce8ff; border-color: #244a90; }
        button { width: 100%; padding: 12px; border: 0; border-radius: 9px; color: #fff; background: #244a90; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #193c7b; }
        .error, .success { margin: 0 0 18px; padding: 12px; border-radius: 9px; }
        .error { background: #ffeded; color: #8c2020; }
        .success { background: #e7f8ed; color: #176c38; }
        .back { display: block; margin-top: 18px; color: #244a90; text-align: center; font-weight: 700; }
    </style>
</head>
<body>
<section class="login">
    <header><div class="icon">📶</div><h1>Administrator Login</h1><p>WiFi Scanner device setup</p></header>
    <form method="post" action="login.php" autocomplete="on">
        <?php if (isset($_GET['password_changed'])): ?><p class="success" role="status">Password changed successfully. Sign in with your new password.</p><?php endif; ?>
        <?php if ($error !== null): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
        <input type="hidden" name="next" value="<?= escape($next) ?>">
        <label for="username">Username</label>
        <input id="username" name="username" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
        <a class="back" href="index.php">← Back to dashboard</a>
    </form>
</section>
</body>
</html>
