# WIFI-IOT

ESP32 WiFi Scanner

An ESP32 scans nearby WiFi access points and uploads the latest result to a small PHP dashboard.

## Setup

1. Copy `secrets.example.h` to `secrets.h` and enter the WiFi name, password, server URL, and a long random API key.
2. Copy `config.example.php` to `config.php` and enter the same API key.
3. Install the ESP32 board package and ArduinoJson 7.x in Arduino IDE.
4. Upload `wifi.ino` to the ESP32.
5. Serve this folder through PHP and open `index.php`.

For the hosted installation, the ESP32 upload endpoint is `https://chocolate-weasel-838490.hostingersite.com/receiver.php`.

`config.php`, `secrets.h`, and the runtime SQLite files are excluded by `.gitignore`.

## Behavior

- The ESP32 scans and uploads approximately every five seconds.
- `receiver.php` accepts authenticated JSON POST requests only, validates every network, and stores each scan in SQLite as one transaction.
- `database.php` creates the schema and indexes automatically. The newest 1,000 scans are retained.
- `index.php` reads the latest scan, escapes output, sorts networks by signal strength, shows the server receipt time, and warns when data is stale.
- `history.php` lists every stored network observation with search, summary totals, and pagination.
- `router.php` shows per-router MAC, channel, band, security, signal statistics, and a signal-over-time chart.
- `clients.php` displays privacy-limited passive client observations captured for the configured router.
- `clients-receiver.php` validates and stores authenticated passive-monitor uploads.
- `login.php` and `auth.php` provide session-based administrator authentication with CSRF protection and throttling.
- `settings.php` provides an authenticated page for copying the receiver URL and API key.
- `change-password.php` lets the authenticated administrator securely replace the login password.

## Security

Keep this service on a trusted private network unless HTTPS and additional web-server access controls are configured. Rotate the WiFi password that was previously stored directly in `wifi.ino`.
