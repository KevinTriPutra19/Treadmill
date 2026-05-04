/*
  ESP32-CAM + RFID RC522 + Button + Treadmill Web

  FLOW
  - Tap RFID ke-1 : login
  - Tap RFID ke-2 : logout
  - Tekan button 1x: masuk mode foto
  - Tekan button 2x: ambil foto 1 (time)
  - Tekan button 3x: ambil foto 2 (distance) + OCR

  REQUIRED LIBRARIES
  - WiFi
  - HTTPClient
  - MFRC522
  - esp_camera (ESP32 board package)

  IMPORTANT
  - Sketch ini sengaja tidak memakai ArduinoJson supaya tidak perlu install library tambahan.
  - BASE_URL isi dengan alamat web aplikasi yang benar.
  - Jika aplikasi dibuka di root server, pakai contoh: http://76.13.23.138:4001
  - Jika aplikasi dipasang di subfolder, pakai contoh: http://76.13.23.138:4001/treadmill
  - RFID hanya mengirim UID; nama user diambil dari database atau dibuat default oleh server.

  WIRING NOTE
  RFID sesuai gambar kamu:
  - VCC  -> 3V3
  - GND  -> GND
  - SDA  -> GPIO15
  - SCK  -> GPIO14
  - MOSI -> GPIO13
  - MISO -> GPIO12
  - RST  -> tidak dipakai

  BUTTON (trigger foto):
  - Satu kaki button -> GPIO2
  - Kaki satunya      -> GND
  - Mode pin: INPUT_PULLUP (tidak perlu resistor eksternal)
  - Tekan button = mode bertahap (masuk mode foto -> foto 1 -> foto 2)

  WARNING:
  GPIO2 adalah pin strapping. Jangan tahan tombol saat board boot.
*/

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include "esp_camera.h"

// =========================
// WiFi & URL
// =========================
const char* WIFI_SSID = "YOUR_WIFI_NAME";
const char* WIFI_PASS = "YOUR_WIFI_PASSWORD";
const char* BASE_URL = "http://76.13.23.138:4001";

WiFiClientSecure secureClient;

// =========================
// RFID RC522
// =========================
#define RFID_SS_PIN   15
#define RFID_SCK_PIN  14
#define RFID_MOSI_PIN 13
#define RFID_MISO_PIN 12
#define RFID_RST_PIN  -1

MFRC522 mfrc522(RFID_SS_PIN, RFID_RST_PIN);

// =========================
// Button (simulate RFID tap)
// =========================
#define BUTTON_PIN 2
int lastButtonReading = HIGH;
int buttonStableState = HIGH;
unsigned long lastButtonDebounceMs = 0;
const unsigned long BUTTON_DEBOUNCE_MS = 40;

// =========================
// ESP32-CAM AI Thinker pin map
// =========================
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

// =========================
// App state
// =========================
String lastUid = "";
unsigned long lastUidSentMs = 0;
unsigned long lastStatePollMs = 0;
String currentActiveUid = "";
bool currentLoggedIn = false;
int captureButtonStep = 0;
unsigned long captureStepMs = 0;
const unsigned long CAPTURE_STEP_TIMEOUT_MS = 15000;
String lastStateSignature = "";
unsigned long lastButtonDiagMs = 0;

// =========================
// Small JSON helpers without ArduinoJson
// =========================
String jsonEscape(const String& input) {
  String out;
  out.reserve(input.length() + 8);
  for (size_t i = 0; i < input.length(); i++) {
    char c = input[i];
    switch (c) {
      case '"': out += "\\\""; break;
      case '\\': out += "\\\\"; break;
      case '\b': out += "\\b"; break;
      case '\f': out += "\\f"; break;
      case '\n': out += "\\n"; break;
      case '\r': out += "\\r"; break;
      case '\t': out += "\\t"; break;
      default: out += c; break;
    }
  }
  return out;
}

String extractJsonString(const String& src, const String& key, const String& fallback = "") {
  String token = String("\"") + key + "\":\"";
  int start = src.indexOf(token);
  if (start < 0) return fallback;
  start += token.length();
  int end = src.indexOf('"', start);
  if (end < 0) return fallback;
  return src.substring(start, end);
}

int extractJsonInt(const String& src, const String& key, int fallback = 0) {
  String token = String("\"") + key + "\":";
  int start = src.indexOf(token);
  if (start < 0) return fallback;
  start += token.length();
  while (start < (int) src.length() && (src[start] == ' ' || src[start] == '"')) start++;
  int end = start;
  while (end < (int) src.length() && (isDigit(src[end]) || src[end] == '-')) end++;
  if (end <= start) return fallback;
  return src.substring(start, end).toInt();
}

bool extractJsonBool(const String& src, const String& key, bool fallback = false) {
  String token = String("\"") + key + "\":";
  int start = src.indexOf(token);
  if (start < 0) return fallback;
  start += token.length();
  while (start < (int) src.length() && src[start] == ' ') start++;
  if (src.startsWith("true", start)) return true;
  if (src.startsWith("false", start)) return false;
  return fallback;
}

// =========================
// Camera setup
// =========================
bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 10000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // Use a smaller frame and a single buffer to reduce RAM pressure.
  // This avoids PSRAM dependency and works better on marginal power setups.
  config.frame_size = FRAMESIZE_VGA;
  config.jpeg_quality = 14;
  config.fb_count = 1;
  config.grab_mode = CAMERA_GRAB_LATEST;
  config.fb_location = CAMERA_FB_IN_DRAM;

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Camera init failed: 0x%x\n", err);
    return false;
  }
  return true;
}

// =========================
// RFID setup
// =========================
void initRFID() {
  SPI.begin(RFID_SCK_PIN, RFID_MISO_PIN, RFID_MOSI_PIN, RFID_SS_PIN);
  mfrc522.PCD_Init();
}

String uidToString(MFRC522::Uid* uid) {
  String result;
  for (byte i = 0; i < uid->size; i++) {
    if (uid->uidByte[i] < 0x10) result += "0";
    result += String(uid->uidByte[i], HEX);
    if (i < uid->size - 1) result += ":";
  }
  result.toUpperCase();
  return result;
}

String readRfidUid() {
  if (!mfrc522.PICC_IsNewCardPresent()) return "";
  if (!mfrc522.PICC_ReadCardSerial()) return "";

  String uid = uidToString(&mfrc522.uid);
  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();
  uid.trim();
  uid.toUpperCase();
  return uid;
}

String resolveRfidName(const String& uid) {
  (void) uid;
  return "";
}

// =========================
// HTTP helpers
// =========================
bool beginHttpRequest(HTTPClient& http, const String& url) {
  if (url.startsWith("https://")) {
    secureClient.setInsecure();
    return http.begin(secureClient, url);
  }

  return http.begin(url);
}

bool postJson(const String& url, const String& body, String& response, int& code) {
  if (WiFi.status() != WL_CONNECTED) return false;
  HTTPClient http;
  if (!beginHttpRequest(http, url)) return false;
  http.addHeader("Content-Type", "application/json");
  code = http.POST(body);
  response = http.getString();
  http.end();
  return true;
}

bool getText(const String& url, String& response, int& code) {
  if (WiFi.status() != WL_CONNECTED) return false;
  HTTPClient http;
  if (!beginHttpRequest(http, url)) return false;
  code = http.GET();
  response = http.getString();
  http.end();
  return true;
}

// =========================
// Web API
// =========================
bool sendRfidTap(const String& uid, const String& name, String& action, bool& isLoggedIn, String& memberName, int& tapCount) {
  String body = String("{\"uid\":\"") + jsonEscape(uid) + "\",\"name\":\"" + jsonEscape(name) + "\"}";

  String response;
  int code = 0;
  if (!postJson(String(BASE_URL) + "/endpoints/rfid_tap.php", body, response, code)) return false;
  if (code < 200 || code >= 300) return false;

  action = extractJsonString(response, "action", "");
  isLoggedIn = extractJsonBool(response, "is_logged_in", false);
  memberName = extractJsonString(response, "member_name", "");
  tapCount = extractJsonInt(response, "tap_count", 0);
  return true;
}

bool fetchDeviceState(bool& isLoggedIn, String& memberName, String& activeUid, int& tapCount) {
  String response;
  int code = 0;
  if (!getText(String(BASE_URL) + "/endpoints/device_state.php", response, code)) return false;
  if (code != 200) return false;

  isLoggedIn = extractJsonBool(response, "is_logged_in", false);
  memberName = extractJsonString(response, "member_name", "");
  activeUid = extractJsonString(response, "active_uid", "");
  tapCount = extractJsonInt(response, "tap_count", 0);
  return true;
}

bool fetchCommand(String& command, int& count, int& intervalSec) {
  String response;
  int code = 0;
  if (!getText(String(BASE_URL) + "/endpoints/device_command.php", response, code)) return false;
  if (code != 200) return false;

  command = extractJsonString(response, "command", "idle");
  count = extractJsonInt(response, "photo_count", 0);
  intervalSec = extractJsonInt(response, "photo_interval_sec", 2);
  return true;
}

bool uploadCapture(int photoIndex, bool runOcr) {
  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("Camera capture failed");
    return false;
  }

  HTTPClient http;
  String url = String(BASE_URL) + "/endpoints/upload.php?photo_index=" + String(photoIndex) + "&run_ocr=" + String(runOcr ? 1 : 0);
  if (!beginHttpRequest(http, url)) {
    esp_camera_fb_return(fb);
    return false;
  }
  http.addHeader("Content-Type", "application/octet-stream");
  int code = http.POST(fb->buf, fb->len);
  String response = http.getString();
  http.end();
  esp_camera_fb_return(fb);

  Serial.printf("UPLOAD %d -> %d | %s\n", photoIndex, code, response.c_str());
  return code >= 200 && code < 300;
}

bool notifyCaptureDone() {
  String response;
  int code = 0;
  String body = "{}";
  return postJson(String(BASE_URL) + "/endpoints/capture_done.php", body, response, code) && code >= 200 && code < 300;
}

bool resetDeviceState() {
  String response;
  int code = 0;
  String body = "{}";
  bool ok = postJson(String(BASE_URL) + "/endpoints/device_reset.php", body, response, code);
  if (!ok || code < 200 || code >= 300) {
    Serial.println("WARN: gagal reset state device");
    return false;
  }
  Serial.println("State device direset ke idle");
  return true;
}

void doCaptureFlow(int count, int intervalSec) {
  if (count < 1) count = 1;
  if (count > 2) count = 2;
  if (intervalSec < 0) intervalSec = 0;
  if (intervalSec > 10) intervalSec = 10;

  Serial.printf("Capture: %d foto, %d sec interval\n", count, intervalSec);
  delay(300);

  for (int i = 1; i <= count; i++) {
    bool runOcr = (i == count);
    bool ok = uploadCapture(i, runOcr);
    if (!ok) {
      Serial.println("Upload failed! Check server.");
      delay(1200);
      return;
    }

    if (i < count) {
      Serial.printf("Next photo in %d sec...\n", intervalSec);
      delay(intervalSec * 1000UL);
    }
  }

  notifyCaptureDone();
  Serial.println("Capture done!");
  delay(1200);
}

bool captureSinglePhoto(int photoIndex, bool runOcr) {
  bool ok = uploadCapture(photoIndex, runOcr);
  if (!ok) {
    Serial.println("Upload failed! Check server.");
    return false;
  }
  if (runOcr) {
    notifyCaptureDone();
  }
  return true;
}

void handleTapAction(const String& uid, const String& action, bool isLoggedIn, const String& memberName, int tapCount) {
  if (action == "login" || action == "switch_user") {
    captureButtonStep = 0;
    Serial.printf("LOGIN: %s | %s | tap=%d\n", memberName.c_str(), uid.c_str(), tapCount);
  } else if (action == "logout") {
    captureButtonStep = 0;
    Serial.println("LOGOUT");
    delay(1000);
  } else if (isLoggedIn) {
    Serial.printf("LOGGED IN: %s\n", memberName.c_str());
  }
}

void refreshStateUi() {
  bool isLoggedIn = false;
  String memberName;
  String activeUid;
  int tapCount = 0;
  if (fetchDeviceState(isLoggedIn, memberName, activeUid, tapCount)) {
    currentLoggedIn = isLoggedIn;
    currentActiveUid = activeUid;
    if (!isLoggedIn) {
      captureButtonStep = 0;
    }

    String signature = String(isLoggedIn ? "1" : "0") + "|" + activeUid;
    if (signature != lastStateSignature) {
      lastStateSignature = signature;
      if (isLoggedIn) {
        Serial.printf("STATE: %s logged in\n", memberName.c_str());
      } else {
        Serial.println("STATE: Idle");
      }
    }
  }
}

// =========================
// Arduino
// =========================
void setup() {
  Serial.begin(115200);
  delay(500);

  pinMode(BUTTON_PIN, INPUT_PULLUP);
  delay(20);

  Serial.println("Booting...");

  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.println("Connecting WiFi...");
  while (WiFi.status() != WL_CONNECTED) {
    delay(300);
    Serial.print('.');
  }
  Serial.println();
  Serial.print("IP: ");
  Serial.println(WiFi.localIP());

  initRFID();

  if (!initCamera()) {
    Serial.println("Camera init failed! Check wiring.");
    while (true) {
      delay(1000);
    }
  }

  resetDeviceState();
  Serial.println("Setup complete!");
  Serial.printf("BUTTON init state: %s\n", digitalRead(BUTTON_PIN) == LOW ? "LOW" : "HIGH");
  Serial.println("Tips: ketik 'b' di Serial Monitor untuk simulasi tekan button");
  refreshStateUi();
}

void processTap(const String& uid, const String& displayName) {
  if (uid != lastUid || (millis() - lastUidSentMs > 2000)) {
    String action;
    bool isLoggedIn = false;
    String memberName;
    int tapCount = 0;

    if (sendRfidTap(uid, displayName, action, isLoggedIn, memberName, tapCount)) {
      lastUid = uid;
      lastUidSentMs = millis();
      currentLoggedIn = isLoggedIn;
      currentActiveUid = isLoggedIn ? uid : "";
      if (!isLoggedIn) {
        captureButtonStep = 0;
      }
      handleTapAction(uid, action, isLoggedIn, memberName, tapCount);
    } else {
      Serial.println("ERROR: RFID Server/WiFi failed");
    }
  }
}

void handleCaptureButtonPressed() {
  if (!currentLoggedIn) {
    Serial.println("BUTTON: login dulu pakai RFID");
    return;
  }

  if (captureButtonStep == 0) {
    captureButtonStep = 1;
    captureStepMs = millis();
    Serial.println("BUTTON: mode foto aktif, tekan lagi utk foto 1");
    return;
  }

  if (captureButtonStep == 1) {
    captureStepMs = millis();
    Serial.println("BUTTON: ambil foto 1 (time)");
    if (captureSinglePhoto(1, false)) {
      captureButtonStep = 2;
      Serial.println("BUTTON: foto 1 OK, tekan lagi utk foto 2 + OCR");
    } else {
      captureButtonStep = 0;
    }
    return;
  }

  if (captureButtonStep == 2) {
    Serial.println("BUTTON: ambil foto 2 (distance) + OCR");
    if (captureSinglePhoto(2, true)) {
      Serial.println("Capture selesai");
    }
    captureButtonStep = 0;
  }
}

void processButtonTap() {
  int reading = digitalRead(BUTTON_PIN);
  if (reading != lastButtonReading) {
    Serial.printf("BUTTON RAW change: %s\n", reading == LOW ? "LOW" : "HIGH");
    lastButtonDebounceMs = millis();
    lastButtonReading = reading;
  }

  if ((millis() - lastButtonDebounceMs) > BUTTON_DEBOUNCE_MS && reading != buttonStableState) {
    buttonStableState = reading;
    if (buttonStableState == LOW) {
      handleCaptureButtonPressed();
    }
  }
}

void processSerialButtonCommand() {
  while (Serial.available() > 0) {
    char c = (char) Serial.read();
    if (c == 'b' || c == 'B') {
      Serial.println("SERIAL: trigger button virtual");
      handleCaptureButtonPressed();
    }
  }
}

void loop() {
  processSerialButtonCommand();
  processButtonTap();

  if (millis() - lastButtonDiagMs >= 3000) {
    lastButtonDiagMs = millis();
    Serial.printf("BUTTON RAW now: %s\n", digitalRead(BUTTON_PIN) == LOW ? "LOW" : "HIGH");
  }

  if (captureButtonStep > 0 && (millis() - captureStepMs > CAPTURE_STEP_TIMEOUT_MS)) {
    captureButtonStep = 0;
    Serial.println("BUTTON: mode foto timeout, ulang dari awal");
  }

  String uid = readRfidUid();
  if (uid.length() > 0) {
    processTap(uid, resolveRfidName(uid));
  }

  if (millis() - lastStatePollMs >= 5000) {
    lastStatePollMs = millis();
    refreshStateUi();
  }
}
