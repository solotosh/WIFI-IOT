<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAdmin();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$config = require __DIR__ . '/config.php';

function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$apiKey = (string) ($config['api_key'] ?? '');
$receiverUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'chocolate-weasel-838490.hostingersite.com') . '/receiver.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Device Setup · WiFi Scanner</title>
    <style>
        :root { font-family: Inter, system-ui, sans-serif; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; min-height: 100vh; background: #eef2f7; }
        .card { max-width: 760px; margin: 40px auto; background: #fff; border-radius: 16px; box-shadow: 0 12px 35px rgba(23,32,51,.1); overflow: hidden; }
        header { padding: 24px; color: #fff; background: linear-gradient(135deg, #172033, #253b67); }
        h1 { margin: 0 0 8px; font-size: 1.55rem; }
        header a { color: #fff; font-weight: 700; }
        .header-row { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
        .logout { margin: 0; }
        .logout button { background: rgba(255,255,255,.16); }
        .account-link { display: inline-block; margin-left: 12px; }
        main { padding: 24px; }
        .field { margin-bottom: 20px; }
        label { display: block; margin-bottom: 7px; font-weight: 700; }
        .copy-row { display: flex; gap: 8px; }
        input { flex: 1; min-width: 0; padding: 12px; border: 1px solid #cbd4e1; border-radius: 9px; font: 14px ui-monospace, monospace; }
        button { border: 0; padding: 11px 16px; border-radius: 9px; background: #244a90; color: #fff; font-weight: 700; cursor: pointer; }
        .notice { padding: 13px; border-radius: 10px; background: #fff3d6; color: #765100; }
        #status { min-height: 1.3em; color: #176c38; font-weight: 700; }
        @media (max-width: 600px) { body { padding: 0; } .card { margin: 0; min-height: 100vh; border-radius: 0; } .copy-row { flex-direction: column; } }
    </style>
</head>
<body>
<div class="card">
    <header>
        <div class="header-row"><h1>ESP32 Device Setup</h1><form class="logout" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>"><button type="submit">Log out</button></form></div>
        <a href="index.php">← Back to dashboard</a><a class="account-link" href="change-password.php">Change password</a>
    </header>
    <main>
        <p class="notice">Treat the API key like a password. Anyone who has it can submit scan data.</p>
        <div class="field">
            <label for="receiver">Receiver URL</label>
            <div class="copy-row"><input id="receiver" value="<?= escape($receiverUrl) ?>" readonly><button type="button" data-copy="receiver">Copy</button></div>
        </div>
        <div class="field">
            <label for="api-key">API key</label>
            <div class="copy-row"><input id="api-key" type="password" value="<?= escape($apiKey) ?>" readonly><button type="button" id="toggle">Show</button><button type="button" data-copy="api-key">Copy</button></div>
        </div>
        <p id="status" role="status" aria-live="polite"></p>
    </main>
</div>
<script>
const status = document.getElementById('status');
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copy);
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Copied to clipboard.';
        } catch {
            input.select();
            document.execCommand('copy');
            status.textContent = 'Copied to clipboard.';
        }
    });
});
document.getElementById('toggle').addEventListener('click', (event) => {
    const input = document.getElementById('api-key');
    input.type = input.type === 'password' ? 'text' : 'password';
    event.currentTarget.textContent = input.type === 'password' ? 'Show' : 'Hide';
});
</script>
</body>
</html>
