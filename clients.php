<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
function escape(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$error = null; $scan = null; $clients = []; $uniqueCount = 0;
try {
    require_once __DIR__ . '/database.php';
    $database = openDatabase();
    $scan = $database->query('SELECT * FROM client_scans ORDER BY id DESC LIMIT 1')->fetch();
    $uniqueCount = (int) $database->query('SELECT COUNT(DISTINCT mac_address) FROM client_observations')->fetchColumn();
    if ($scan === false) { $scan = null; $error = 'Waiting for the first passive monitor upload.'; }
    else {
        $statement = $database->prepare('SELECT mac_address, rssi, packet_count, first_seen_ms, last_seen_ms FROM client_observations WHERE client_scan_id = :scan_id ORDER BY rssi DESC');
        $statement->execute([':scan_id' => $scan['id']]);
        $clients = $statement->fetchAll();
    }
} catch (Throwable $exception) { error_log('Client dashboard error: ' . $exception->getMessage()); $error = 'Client observations could not be loaded.'; }
function isRandomizedMac(string $mac): bool { $first = hexdec(substr($mac, 0, 2)); return ($first & 2) === 2; }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="10"><title>Observed Devices · WiFi Scanner</title>
<style>
:root{font-family:Inter,system-ui,sans-serif;color:#172033}*{box-sizing:border-box}body{margin:0;padding:24px;background:#eef2f7}.card{max-width:1050px;margin:auto;background:#fff;border-radius:16px;box-shadow:0 12px 35px rgba(23,32,51,.1);overflow:hidden}header{padding:24px;color:#fff;background:linear-gradient(135deg,#172033,#253b67)}h1{margin:0 0 8px;font-size:1.55rem}header a{color:#fff;font-weight:700}main{padding:24px}.summary{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}.pill{padding:8px 12px;border-radius:999px;background:#edf3ff;color:#244a90;font-weight:700}.notice,.error{padding:13px;border-radius:10px}.notice{background:#fff3d6;color:#765100}.error{background:#ffeded;color:#8c2020}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:720px}th,td{padding:12px 10px;border-bottom:1px solid #e5eaf1;text-align:left}th{color:#57657a;font-size:.76rem;text-transform:uppercase}.random{display:inline-flex;padding:4px 8px;border-radius:999px;background:#f1e9ff;color:#65409a;font-size:.72rem;font-weight:700}@media(max-width:600px){body{padding:0}.card{min-height:100vh;border-radius:0}header,main{padding:18px}}
</style></head><body><div class="card"><header><h1>Observed devices</h1><a href="index.php">Live WiFi dashboard</a> · <a href="history.php">WiFi history</a></header><main>
<p class="notice">Passive metadata only: MAC address, signal level and packet count. No payload contents are captured. Use only on networks you own or administer.</p>
<?php if ($error !== null): ?><p class="error"><?= escape($error) ?></p><?php else: ?>
<div class="summary"><span class="pill"><?= count($clients) ?> devices this cycle</span><span class="pill"><?= $uniqueCount ?> unique devices recorded</span><span class="pill">Router: <?= escape($scan['target_ssid']) ?> · <?= escape($scan['target_bssid']) ?> · Ch <?= escape($scan['channel']) ?></span><span class="pill">Received <time data-date="<?= escape($scan['received_at']) ?>"><?= escape($scan['received_at']) ?></time></span></div>
<?php if ($clients === []): ?><p>No client data frames were observed during this cycle.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>#</th><th>Device MAC</th><th>Signal</th><th>Packets observed</th><th>Address type</th></tr></thead><tbody>
<?php foreach ($clients as $i => $client): ?><tr><td><?= $i + 1 ?></td><td><strong><?= escape($client['mac_address']) ?></strong></td><td><?= escape($client['rssi']) ?> dBm</td><td><?= escape($client['packet_count']) ?></td><td><?= isRandomizedMac($client['mac_address']) ? '<span class="random">Private/randomized</span>' : 'Globally assigned' ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?><?php endif; ?></main></div><script>document.querySelectorAll('time[data-date]').forEach(el=>{const d=new Date(el.dataset.date);if(!Number.isNaN(d.getTime()))el.textContent=d.toLocaleString()});</script></body></html>
