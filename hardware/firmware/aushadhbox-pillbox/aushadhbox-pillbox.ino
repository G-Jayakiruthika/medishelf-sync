/*
  AushadhBox smart pillbox — ESP32 firmware
  ------------------------------------------
  A pill organiser with a magnet on each lid and a reed switch under it.
  - At each dose time the right lid's LED lights and the buzzer beeps (again every 10 minutes, 3 times at most).
  - Opening a lid is sent to AushadhBox. Opened at dose time -> the dose is ticked in the app ("by pillbox").
    Opened again later -> the family is warned about a possible double dose. Not opened -> the normal
    "did you take it?" check and missed-dose alert.
  - Works offline: openings are kept (up to 64) and sent when Wi-Fi is back, with the time they happened.

  Libraries (Arduino IDE -> Tools -> Manage Libraries):
    WiFiManager by tzapu (2.0.17 or newer), ArduinoJson by Benoit Blanchon (7.x)
  Board: "ESP32 Dev Module" (esp32 by Espressif Systems, 2.0.x or 3.x)

  First start: the box creates the Wi-Fi network "AushadhBox-Setup". Join it from a phone, pick your home
  Wi-Fi, and type the 6-digit pairing code shown in the app (Account -> Smart pillbox -> Add a pillbox).
  Hold the button for 10 seconds to forget Wi-Fi and pairing.

  Server API: see public_html/device.php in this repository.
*/
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <WiFiManager.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <time.h>
#include <sys/time.h>
#include "config.h"

#if !ALLOW_INSECURE_TLS
extern const uint8_t rootca_crt_bundle_start[] asm("_binary_x509_crt_bundle_start");   // Mozilla CA list built into the ESP32 core
#endif

Preferences prefs;
String server, token, boxName;
uint8_t lids = LIDS;

// ---------- Events waiting to be sent ----------
struct Event { char type; uint8_t lid; time_t t; };
const int QMAX = 64;
Event queue[QMAX];
int qLen = 0;
unsigned long lastChangeMs = 0;

void enqueue(char type, uint8_t lid) {
  if (qLen == QMAX) { memmove(queue, queue + 1, sizeof(Event) * (QMAX - 1)); qLen--; }   // drop the oldest
  queue[qLen++] = {type, lid, time(nullptr)};
  lastChangeMs = millis();
}

// ---------- Today's schedule from the server ----------
struct Slot { char slot; uint8_t lid; int minute; bool done; uint8_t beeps; int lastBeep; bool quiet; };
Slot slots[4];
int slotCount = 0;
int windowAfter = 180;
unsigned long lastSyncMs = 0;
int syncedDay = -1;

// ---------- Lids ----------
bool lidOpen[4] = {false, false, false, false};
bool rawOpen[4] = {false, false, false, false};
unsigned long rawSince[4] = {0, 0, 0, 0};

bool readLidOpen(uint8_t i) {
  int v = digitalRead(LID_PINS[i]);
#if LID_OPEN_IS_LOW
  return v == LOW;
#else
  return v == HIGH;   // magnet away from the reed switch -> switch open -> pull-up reads HIGH
#endif
}

// ---------- Buzzer (non-blocking: 3 short beeps) ----------
int beepStep = 0;
unsigned long beepNext = 0;
void startBeep() { beepStep = 6; beepNext = 0; }
void runBuzzer() {
  if (!beepStep || millis() < beepNext) return;
  digitalWrite(BUZZER_PIN, beepStep % 2 == 0 ? HIGH : LOW);
  beepStep--;
  beepNext = millis() + 180;
  if (!beepStep) digitalWrite(BUZZER_PIN, LOW);
}

// ---------- Time ----------
bool timeValid() { return time(nullptr) > 1700000000; }   // after Nov 2023 = the clock has been set
int minuteOfDay() { time_t n = time(nullptr); struct tm t; localtime_r(&n, &t); return t.tm_hour * 60 + t.tm_min; }
int dayOfYear() { time_t n = time(nullptr); struct tm t; localtime_r(&n, &t); return t.tm_yday; }

// The server tells us its UTC offset in seconds (19800 for India). POSIX TZ strings use the opposite sign.
void setOffset(long off) {
  char tz[24];
  long a = labs(off);
  snprintf(tz, sizeof tz, "UTC%c%ld:%02ld", off >= 0 ? '-' : '+', a / 3600, (a % 3600) / 60);
  setenv("TZ", tz, 1);
  tzset();
}

// ---------- HTTP ----------
bool request(const char *method, const String &action, const String &body, JsonDocument &out) {
  if (WiFi.status() != WL_CONNECTED) return false;
  WiFiClientSecure tls;
  WiFiClient plain;
  HTTPClient http;
  String url = server + "/device.php?action=" + action;
  bool ok;
  if (url.startsWith("https://")) {
#if ALLOW_INSECURE_TLS
    tls.setInsecure();
#else
    tls.setCACertBundle(rootca_crt_bundle_start);
#endif
    ok = http.begin(tls, url);
  } else {
    ok = http.begin(plain, url);   // local testing only
  }
  if (!ok) return false;
  http.setTimeout(12000);
  http.addHeader("Content-Type", "application/json");
  if (token.length()) {
    http.addHeader("Authorization", "Bearer " + token);
    http.addHeader("X-Device-Token", token);   // for hosts that drop the Authorization header
  }
  int code = strcmp(method, "POST") == 0 ? http.POST(body) : http.GET();
  String resp = code > 0 ? http.getString() : "";
  http.end();
  Serial.printf("%s %s -> %d\n", method, action.c_str(), code);
  if (code == 401 && action != "claim") {   // the box was removed or re-paired in the app
    Serial.println("Token refused: pair the box again (hold the button 10 s).");
    for (int i = 0; i < 6; i++) { digitalWrite(STATUS_LED, i % 2); delay(150); }
  }
  if (code < 200 || code >= 300) return false;
  return deserializeJson(out, resp) == DeserializationError::Ok;
}

void applySchedule(JsonDocument &doc) {
  if (doc["now"].is<long>()) {
    setOffset(doc["utcOffset"] | 19800L);
    if (!timeValid()) { struct timeval tv = { (time_t) doc["now"].as<long>(), 0 }; settimeofday(&tv, nullptr); }
  }
  windowAfter = doc["windowAfter"] | 180;
  // Keep beep counters for slots we already knew about today.
  Slot old[4]; int oldCount = slotCount; memcpy(old, slots, sizeof slots);
  slotCount = 0;
  for (JsonObject s : doc["slots"].as<JsonArray>()) {
    if (slotCount >= 4) break;
    const char *tm = s["time"] | "08:00";
    Slot n = { (s["slot"] | "m")[0], (uint8_t) (s["lid"] | 1), atoi(tm) * 60 + atoi(tm + 3), s["done"] | false, 0, -100, false };
    for (int i = 0; i < oldCount; i++) if (old[i].slot == n.slot) { n.beeps = old[i].beeps; n.lastBeep = old[i].lastBeep; n.quiet = old[i].quiet; n.done = n.done || old[i].done; }
    slots[slotCount++] = n;
  }
  syncedDay = timeValid() ? dayOfYear() : -1;
  lastSyncMs = millis();
}

void sync() {
  JsonDocument doc;
  if (request("GET", "sync", "", doc)) applySchedule(doc);
  else lastSyncMs = millis() - SYNC_EVERY_MS + 60000;   // try again in a minute
}

int batteryPercent() {
#if HAS_BATTERY
  int mv = analogReadMilliVolts(BATTERY_PIN) * 2;   // 1:2 divider
  return constrain(map(mv, 3300, 4150, 0, 100), 0, 100);
#else
  return -1;
#endif
}

void sendEvents() {
  if (!qLen || !timeValid() || WiFi.status() != WL_CONNECTED) return;
  JsonDocument body;
  body["fw"] = FW_VERSION;
  int b = batteryPercent(); if (b >= 0) body["battery"] = b;
  JsonArray ev = body["events"].to<JsonArray>();
  int n = qLen;
  for (int i = 0; i < n; i++) {
    JsonObject e = ev.add<JsonObject>();
    e["type"] = queue[i].type == 'o' ? "open" : queue[i].type == 'c' ? "close" : queue[i].type == 'b' ? "button" : "boot";
    if (queue[i].lid) e["lid"] = queue[i].lid;
    e["t"] = (long) queue[i].t;
  }
  String s; serializeJson(body, s);
  JsonDocument resp;
  if (request("POST", "events", s, resp)) {
    memmove(queue, queue + n, sizeof(Event) * (qLen - n)); qLen -= n;
    applySchedule(resp);   // the reply carries the fresh schedule (doses now ticked)
  }
}

// ---------- Setup: Wi-Fi portal and pairing ----------
void forgetAll() {
  prefs.clear();
  WiFiManager wm; wm.resetSettings();
  for (int i = 0; i < 10; i++) { digitalWrite(STATUS_LED, i % 2); delay(100); }
  ESP.restart();
}

bool claim(const String &code) {
  JsonDocument body; body["code"] = code; body["hw"] = WiFi.macAddress(); body["fw"] = FW_VERSION;
  String s; serializeJson(body, s);
  JsonDocument resp;
  if (!request("POST", "claim", s, resp) || !resp["token"].is<const char*>()) return false;
  token = resp["token"].as<String>();
  boxName = resp["name"] | "Pillbox";
  lids = (resp["compartments"] | 4) >= 4 ? 4 : 1;
  prefs.putString("token", token); prefs.putString("name", boxName); prefs.putUChar("lids", lids);
  return true;
}

void connectAndPair() {
  WiFiManager wm;
  WiFiManagerParameter pServer("server", "AushadhBox address", server.c_str(), 80);
  WiFiManagerParameter pCode("code", "6-digit pairing code from the app", "", 7, "inputmode=\"numeric\"");
  wm.addParameter(&pServer);
  if (!token.length()) wm.addParameter(&pCode);
  wm.setTitle("AushadhBox pillbox");
  wm.setConfigPortalTimeout(600);
  bool connected = token.length() ? wm.autoConnect(SETUP_AP_NAME, SETUP_AP_PASS) : wm.startConfigPortal(SETUP_AP_NAME, SETUP_AP_PASS);
  if (!connected) { Serial.println("No Wi-Fi; will keep working offline and retry."); return; }
  String sv = String(pServer.getValue()); sv.trim(); while (sv.endsWith("/")) sv.remove(sv.length() - 1);
  if (sv.length()) { server = sv; prefs.putString("server", server); }
  if (!token.length()) {
    String code = String(pCode.getValue()); code.trim();
    if (claim(code)) Serial.println("Paired as " + boxName);
    else { Serial.println("Pairing failed: check the code (it works for 30 minutes)."); for (int i = 0; i < 20; i++) { digitalWrite(BUZZER_PIN, i % 2); delay(80); } ESP.restart(); }
  }
}

void setup() {
  Serial.begin(115200);
  for (int i = 0; i < 4; i++) { pinMode(LID_PINS[i], INPUT_PULLUP); pinMode(LED_PINS[i], OUTPUT); digitalWrite(LED_PINS[i], LOW); }
  pinMode(BUZZER_PIN, OUTPUT); pinMode(BUTTON_PIN, INPUT_PULLUP); pinMode(STATUS_LED, OUTPUT);

  prefs.begin("aushadhbox", false);
  server = prefs.getString("server", DEFAULT_SERVER);
  token = prefs.getString("token", "");
  boxName = prefs.getString("name", "Pillbox");
  lids = prefs.getUChar("lids", LIDS);

  // Hold the button while switching on (10 s) to start again from scratch.
  unsigned long held = millis();
  while (digitalRead(BUTTON_PIN) == LOW) { digitalWrite(STATUS_LED, (millis() / 250) % 2); if (millis() - held > 10000) forgetAll(); delay(10); }

  setenv("TZ", "UTC-5:30", 1); tzset();   // India until the server says otherwise
  connectAndPair();
  configTime(0, 0, "pool.ntp.org", "time.google.com");
  setenv("TZ", "UTC-5:30", 1); tzset();
  for (int i = 0; i < 20 && !timeValid(); i++) delay(250);

  for (int i = 0; i < lids; i++) { lidOpen[i] = rawOpen[i] = readLidOpen(i); }
  enqueue('s', 0);   // "boot"
  sync();
  sendEvents();
}

void loop() {
  unsigned long ms = millis();

  // Lids, with debouncing
  for (uint8_t i = 0; i < lids; i++) {
    bool r = readLidOpen(i);
    if (r != rawOpen[i]) { rawOpen[i] = r; rawSince[i] = ms; }
    if (r != lidOpen[i] && ms - rawSince[i] > DEBOUNCE_MS) {
      lidOpen[i] = r;
      enqueue(r ? 'o' : 'c', i + 1);
      Serial.printf("Lid %d %s\n", i + 1, r ? "opened" : "closed");
      if (r) for (int k = 0; k < slotCount; k++) if (slots[k].lid == i + 1) {   // stop beeping for this lid at once
        int now = minuteOfDay();
        if (now >= slots[k].minute - 60 && now <= slots[k].minute + windowAfter) slots[k].done = true;
      }
    }
  }

  // Button: short press = quiet for the doses due now; 10 s = forget everything
  static unsigned long btnDown = 0;
  if (digitalRead(BUTTON_PIN) == LOW) {
    if (!btnDown) btnDown = ms;
    if (ms - btnDown > 10000) forgetAll();
  } else if (btnDown) {
    if (ms - btnDown > 50) { for (int k = 0; k < slotCount; k++) slots[k].quiet = true; digitalWrite(BUZZER_PIN, LOW); beepStep = 0; enqueue('b', 0); }
    btnDown = 0;
  }

  // Reminders: light the lid that is due and beep at the dose time, +10 and +20 minutes
  int now = timeValid() ? minuteOfDay() : -1;
  for (int k = 0; k < slotCount; k++) {
    Slot &s = slots[k];
    uint8_t led = LED_PINS[(s.lid - 1) % 4];
    bool due = now >= 0 && !s.done && now >= s.minute && now <= s.minute + windowAfter;
    digitalWrite(led, due && (ms / 500) % 2 ? HIGH : LOW);
    if (due && !s.quiet && s.beeps < BEEP_MAX_TIMES && now - s.lastBeep >= BEEP_REPEAT_MIN) { s.beeps++; s.lastBeep = now; startBeep(); }
  }
  runBuzzer();
  digitalWrite(STATUS_LED, WiFi.status() == WL_CONNECTED ? LOW : (ms / 1000) % 2);

  // Network
  if (qLen && ms - lastChangeMs > SEND_DELAY_MS) sendEvents();
  if (ms - lastSyncMs > SYNC_EVERY_MS || (timeValid() && dayOfYear() != syncedDay && ms - lastSyncMs > 60000)) { if (qLen) sendEvents(); else sync(); }
  if (WiFi.status() != WL_CONNECTED) { static unsigned long lastTry = 0; if (ms - lastTry > 60000) { lastTry = ms; WiFi.reconnect(); } }

  delay(10);
}
