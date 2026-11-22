<?php
declare(strict_types=1);

function openDatabase(): PDO
{
    $database = new PDO('sqlite:' . __DIR__ . '/wifi.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA journal_mode = WAL');
    $database->exec('CREATE TABLE IF NOT EXISTS scans (id INTEGER PRIMARY KEY AUTOINCREMENT, device TEXT NOT NULL, uptime_ms INTEGER NOT NULL DEFAULT 0, received_at TEXT NOT NULL)');
    $database->exec('CREATE TABLE IF NOT EXISTS networks (id INTEGER PRIMARY KEY AUTOINCREMENT, scan_id INTEGER NOT NULL, ssid TEXT NOT NULL, rssi INTEGER NOT NULL CHECK (rssi BETWEEN -120 AND 0), channel INTEGER NOT NULL CHECK (channel BETWEEN 1 AND 196), secure INTEGER NOT NULL CHECK (secure IN (0, 1)), security_type TEXT NOT NULL DEFAULT \'UNKNOWN\', bssid TEXT NOT NULL, FOREIGN KEY (scan_id) REFERENCES scans(id) ON DELETE CASCADE)');
    $columns = $database->query('PRAGMA table_info(networks)')->fetchAll();
    if (!in_array('security_type', array_column($columns, 'name'), true)) {
        $database->exec("ALTER TABLE networks ADD COLUMN security_type TEXT NOT NULL DEFAULT 'UNKNOWN'");
    }
    $database->exec('CREATE INDEX IF NOT EXISTS idx_networks_scan_signal ON networks(scan_id, rssi DESC)');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_networks_bssid ON networks(bssid)');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_scans_received ON scans(received_at DESC)');
    $database->exec('CREATE TABLE IF NOT EXISTS client_scans (id INTEGER PRIMARY KEY AUTOINCREMENT, device TEXT NOT NULL, target_ssid TEXT NOT NULL, target_bssid TEXT NOT NULL, channel INTEGER NOT NULL, received_at TEXT NOT NULL)');
    $database->exec('CREATE TABLE IF NOT EXISTS client_observations (id INTEGER PRIMARY KEY AUTOINCREMENT, client_scan_id INTEGER NOT NULL, mac_address TEXT NOT NULL, rssi INTEGER NOT NULL CHECK (rssi BETWEEN -120 AND 0), packet_count INTEGER NOT NULL DEFAULT 1, first_seen_ms INTEGER NOT NULL DEFAULT 0, last_seen_ms INTEGER NOT NULL DEFAULT 0, FOREIGN KEY (client_scan_id) REFERENCES client_scans(id) ON DELETE CASCADE)');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_client_observations_scan ON client_observations(client_scan_id, rssi DESC)');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_client_observations_mac ON client_observations(mac_address)');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_client_scans_received ON client_scans(received_at DESC)');
    return $database;
}
