<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAdmin();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Robots-Tag: noindex, nofollow');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    $config = require __DIR__ . '/config.php';

    if (!validCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (!password_verify($currentPassword, (string) ($config['admin_password_hash'] ?? ''))) {
        $error = 'The current password is incorrect.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'The new password must contain at least 12 characters.';
    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword)
        || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $error = 'Use uppercase, lowercase, a number, and a special character.';
    } elseif ($newPassword !== $confirmation) {
        $error = 'The new passwords do not match.';
    } elseif (password_verify($newPassword, (string) ($config['admin_password_hash'] ?? ''))) {
        $error = 'Choose a password different from the current password.';
    } else {
        $config['admin_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $contents = "<?php\nreturn " . var_export($config, true) . ";\n";
        $temporary = tempnam(__DIR__, 'config_');
        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
            $error = 'The password could not be saved. Please try again.';
        } else {
            @chmod($temporary, 0600);
            if (!rename($temporary, __DIR__ . '/config.php')) {
                @unlink($temporary);
                $error = 'The password could not be saved. Please try again.';
            } else {
                $_SESSION = [];
                session_regenerate_id(true);
                header('Location: login.php?password_changed=1');
                exit;
            }
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
    <title>Change Password · WiFi Scanner</title>
    <style>
        :root { font-family: Inter, system-ui, sans-serif; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #eef2f7; }
        .card { width: min(100%, 500px); background: #fff; border-radius: 18px; box-shadow: 0 20px 55px rgba(23,32,51,.14); overflow: hidden; }
        header { padding: 25px; color: #fff; background: linear-gradient(135deg, #172033, #253b67); }
        h1 { margin: 0 0 7px; font-size: 1.5rem; }
        header a { color: #fff; font-weight: 700; }
        form { padding: 26px; }
        label { display: block; margin: 0 0 7px; font-weight: 700; }
        input { width: 100%; padding: 12px 13px; margin-bottom: 18px; border: 1px solid #cbd4e1; border-radius: 9px; font: inherit; }
        input:focus { outline: 3px solid #dce8ff; border-color: #244a90; }
        button { width: 100%; padding: 12px; border: 0; border-radius: 9px; color: #fff; background: #244a90; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin: 0 0 18px; padding: 12px; border-radius: 9px; background: #ffeded; color: #8c2020; }
        .hint { margin-top: -10px; margin-bottom: 18px; color: #65738a; font-size: .88rem; }
    </style>
</head>
<body>
<section class="card">
    <header><h1>Change administrator password</h1><a href="settings.php">← Back to device setup</a></header>
    <form method="post" action="change-password.php">
        <?php if ($error !== null): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
        <label for="current-password">Current password</label>
        <input id="current-password" name="current_password" type="password" autocomplete="current-password" required autofocus>
        <label for="new-password">New password</label>
        <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="12" required>
        <p class="hint">At least 12 characters with uppercase, lowercase, a number, and a special character.</p>
        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required>
        <button type="submit">Update password</button>
    </form>
</section>
</body>
</html>
