<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$scan = null;
try {
    require_once __DIR__ . '/database.php';
    $database = openDatabase();
    $scan = $database->query('SELECT id, device, uptime_ms, received_at FROM scans ORDER BY id DESC LIMIT 1')->fetch();
    if ($scan === false) {
        $scan = null;
        $error = 'Waiting for the first ESP32 scan.';
        $networks = [];
    } else {
        $statement = $database->prepare('SELECT ssid, rssi, channel, secure, security_type, bssid FROM networks WHERE scan_id = :scan_id ORDER BY rssi DESC');
        $statement->execute([':scan_id' => $scan['id']]);
        $networks = $statement->fetchAll();
    }
} catch (Throwable $exception) {
    error_log('WiFi dashboard database error: ' . $exception->getMessage());
    $error = 'The latest scan data could not be read.';
    $networks = [];
}
$receivedAt = isset($scan['received_at']) ? strtotime((string) $scan['received_at']) : false;
$ageSeconds = $receivedAt === false ? null : max(0, time() - $receivedAt);
$isStale = $ageSeconds === null || $ageSeconds > 30;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WiFi Scanner</title>
    <meta http-equiv="refresh" content="5">
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; color: #172033; padding: 24px; }
        .card { background: #fff; max-width: 1050px; margin: auto; border-radius: 16px; box-shadow: 0 12px 35px rgba(23,32,51,.1); overflow: hidden; }
        header { padding: 24px; background: linear-gradient(135deg, #172033, #253b67); color: #fff; }
        h1 { margin: 0 0 8px; font-size: 1.55rem; }
        header p { margin: 0; color: #d9e4f7; }
        header a { color: #fff; font-weight: 700; }
        main { padding: 24px; }
        .summary { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
        .pill, .badge { display: inline-flex; align-items: center; border-radius: 999px; font-weight: 700; }
        .pill { padding: 8px 12px; background: #edf3ff; color: #244a90; }
        .live { background: #e7f8ed; color: #176c38; }
        .stale { background: #fff3d6; color: #885c00; }
        .error { padding: 14px; border-radius: 10px; background: #ffeded; color: #8c2020; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; }
        th, td { padding: 13px 12px; border-bottom: 1px solid #e5eaf1; text-align: left; }
        th { color: #57657a; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; }
        tbody tr:hover { background: #f8faff; }
        .badge { padding: 4px 9px; color: #fff; font-size: .72rem; }
        .open { background: #c0392b; } .secured { background: #21884a; }
        .bar { width: 110px; height: 7px; margin-top: 5px; background: #e5eaf1; border-radius: 9px; overflow: hidden; }
        .fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #e74c3c, #f1c40f 55%, #27ae60); }
        .muted { color: #6b778c; }
        .router-link { color: #244a90; font-weight: 700; }
        @media (max-width: 600px) { body { padding: 0; } .card { border-radius: 0; min-height: 100vh; } header, main { padding: 18px; } }
    </style>
</head>
<body>
<div class="card">
    <header>
        <h1>TOSH IOT SANDBOX DASHBOARD</h1>
        <p>Nearby access points reported by <?= escape($scan['device'] ?? 'ESP32') ?> · <a href="history.php">View scan history</a> ·
         <a href="settings.php">Access  API</a></p>
    </header>
    <main>
        <?php if ($error !== null): ?>
            <p class="error"><?= escape($error) ?></p>
        <?php else: ?>
            <div class="summary">
                <span class="pill"><?= count($networks) ?> network<?= count($networks) === 1 ? '' : 's' ?></span>
                <span class="pill <?= $isStale ? 'stale' : 'live' ?>"><?= $isStale ? 'Data may be stale' : 'Live' ?></span>
                <span class="muted">Received <time datetime="<?= escape($scan['received_at'] ?? '') ?>" data-received="<?= escape($scan['received_at'] ?? '') ?>"><?= escape($scan['received_at'] ?? 'unknown') ?></time></span>
            </div>
            <?php if ($networks === []): ?>
                <p class="muted">No nearby networks were found in the latest scan.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>#</th><th>SSID</th><th>Signal</th><th>Channel</th><th>Security</th><th>BSSID</th></tr></thead>
                    <tbody>
                    <?php foreach ($networks as $index => $network):
                        $rssi = max(-120, min(0, (int) ($network['rssi'] ?? -120)));
                        $percent = max(0, min(100, (int) round(($rssi + 100) * 2)));
                        $secure = (int) ($network['secure'] ?? 0) === 1;
                    ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong><?= escape(($network['ssid'] ?? '') !== '' ? $network['ssid'] : '(Hidden)') ?></strong></td>
                            <td><?= $rssi ?> dBm<div class="bar" aria-label="Signal strength <?= $percent ?> percent"><div class="fill" style="width:<?= $percent ?>%"></div></div></td>
                            <td><?= escape((int) ($network['channel'] ?? 0)) ?></td>
                            <td><span class="badge <?= $secure ? 'secured' : 'open' ?>"><?= $secure ? 'SECURED' : 'OPEN' ?></span></td>
                            <td><a class="router-link" href="router.php?bssid=<?= urlencode((string) ($network['bssid'] ?? '')) ?>"><?= escape($network['bssid'] ?? '') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script>
document.querySelectorAll('time[data-received]').forEach((element) => {
    const date = new Date(element.dataset.received);
    if (!Number.isNaN(date.getTime())) element.textContent = date.toLocaleString();
});
</script>
</body>
</html>
