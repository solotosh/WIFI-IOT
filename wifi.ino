#include "WiFi.h"
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include "secrets.h"

constexpr unsigned long SCAN_INTERVAL_MS = 5000;
constexpr unsigned long CONNECT_TIMEOUT_MS = 20000;
constexpr unsigned long HTTP_TIMEOUT_MS = 5000;
constexpr size_t MAX_NETWORKS = 100;
unsigned long lastScanAt = 0;

const char* securityName(wifi_auth_mode_t mode) {
  switch (mode) {
    case WIFI_AUTH_OPEN: return "OPEN";
    case WIFI_AUTH_WEP: return "WEP";
    case WIFI_AUTH_WPA_PSK: return "WPA";
    case WIFI_AUTH_WPA2_PSK: return "WPA2";
    case WIFI_AUTH_WPA_WPA2_PSK: return "WPA/WPA2";
    case WIFI_AUTH_WPA2_ENTERPRISE: return "WPA2-ENTERPRISE";
    default: return "SECURED";
  }
}

bool connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;
  Serial.printf("Connecting to %s", WIFI_SSID);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  const unsigned long startedAt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startedAt < CONNECT_TIMEOUT_MS) {
    delay(500);
    Serial.print('.');
  }
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("\nWiFi connection timed out; retrying later.");
    WiFi.disconnect();
    return false;
  }
  Serial.println("\nConnected. ESP32 IP: " + WiFi.localIP().toString());
  return true;
}

void sendScan(int scanCount) {
  JsonDocument document;
  JsonArray networks = document["networks"].to<JsonArray>();
  const int safeCount = scanCount > 0 ? min(scanCount, static_cast<int>(MAX_NETWORKS)) : 0;
  for (int i = 0; i < safeCount; i++) {
    JsonObject network = networks.add<JsonObject>();
    network["ssid"] = WiFi.SSID(i);
    network["rssi"] = WiFi.RSSI(i);
    network["channel"] = WiFi.channel(i);
    const wifi_auth_mode_t authMode = WiFi.encryptionType(i);
    network["secure"] = authMode != WIFI_AUTH_OPEN;
    network["security_type"] = securityName(authMode);
    network["bssid"] = WiFi.BSSIDstr(i);
  }
  document["count"] = networks.size();
  document["device"] = DEVICE_NAME;
  document["uptime_ms"] = millis();

  String payload;
  serializeJson(document, payload);
  WiFiClientSecure client;
  // Traffic is encrypted. For stronger identity verification, install the hosting
  // provider's root CA with client.setCACert(...) instead of setInsecure().
  client.setInsecure();
  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(client, SERVER_URL)) {
    Serial.println("Could not initialize HTTP request.");
    return;
  }
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);
  const int status = http.POST(payload);
  if (status >= 200 && status < 300) {
    Serial.printf("Uploaded %u networks (HTTP %d).\n", networks.size(), status);
  } else {
    Serial.printf("Upload failed (HTTP %d): %s\n", status, http.errorToString(status).c_str());
  }
  http.end();
}

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n--- WiFi Scanner by TOSH Developer ---");
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  connectWiFi();
}

void loop() {
  if (millis() - lastScanAt < SCAN_INTERVAL_MS) {
    delay(50);
    return;
  }
  lastScanAt = millis();
  if (!connectWiFi()) return;

  Serial.println("Scanning...");
  const int count = WiFi.scanNetworks(false, true);
  if (count < 0) {
    Serial.printf("WiFi scan failed with code %d.\n", count);
  } else {
    Serial.printf("Found %d network(s).\n", count);
    sendScan(count);
  }
  WiFi.scanDelete();
}
