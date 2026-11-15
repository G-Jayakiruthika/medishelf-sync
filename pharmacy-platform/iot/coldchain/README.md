# Fridge thermometer for insulin and vaccines (IoT)

Insulin, vaccines and some eye drops must be stored at **2–8 °C**. Shops already keep a written temperature log for
the inspector, but a log written twice a day doesn't notice a power cut at night. This ₹600 device reads the fridge
every 5 minutes. It beeps and warns the shop when the fridge has been too warm or too cold for 15 minutes, and the
portal keeps a 30-day log for inspections.

## Parts (about ₹600)
| Part | Approx. price |
|---|---|
| ESP32 development board (ESP32-WROOM-32, any "DevKit V1") | ₹350–450 |
| DS18B20 waterproof probe, 1 m cable | ₹120–180 |
| 4.7 kΩ resistor | ₹2 |
| Active buzzer 5 V (or passive) | ₹20 |
| 5 V USB phone charger and cable | already in most shops |
| Optional: 18650 cell + TP4056 charger board, for power cuts | ₹150 |

## Wiring
```
DS18B20 red    → 3V3
DS18B20 black  → GND
DS18B20 yellow → GPIO 4   (and a 4.7 kΩ resistor between GPIO 4 and 3V3)
Buzzer +       → GPIO 25,  buzzer − → GND
Battery (optional) → divider 100 kΩ / 100 kΩ → GPIO 34   (reads half the cell voltage)
LED            → the board's own LED on GPIO 2
```
Pass the thin probe cable through the fridge door seal and tape the probe to the middle shelf, next to the insulin,
not touching the back wall or the freezer plate.

## Install
1. Flash MicroPython (v1.22 or newer) on the ESP32: `esptool.py --chip esp32 write_flash -z 0x1000 ESP32_GENERIC-*.bin`.
2. In the portal: **Devices → Add fridge thermometer**. You get a 6-digit code valid for 30 minutes.
3. Copy `secrets.example.py` to `secrets.py` and fill in the shop Wi-Fi, server address and the code.
4. Copy `logic.py`, `main.py` and `secrets.py` to the board: `mpremote cp logic.py main.py secrets.py :`. Restart it.
5. The device swaps the code for a secret token (kept in `token.json`) and reports. A short blink every 5 minutes means it's alive.

## How it behaves
- **Online:** the server decides. When every reading of the last 15 minutes is out of range, the portal shows "Fridge is too warm/cold", the owner gets the alert, and the device beeps.
- **Wi-Fi down:** up to 24 hours of readings are kept and sent with their real times when Wi-Fi returns, with retries at 1, 2, 4… up to 30 minutes. The device also beeps by itself after 15 minutes out of range, using the last range the server sent (2–8 °C by default).
- **Probe fault:** a DS18B20 that isn't read correctly gives 85 °C or −127 °C. These are thrown away, and the device beeps once.
- **Hangs:** a watchdog restarts the board after 2 minutes.
- **Removed in the portal:** the server answers 401; the device forgets its token and waits for a new pairing code.

## Test without hardware
```
python3 -m unittest test_logic                                                  # the firmware's decisions
python3 simulate.py --server http://127.0.0.1:8090 --code 123456 --temps 5,5.2,9.5,9.8,10.1,10.4
```
The simulator uses the same `logic.py` against a real server and prints the server's answer for each reading
(`state: high, buzz: true` once the fridge has stayed warm).

## Security
The pairing code works once and only for 30 minutes. The token is 48 random hex characters, stored on the server only
as a SHA-256 hash. Reports are rate-limited, and wrong tokens are counted per IP. Readings outside −40…80 °C are
rejected. The device sends only temperatures, times and battery level.
