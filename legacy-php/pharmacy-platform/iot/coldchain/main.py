"""AushadhBox fridge thermometer: ESP32 + DS18B20 waterproof probe, MicroPython.

Every 5 minutes it reads the temperature inside the medicine fridge (insulin, vaccines, some eye drops must stay at
2-8 °C) and reports to AushadhBox Pharmacy. The server keeps the log for inspections, warns the shop when the fridge
is too warm or too cold for 15 minutes, and tells the device to beep. Without Wi-Fi it keeps 24 hours of readings and
beeps by itself.

Pairing: the shop makes a 6-digit code in the portal (Devices → Add fridge thermometer) and puts it in secrets.py as
PAIR_CODE. On first start the device swaps it once for a secret token (kept in token.json).
Wiring and setup: README.md."""
import json
import time

import machine
import network
import ntptime
import onewire
import ds18x20
import urequests

import logic

try:
    import secrets          # WIFI_SSID, WIFI_PASSWORD, SERVER, PAIR_CODE (see secrets.example.py)
except ImportError:
    raise SystemExit("Copy secrets.example.py to secrets.py and fill it in")

PIN_PROBE, PIN_BUZZER, PIN_LED, PIN_BATTERY = 4, 25, 2, 34
EVERY_S = 300
TOKEN_FILE = "token.json"

probe = ds18x20.DS18X20(onewire.OneWire(machine.Pin(PIN_PROBE)))
buzzer = machine.PWM(machine.Pin(PIN_BUZZER), freq=2700, duty=0)
led = machine.Pin(PIN_LED, machine.Pin.OUT)
adc = machine.ADC(machine.Pin(PIN_BATTERY)); adc.atten(machine.ADC.ATTN_11DB)
wdt = machine.WDT(timeout=120_000)        # restart by itself if anything hangs
buf = logic.Buffer()
alarm = logic.LocalAlarm()


def beep(times=3):
    for _ in range(times):
        buzzer.duty(512); led.on(); time.sleep_ms(250)
        buzzer.duty(0); led.off(); time.sleep_ms(250)


def wifi():
    w = network.WLAN(network.STA_IF)
    w.active(True)
    if not w.isconnected():
        w.connect(secrets.WIFI_SSID, secrets.WIFI_PASSWORD)
        for _ in range(40):
            if w.isconnected():
                break
            time.sleep_ms(500)
    if w.isconnected():
        try:
            ntptime.settime()                 # readings carry real times even after an offline night
        except Exception:
            pass
    return w.isconnected()


def read_c():
    roms = probe.scan()
    if not roms:
        return None
    probe.convert_temp(); time.sleep_ms(750)
    try:
        return probe.read_temp(roms[0])
    except Exception:
        return None


def battery():
    volts = adc.read_uv() / 1_000_000 * 2 if hasattr(adc, "read_uv") else adc.read() / 4095 * 3.6 * 2
    return logic.battery_percent(volts)


def unix_now():
    return time.time() + 946684800 if time.gmtime(0)[0] == 2000 else time.time()   # MicroPython epoch is 2000


def post(action, body, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    r = urequests.post(secrets.SERVER.rstrip("/") + "/device.php?action=" + action, data=json.dumps(body), headers=headers)
    try:
        return r.status_code, r.json()
    finally:
        r.close()


def load_token():
    try:
        with open(TOKEN_FILE) as f:
            return json.load(f)["token"]
    except Exception:
        return None


def claim():
    status, data = post("claim", {"code": secrets.PAIR_CODE})
    if status == 200 and "token" in data:
        with open(TOKEN_FILE, "w") as f:
            json.dump({"token": data["token"]}, f)
        alarm.update_range(data.get("min"), data.get("max"))
        return data["token"]
    print("Pairing failed:", status, data, "- make a new code in the portal and put it in secrets.py")
    return None


def run():
    token = load_token()
    fails, next_try = 0, 0
    while True:
        wdt.feed()
        c = read_c()
        if not buf.add(unix_now(), c):
            print("Probe not read: check the wire")
            beep(1)
        online = wifi() and time.time() >= next_try
        if online and not token:
            token = claim()
        if online and token:
            try:
                status, data = post("report", {"readings": buf.batch(), "battery": battery()}, token)
                if status == 200:
                    buf.sent(len(buf.batch()))
                    fails = 0
                    alarm.update_range(data.get("min"), data.get("max"))
                    alarm.out_count = 0
                    if data.get("buzz"):
                        beep(5)
                elif status == 401:           # removed in the portal: forget the token, pair again
                    token = None
                    try:
                        import os; os.remove(TOKEN_FILE)
                    except Exception:
                        pass
                else:
                    raise OSError(status)
            except Exception as e:
                fails += 1
                next_try = time.time() + logic.backoff_s(fails)
                print("Report failed:", e)
                if alarm.check(c):
                    beep(5)
        elif alarm.check(c):                  # offline: decide here
            beep(5)
        led.on(); time.sleep_ms(30); led.off()  # a short blink every 5 minutes = alive
        time.sleep(EVERY_S - 2)


run()
