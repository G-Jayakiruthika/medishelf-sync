// AushadhBox smart pillbox: settings you may want to change before flashing.
#pragma once

// Firmware version reported to the server.
#define FW_VERSION "1.0.0"

// Default server (can also be changed on the Wi-Fi setup page).
#define DEFAULT_SERVER "https://aushadhbox.in"

// Name of the Wi-Fi network the box creates for setup, and its password (8+ characters, or "" for an open network).
#define SETUP_AP_NAME "AushadhBox-Setup"
#define SETUP_AP_PASS "aushadh123"

// ---- Pins (ESP32 DevKit V1, 30-pin) ----
// Reed switches: one leg to the pin, the other to GND. Magnet on the lid. Closed lid = magnet near = switch closed = LOW.
// Set LIDS to 1 for a box with a single lid (only LID_PINS[0] and LED_PINS[0] are used).
#define LIDS 4
static const uint8_t LID_PINS[4] = {32, 33, 25, 26};   // morning, afternoon, evening, bedtime
static const uint8_t LED_PINS[4] = {16, 17, 18, 19};   // through 220 ohm resistors to GND
#define BUZZER_PIN 23                                  // active 3.3-5 V buzzer (through an NPN transistor if it draws >20 mA)
#define BUTTON_PIN 27                                  // push button to GND: press = quiet; hold 10 s = forget Wi-Fi and pairing
#define STATUS_LED 2                                   // the blue LED on the board
#define BATTERY_PIN 34                                 // optional: battery + -> 100k -> pin -> 100k -> GND. Set HAS_BATTERY 0 if not fitted.
#define HAS_BATTERY 0

// Reed switches with the opposite logic (normally closed): set to 1.
#define LID_OPEN_IS_LOW 0

// ---- Timing ----
#define SYNC_EVERY_MS      (10UL * 60 * 1000)   // fetch today's dose times
#define BEEP_REPEAT_MIN    10                   // beep again every 10 minutes while a dose is due
#define BEEP_MAX_TIMES     3                    // ...at most this many times per dose
#define DEBOUNCE_MS        60
#define SEND_DELAY_MS      1500                 // wait a moment after a lid moves, so open+close go in one request

// Testing only: skip HTTPS certificate checks (for a local test server with a self-signed certificate). Never in real use.
#define ALLOW_INSECURE_TLS 0
