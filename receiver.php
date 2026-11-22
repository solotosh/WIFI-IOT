<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    respond(503, ['ok' => false, 'error' => 'Server is not configured']);
}
$config = require $configFile;
$expectedKey = is_array($config) ? (string) ($config['api_key'] ?? '') : '';
$providedKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    respond(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    respond(415, ['ok' => false, 'error' => 'Content-Type must be application/json']);
}
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 65536) {
    respond(413, ['ok' => false, 'error' => 'Payload is too large']);
}

$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || $raw === '') {
    respond(400, ['ok' => false, 'error' => 'Request body is empty']);
}
if (strlen($raw) > 65536) {
    respond(413, ['ok' => false, 'error' => 'Payload is too large']);
}

try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    respond(400, ['ok' => false, 'error' => 'Malformed JSON']);
}
if (!is_array($payload) || !isset($payload['networks']) || !is_array($payload['networks'])) {
    respond(422, ['ok' => false, 'error' => 'networks must be an array']);
}
if (count($payload['networks']) > 200) {
    respond(422, ['ok' => false, 'error' => 'Too many networks']);
}

$networks = [];
foreach ($payload['networks'] as $network) {
    if (!is_array($network)) {
        respond(422, ['ok' => false, 'error' => 'Each network must be an object']);
    }
    $ssid = $network['ssid'] ?? null;
    $rssi = filter_var($network['rssi'] ?? null, FILTER_VALIDATE_INT);
    $channel = filter_var($network['channel'] ?? null, FILTER_VALIDATE_INT);
    $secure = $network['secure'] ?? null;
    $securityType = strtoupper(trim((string) ($network['security_type'] ?? ($secure === false ? 'OPEN' : 'SECURED'))));
    $bssid = strtoupper((string) ($network['bssid'] ?? ''));
    if (!is_string($ssid) || strlen($ssid) > 128
        || $rssi === false || $rssi < -120 || $rssi > 0
        || $channel === false || $channel < 1 || $channel > 196
        || !is_bool($secure) || $securityType === '' || strlen($securityType) > 32
        || preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $bssid) !== 1) {
        respond(422, ['ok' => false, 'error' => 'A network entry contains invalid values']);
    }
    $networks[] = ['ssid' => $ssid, 'rssi' => $rssi, 'channel' => $channel, 'secure' => $secure, 'security_type' => $securityType, 'bssid' => $bssid];
}

require_once __DIR__ . '/database.php';
try {
    $database = openDatabase();
    $database->beginTransaction();
    $insertScan = $database->prepare('INSERT INTO scans (device, uptime_ms, received_at) VALUES (:device, :uptime_ms, :received_at)');
    $insertScan->execute([
        ':device' => substr((string) ($payload['device'] ?? 'ESP32'), 0, 64),
        ':uptime_ms' => max(0, (int) ($payload['uptime_ms'] ?? 0)),
        ':received_at' => gmdate('c'),
    ]);
    $scanId = (int) $database->lastInsertId();
    $insertNetwork = $database->prepare('INSERT INTO networks (scan_id, ssid, rssi, channel, secure, security_type, bssid) VALUES (:scan_id, :ssid, :rssi, :channel, :secure, :security_type, :bssid)');
    foreach ($networks as $network) {
        $insertNetwork->execute([
            ':scan_id' => $scanId,
            ':ssid' => $network['ssid'],
            ':rssi' => $network['rssi'],
            ':channel' => $network['channel'],
            ':secure' => $network['secure'] ? 1 : 0,
            ':security_type' => $network['security_type'],
            ':bssid' => $network['bssid'],
        ]);
    }
    $database->exec('DELETE FROM scans WHERE id NOT IN (SELECT id FROM scans ORDER BY id DESC LIMIT 1000)');
    $database->commit();
} catch (Throwable $exception) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    error_log('WiFi scan database error: ' . $exception->getMessage());
    respond(500, ['ok' => false, 'error' => 'Could not save scan data']);
}
respond(200, ['ok' => true, 'scan_id' => $scanId, 'count' => count($networks)]);
