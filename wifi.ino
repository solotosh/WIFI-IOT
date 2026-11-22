#include "WiFi.h"
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <esp_wifi.h>
#include "secrets.h"

constexpr unsigned long SCAN_INTERVAL_MS = 5000;
constexpr unsigned long CONNECT_TIMEOUT_MS = 20000;
constexpr unsigned long HTTP_TIMEOUT_MS = 5000;
constexpr size_t MAX_NETWORKS = 100;
constexpr size_t MAX_CLIENTS = 100;

struct ClientObservation { uint8_t mac[6]; int8_t rssi; uint32_t packetCount; uint32_t firstSeenMs; uint32_t lastSeenMs; };
ClientObservation observedClients[MAX_CLIENTS];
volatile size_t observedClientCount = 0;
uint8_t targetBssid[6] = {};
uint8_t targetChannel = 0;
portMUX_TYPE snifferMux = portMUX_INITIALIZER_UNLOCKED;
unsigned long lastScanAt = 0;

const char* securityName(wifi_auth_mode_t mode) {
  switch (mode) {
    case WIFI_AUTH_OPEN: return "OPEN"; case WIFI_AUTH_WEP: return "WEP";
    case WIFI_AUTH_WPA_PSK: return "WPA"; case WIFI_AUTH_WPA2_PSK: return "WPA2";
    case WIFI_AUTH_WPA_WPA2_PSK: return "WPA/WPA2"; case WIFI_AUTH_WPA2_ENTERPRISE: return "WPA2-ENTERPRISE";
    default: return "SECURED";
  }
}

bool sameMac(const uint8_t* first, const uint8_t* second) { for (int i = 0; i < 6; i++) if (first[i] != second[i]) return false; return true; }

void IRAM_ATTR promiscuousCallback(void* buffer, wifi_promiscuous_pkt_type_t type) {
  if (type != WIFI_PKT_DATA) return;
  const wifi_promiscuous_pkt_t* packet = static_cast<wifi_promiscuous_pkt_t*>(buffer);
  const uint8_t* frame = packet->payload;
  const uint16_t frameControl = frame[0] | (frame[1] << 8);
  const bool toDs = (frameControl & 0x0100) != 0;
  const bool fromDs = (frameControl & 0x0200) != 0;
  if (toDs && fromDs) return;
  const uint8_t* address1 = frame + 4;
  const uint8_t* address2 = frame + 10;
  const uint8_t* bssid = toDs ? address1 : address2;
  const uint8_t* client = toDs ? address2 : address1;
  if (!sameMac(bssid, targetBssid) || (client[0] & 0x01) != 0 || sameMac(client, targetBssid)) return;
  const uint32_t now = millis();
  portENTER_CRITICAL_ISR(&snifferMux);
  for (size_t i = 0; i < observedClientCount; i++) {
    if (sameMac(observedClients[i].mac, client)) {
      observedClients[i].rssi = packet->rx_ctrl.rssi; observedClients[i].packetCount++; observedClients[i].lastSeenMs = now;
      portEXIT_CRITICAL_ISR(&snifferMux); return;
    }
  }
  if (observedClientCount < MAX_CLIENTS) {
    ClientObservation& entry = observedClients[observedClientCount++];
    for (int i = 0; i < 6; i++) entry.mac[i] = client[i];
    entry.rssi = packet->rx_ctrl.rssi; entry.packetCount = 1; entry.firstSeenMs = now; entry.lastSeenMs = now;
  }
  portEXIT_CRITICAL_ISR(&snifferMux);
}

bool connectWiFi() {
  esp_wifi_set_promiscuous(false);
  if (WiFi.status() == WL_CONNECTED) return true;
  WiFi.mode(WIFI_STA); WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  const unsigned long startedAt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startedAt < CONNECT_TIMEOUT_MS) { delay(500); Serial.print('.'); }
  if (WiFi.status() != WL_CONNECTED) { Serial.println("\nWiFi connection timed out."); WiFi.disconnect(); return false; }
  Serial.println("\nConnected. ESP32 IP: " + WiFi.localIP().toString()); return true;
}

int authenticatedPost(const char* url, const String& payload) {
  WiFiClientSecure client; client.setInsecure();
  HTTPClient http; http.setConnectTimeout(HTTP_TIMEOUT_MS); http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(client, url)) return -1;
  http.addHeader("Content-Type", "application/json"); http.addHeader("X-API-Key", API_KEY);
  const int status = http.POST(payload); http.end(); return status;
}

void sendNetworkScan(int scanCount) {
  JsonDocument document; JsonArray networks = document["networks"].to<JsonArray>();
  const int safeCount = scanCount > 0 ? min(scanCount, static_cast<int>(MAX_NETWORKS)) : 0;
  for (int i = 0; i < safeCount; i++) {
    JsonObject network = networks.add<JsonObject>(); network["ssid"] = WiFi.SSID(i); network["rssi"] = WiFi.RSSI(i); network["channel"] = WiFi.channel(i);
    const wifi_auth_mode_t authMode = WiFi.encryptionType(i); network["secure"] = authMode != WIFI_AUTH_OPEN; network["security_type"] = securityName(authMode); network["bssid"] = WiFi.BSSIDstr(i);
  }
  document["device"] = DEVICE_NAME; document["uptime_ms"] = millis();
  String payload; serializeJson(document, payload);
  Serial.printf("Network upload: HTTP %d (%u networks).\n", authenticatedPost(SERVER_URL, payload), networks.size());
}

bool discoverTargetRouter() {
  if (!connectWiFi()) return false;
  const int count = WiFi.scanNetworks(false, true); bool found = false;
  for (int i = 0; i < count; i++) if (WiFi.SSID(i) == TARGET_SSID) {
    const uint8_t* bssid = WiFi.BSSID(i); for (int j = 0; j < 6; j++) targetBssid[j] = bssid[j];
    targetChannel = WiFi.channel(i); found = true; break;
  }
  WiFi.scanDelete(); if (!found) Serial.printf("Target router %s was not found.\n", TARGET_SSID); return found;
}

void monitorTargetRouter() {
  observedClientCount = 0; WiFi.disconnect(); delay(200);
  esp_wifi_set_channel(targetChannel, WIFI_SECOND_CHAN_NONE);
  esp_wifi_set_promiscuous_rx_cb(&promiscuousCallback); esp_wifi_set_promiscuous(true);
  Serial.printf("Passively monitoring %s on channel %u for %u seconds...\n", TARGET_SSID, targetChannel, SNIFF_DURATION_SECONDS);
  delay(SNIFF_DURATION_SECONDS * 1000UL); esp_wifi_set_promiscuous(false);
  Serial.printf("Observed %u client MAC address(es).\n", observedClientCount);
}

void uploadClientObservations() {
  if (!connectWiFi()) return;
  JsonDocument document; document["device"] = DEVICE_NAME; document["target_ssid"] = TARGET_SSID; document["channel"] = targetChannel;
  char routerMac[18]; snprintf(routerMac, sizeof(routerMac), "%02X:%02X:%02X:%02X:%02X:%02X", targetBssid[0], targetBssid[1], targetBssid[2], targetBssid[3], targetBssid[4], targetBssid[5]);
  document["target_bssid"] = routerMac; JsonArray clients = document["clients"].to<JsonArray>();
  for (size_t i = 0; i < observedClientCount; i++) {
    char mac[18]; snprintf(mac, sizeof(mac), "%02X:%02X:%02X:%02X:%02X:%02X", observedClients[i].mac[0], observedClients[i].mac[1], observedClients[i].mac[2], observedClients[i].mac[3], observedClients[i].mac[4], observedClients[i].mac[5]);
    JsonObject client = clients.add<JsonObject>(); client["mac_address"] = mac; client["rssi"] = observedClients[i].rssi; client["packet_count"] = observedClients[i].packetCount; client["first_seen_ms"] = observedClients[i].firstSeenMs; client["last_seen_ms"] = observedClients[i].lastSeenMs;
  }
  String payload; serializeJson(document, payload);
  Serial.printf("Client observation upload: HTTP %d (%u devices).\n", authenticatedPost(CLIENTS_SERVER_URL, payload), clients.size());
}

void setup() {
  Serial.begin(115200); delay(500); Serial.println("\n--- WiFi Scanner by TOSH Developer ---");
  WiFi.mode(WIFI_STA); WiFi.setAutoReconnect(true); connectWiFi();
}

void loop() {
  if (millis() - lastScanAt < SCAN_INTERVAL_MS) { delay(50); return; }
  lastScanAt = millis();
  if (ENABLE_PASSIVE_SNIFFER) { if (discoverTargetRouter()) { monitorTargetRouter(); uploadClientObservations(); } return; }
  if (!connectWiFi()) return;
  const int count = WiFi.scanNetworks(false, true);
  if (count < 0) Serial.printf("WiFi scan failed with code %d.\n", count); else sendNetworkScan(count);
  WiFi.scanDelete();
}
