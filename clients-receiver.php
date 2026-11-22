<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respond(int $status, array $body): never { http_response_code($status); echo json_encode($body); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); respond(405, ['ok' => false, 'error' => 'Method not allowed']); }
$config = require __DIR__ . '/config.php';
if (!hash_equals((string) ($config['api_key'] ?? ''), (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''))) respond(401, ['ok' => false, 'error' => 'Unauthorized']);
if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') respond(415, ['ok' => false, 'error' => 'Content-Type must be application/json']);
$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || $raw === '' || strlen($raw) > 65536) respond(400, ['ok' => false, 'error' => 'Invalid request body']);
try { $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (JsonException $exception) { respond(400, ['ok' => false, 'error' => 'Malformed JSON']); }
$clients = $payload['clients'] ?? null;
$ssid = (string) ($payload['target_ssid'] ?? '');
$bssid = strtoupper((string) ($payload['target_bssid'] ?? ''));
$channel = filter_var($payload['channel'] ?? null, FILTER_VALIDATE_INT);
if (!is_array($clients) || count($clients) > 200 || strlen($ssid) > 128 || preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $bssid) !== 1 || $channel === false || $channel < 1 || $channel > 196) respond(422, ['ok' => false, 'error' => 'Invalid monitor metadata']);
$cleanClients = [];
foreach ($clients as $client) {
    if (!is_array($client)) respond(422, ['ok' => false, 'error' => 'Invalid client entry']);
    $mac = strtoupper((string) ($client['mac_address'] ?? ''));
    $rssi = filter_var($client['rssi'] ?? null, FILTER_VALIDATE_INT);
    $packetCount = filter_var($client['packet_count'] ?? null, FILTER_VALIDATE_INT);
    if (preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) !== 1 || $mac === $bssid || $rssi === false || $rssi < -120 || $rssi > 0 || $packetCount === false || $packetCount < 1) respond(422, ['ok' => false, 'error' => 'Invalid client values']);
    $cleanClients[] = ['mac' => $mac, 'rssi' => $rssi, 'packet_count' => min($packetCount, 1000000), 'first_seen_ms' => max(0, (int) ($client['first_seen_ms'] ?? 0)), 'last_seen_ms' => max(0, (int) ($client['last_seen_ms'] ?? 0))];
}
require_once __DIR__ . '/database.php';
try {
    $database = openDatabase(); $database->beginTransaction();
    $scan = $database->prepare('INSERT INTO client_scans (device, target_ssid, target_bssid, channel, received_at) VALUES (:device, :ssid, :bssid, :channel, :received_at)');
    $scan->execute([':device' => substr((string) ($payload['device'] ?? 'ESP32'), 0, 64), ':ssid' => $ssid, ':bssid' => $bssid, ':channel' => $channel, ':received_at' => gmdate('c')]);
    $scanId = (int) $database->lastInsertId();
    $insert = $database->prepare('INSERT INTO client_observations (client_scan_id, mac_address, rssi, packet_count, first_seen_ms, last_seen_ms) VALUES (:scan_id, :mac, :rssi, :packets, :first_seen, :last_seen)');
    foreach ($cleanClients as $client) $insert->execute([':scan_id' => $scanId, ':mac' => $client['mac'], ':rssi' => $client['rssi'], ':packets' => $client['packet_count'], ':first_seen' => $client['first_seen_ms'], ':last_seen' => $client['last_seen_ms']]);
    $database->exec('DELETE FROM client_scans WHERE id NOT IN (SELECT id FROM client_scans ORDER BY id DESC LIMIT 1000)');
    $database->commit();
} catch (Throwable $exception) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    error_log('Client monitor database error: ' . $exception->getMessage());
    respond(500, ['ok' => false, 'error' => 'Could not save client observations']);
}
respond(200, ['ok' => true, 'scan_id' => $scanId, 'count' => count($cleanClients)]);
