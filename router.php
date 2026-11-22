<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bandForChannel(int $channel): string {
    return $channel >= 1 && $channel <= 14 ? '2.4 GHz' : '5 / 6 GHz';
}

$bssid = strtoupper(trim((string) ($_GET['bssid'] ?? '')));
$validBssid = preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $bssid) === 1;
$router = null;
$observations = [];
$error = null;

if (!$validBssid) {
    http_response_code(400);
    $error = 'A valid router MAC address is required.';
} else {
    try {
        require_once __DIR__ . '/database.php';
        $database = openDatabase();
        $statement = $database->prepare(
            'SELECT n.ssid, n.channel, n.secure, n.security_type, n.bssid,
                    COUNT(*) AS observation_count, ROUND(AVG(n.rssi), 1) AS average_rssi,
                    MAX(n.rssi) AS best_rssi, MIN(n.rssi) AS worst_rssi,
                    MIN(s.received_at) AS first_seen, MAX(s.received_at) AS last_seen
             FROM networks n JOIN scans s ON s.id = n.scan_id
             WHERE n.bssid = :bssid GROUP BY n.bssid'
        );
        $statement->execute([':bssid' => $bssid]);
        $router = $statement->fetch();

        if ($router === false) {
            $router = null;
            http_response_code(404);
            $error = 'This router has not been recorded.';
        } else {
            $latest = $database->prepare(
                'SELECT n.ssid, n.channel, n.secure, n.security_type
                 FROM networks n WHERE n.bssid = :bssid ORDER BY n.scan_id DESC LIMIT 1'
            );
            $latest->execute([':bssid' => $bssid]);
            $router = array_merge($router, $latest->fetch() ?: []);

            $history = $database->prepare(
                'SELECT rssi, channel, s.received_at
                 FROM networks n JOIN scans s ON s.id = n.scan_id
                 WHERE n.bssid = :bssid ORDER BY n.scan_id DESC LIMIT 200'
            );
            $history->execute([':bssid' => $bssid]);
            $observations = array_reverse($history->fetchAll());
        }
    } catch (Throwable $exception) {
        error_log('Router detail database error: ' . $exception->getMessage());
        $error = 'Router details could not be loaded.';
    }
}

$chartWidth = 900;
$chartHeight = 270;
$paddingLeft = 55;
$paddingTop = 20;
$plotWidth = $chartWidth - $paddingLeft - 20;
$plotHeight = $chartHeight - $paddingTop - 38;
$points = [];
$count = count($observations);
foreach ($observations as $index => $observation) {
    $x = $paddingLeft + ($count > 1 ? ($index / ($count - 1)) * $plotWidth : $plotWidth / 2);
    $rssi = max(-100, min(-20, (int) $observation['rssi']));
    $y = $paddingTop + ((-20 - $rssi) / 80) * $plotHeight;
    $points[] = round($x, 1) . ',' . round($y, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Router Details · WiFi Scanner</title>
    <style>
        :root { font-family: Inter, system-ui, sans-serif; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #eef2f7; }
        .card { max-width: 1050px; margin: auto; background: #fff; border-radius: 16px; box-shadow: 0 12px 35px rgba(23,32,51,.1); overflow: hidden; }
        header { padding: 24px; color: #fff; background: linear-gradient(135deg, #172033, #253b67); }
        h1 { margin: 0 0 8px; font-size: 1.55rem; }
        header a { color: #fff; font-weight: 700; }
        main { padding: 24px; }
        .details { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; margin-bottom: 24px; }
        .detail { padding: 15px; border-radius: 12px; background: #f2f6fd; }
        .detail span { display: block; color: #65738a; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
        .detail strong { display: block; margin-top: 5px; word-break: break-word; }
        .chart { overflow-x: auto; border: 1px solid #e3e9f2; border-radius: 12px; padding: 12px; }
        svg { display: block; width: 100%; min-width: 680px; height: auto; }
        .grid { stroke: #dce3ed; stroke-width: 1; }
        .axis-label { fill: #66758b; font-size: 12px; }
        .signal-area { fill: url(#signalGradient); opacity: .2; }
        .signal-line { fill: none; stroke: #244a90; stroke-width: 3; stroke-linejoin: round; stroke-linecap: round; }
        .error { padding: 14px; border-radius: 10px; background: #ffeded; color: #8c2020; }
        .legend { color: #65738a; font-size: .9rem; }
        @media (max-width: 760px) { body { padding: 0; } .card { min-height: 100vh; border-radius: 0; } header, main { padding: 18px; } .details { grid-template-columns: repeat(2,minmax(0,1fr)); } }
    </style>
</head>
<body>
<div class="card">
    <header><h1><?= escape($router['ssid'] ?? 'Router details') ?></h1><a href="index.php">Live dashboard</a> · <a href="history.php">Scan history</a></header>
    <main>
    <?php if ($error !== null): ?>
        <p class="error"><?= escape($error) ?></p>
    <?php else: ?>
        <div class="details">
            <div class="detail"><span>MAC address / BSSID</span><strong><?= escape($router['bssid']) ?></strong></div>
            <div class="detail"><span>SSID</span><strong><?= escape($router['ssid'] !== '' ? $router['ssid'] : '(Hidden)') ?></strong></div>
            <div class="detail"><span>Channel and band</span><strong><?= escape($router['channel']) ?> · <?= escape(bandForChannel((int) $router['channel'])) ?></strong></div>
            <div class="detail"><span>Security</span><strong><?= escape($router['security_type']) ?></strong></div>
            <div class="detail"><span>Observations</span><strong><?= escape($router['observation_count']) ?></strong></div>
            <div class="detail"><span>Average signal</span><strong><?= escape($router['average_rssi']) ?> dBm</strong></div>
            <div class="detail"><span>Signal range</span><strong><?= escape($router['worst_rssi']) ?> to <?= escape($router['best_rssi']) ?> dBm</strong></div>
            <div class="detail"><span>First / last seen</span><strong><time data-date="<?= escape($router['first_seen']) ?>"><?= escape($router['first_seen']) ?></time><br><time data-date="<?= escape($router['last_seen']) ?>"><?= escape($router['last_seen']) ?></time></strong></div>
        </div>
        <h2>Signal strength over time</h2>
        <p class="legend">Higher values (closer to −20 dBm) indicate a stronger signal. Showing up to the latest 200 observations.</p>
        <div class="chart">
            <?php if ($observations === []): ?><p>No signal observations are available.</p><?php else: ?>
            <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="Signal strength history chart">
                <defs><linearGradient id="signalGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#27ae60"/><stop offset="1" stop-color="#244a90"/></linearGradient></defs>
                <?php foreach ([-20,-40,-60,-80,-100] as $tick): $y = $paddingTop + ((-20 - $tick) / 80) * $plotHeight; ?>
                    <line class="grid" x1="<?= $paddingLeft ?>" y1="<?= $y ?>" x2="<?= $paddingLeft + $plotWidth ?>" y2="<?= $y ?>"/>
                    <text class="axis-label" x="5" y="<?= $y + 4 ?>"><?= $tick ?> dBm</text>
                <?php endforeach; ?>
                <?php $areaPoints = $paddingLeft . ',' . ($paddingTop + $plotHeight) . ' ' . implode(' ', $points) . ' ' . ($paddingLeft + $plotWidth) . ',' . ($paddingTop + $plotHeight); ?>
                <polygon class="signal-area" points="<?= escape($areaPoints) ?>"/>
                <polyline class="signal-line" points="<?= escape(implode(' ', $points)) ?>"/>
                <text class="axis-label" x="<?= $paddingLeft ?>" y="<?= $chartHeight - 5 ?>"><?= escape($observations[0]['received_at']) ?></text>
                <text class="axis-label" text-anchor="end" x="<?= $paddingLeft + $plotWidth ?>" y="<?= $chartHeight - 5 ?>"><?= escape($observations[$count - 1]['received_at']) ?></text>
            </svg>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </main>
</div>
<script>document.querySelectorAll('time[data-date]').forEach((el) => { const date = new Date(el.dataset.date); if (!Number.isNaN(date.getTime())) el.textContent = date.toLocaleString(); });</script>
</body>
</html>
