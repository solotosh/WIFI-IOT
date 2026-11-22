<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once __DIR__ . '/database.php';

$perPage = 50;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$search = trim((string) ($_GET['q'] ?? ''));
$search = substr($search, 0, 100);
$error = null;
$rows = [];
$total = 0;
$stats = ['scans' => 0, 'observations' => 0, 'access_points' => 0];

try {
    $database = openDatabase();
    $stats = $database->query(
        'SELECT
            (SELECT COUNT(*) FROM scans) AS scans,
            (SELECT COUNT(*) FROM networks) AS observations,
            (SELECT COUNT(DISTINCT bssid) FROM networks) AS access_points'
    )->fetch();

    $where = '';
    $parameters = [];
    if ($search !== '') {
        $where = ' WHERE n.ssid LIKE :search ESCAPE \'\\\' OR n.bssid LIKE :search ESCAPE \'\\\' OR s.device LIKE :search ESCAPE \'\\\'';
        $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $parameters[':search'] = '%' . $escapedSearch . '%';
    }

    $countStatement = $database->prepare('SELECT COUNT(*) FROM networks n JOIN scans s ON s.id = n.scan_id' . $where);
    $countStatement->execute($parameters);
    $total = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $statement = $database->prepare(
        'SELECT n.ssid, n.rssi, n.channel, n.secure, n.security_type, n.bssid, s.device, s.received_at
         FROM networks n JOIN scans s ON s.id = n.scan_id' . $where . '
         ORDER BY s.id DESC, n.rssi DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($parameters as $name => $value) $statement->bindValue($name, $value, PDO::PARAM_STR);
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('WiFi history database error: ' . $exception->getMessage());
    $error = 'The scan history could not be loaded.';
    $totalPages = 1;
}

function pageUrl(int $page, string $search): string {
    return '?' . http_build_query(array_filter(['q' => $search, 'page' => $page], static fn($value) => $value !== ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WiFi Scan History</title>
    <style>
        :root { font-family: Inter, system-ui, sans-serif; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #eef2f7; }
        .card { max-width: 1150px; margin: auto; background: #fff; border-radius: 16px; box-shadow: 0 12px 35px rgba(23,32,51,.1); overflow: hidden; }
        header { padding: 24px; color: #fff; background: linear-gradient(135deg, #172033, #253b67); }
        h1 { margin: 0 0 8px; font-size: 1.55rem; }
        header a { color: #fff; font-weight: 700; }
        main { padding: 24px; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat { padding: 16px; background: #f2f6fd; border-radius: 12px; }
        .stat strong { display: block; font-size: 1.5rem; color: #244a90; }
        .stat span { color: #65738a; font-size: .85rem; }
        form { display: flex; gap: 8px; margin-bottom: 18px; }
        input { flex: 1; min-width: 0; padding: 11px 13px; border: 1px solid #cbd4e1; border-radius: 9px; font: inherit; }
        button, .button { border: 0; padding: 11px 15px; border-radius: 9px; background: #244a90; color: #fff; text-decoration: none; font-weight: 700; cursor: pointer; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 880px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #e5eaf1; text-align: left; }
        th { color: #57657a; font-size: .76rem; text-transform: uppercase; letter-spacing: .05em; }
        tbody tr:hover { background: #f8faff; }
        .badge { display: inline-flex; padding: 4px 9px; border-radius: 999px; color: #fff; font-size: .7rem; font-weight: 700; }
        .secured { background: #21884a; } .open { background: #c0392b; }
        .pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 20px; }
        .disabled { opacity: .4; pointer-events: none; }
        .error { padding: 14px; border-radius: 10px; background: #ffeded; color: #8c2020; }
        .muted { color: #6b778c; }
        .router-link { color: #244a90; font-weight: 700; }
        @media (max-width: 650px) { body { padding: 0; } .card { min-height: 100vh; border-radius: 0; } header, main { padding: 18px; } .stats { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="card">
    <header><h1>WiFi Scan History</h1><a href="index.php">← Back to live scanner</a></header>
    <main>
        <div class="stats">
            <div class="stat"><strong><?= escape($stats['scans'] ?? 0) ?></strong><span>Total scans</span></div>
            <div class="stat"><strong><?= escape($stats['observations'] ?? 0) ?></strong><span>Network observations</span></div>
            <div class="stat"><strong><?= escape($stats['access_points'] ?? 0) ?></strong><span>Unique access points</span></div>
        </div>
        <form method="get" action="history.php">
            <input type="search" name="q" value="<?= escape($search) ?>" placeholder="Search SSID, BSSID, or device" aria-label="Search history">
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?><a class="button" href="history.php">Clear</a><?php endif; ?>
        </form>
        <?php if ($error !== null): ?>
            <p class="error"><?= escape($error) ?></p>
        <?php elseif ($rows === []): ?>
            <p class="muted">No matching WiFi observations found.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Scanned at</th><th>SSID</th><th>Signal</th><th>Channel</th><th>Security</th><th>BSSID</th><th>Device</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $secure = (int) $row['secure'] === 1; ?>
                    <tr>
                        <td><time datetime="<?= escape($row['received_at']) ?>" data-received="<?= escape($row['received_at']) ?>"><?= escape($row['received_at']) ?></time></td>
                        <td><strong><?= escape($row['ssid'] !== '' ? $row['ssid'] : '(Hidden)') ?></strong></td>
                        <td><?= escape($row['rssi']) ?> dBm</td>
                        <td><?= escape($row['channel']) ?></td>
                        <td><span class="badge <?= $secure ? 'secured' : 'open' ?>"><?= $secure ? 'SECURED' : 'OPEN' ?></span></td>
                        <td><a class="router-link" href="router.php?bssid=<?= urlencode((string) $row['bssid']) ?>"><?= escape($row['bssid']) ?></a></td>
                        <td><?= escape($row['device']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <nav class="pagination" aria-label="History pages">
                <a class="button <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= escape(pageUrl($page - 1, $search)) ?>">Previous</a>
                <span>Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> result<?= $total === 1 ? '' : 's' ?></span>
                <a class="button <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= escape(pageUrl($page + 1, $search)) ?>">Next</a>
            </nav>
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
