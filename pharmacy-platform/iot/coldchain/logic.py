"""Decision logic of the fridge thermometer, shared by the ESP32 firmware (MicroPython) and the tests (CPython).

Nothing here touches hardware or the network, so it can be tested on any computer."""

MAX_BUFFER = 288          # 24 hours of 5-minute readings kept while Wi-Fi is down (about 6 KB of RAM)
SENSOR_MIN, SENSOR_MAX = -40.0, 80.0
DS18B20_ERROR = (85.0, -127.0)   # values a DS18B20 returns when it was not read correctly


def valid(c):
    return c is not None and SENSOR_MIN <= c <= SENSOR_MAX and c not in DS18B20_ERROR


class Buffer:
    """Readings waiting to be sent. Oldest are dropped first when full; the server accepts up to 300 per report."""

    def __init__(self, cap=MAX_BUFFER):
        self.cap = cap
        self.items = []

    def add(self, t, c):
        if not valid(c):
            return False
        self.items.append({"t": int(t), "c": round(c, 1)})
        if len(self.items) > self.cap:
            del self.items[0:len(self.items) - self.cap]
        return True

    def batch(self, n=300):
        return self.items[:n]

    def sent(self, n):
        del self.items[0:n]


class LocalAlarm:
    """Beeps on the device itself when the server can't be reached: out of range for `minutes` in a row.
    (Online, the server decides and answers buzz=true.) Uses the last range the server sent; 2-8 °C by default."""

    def __init__(self, lo=2.0, hi=8.0, minutes=15, every_min=5):
        self.lo, self.hi = lo, hi
        self.need = max(1, minutes // every_min)
        self.out_count = 0

    def update_range(self, lo, hi):
        if lo is not None and hi is not None and lo < hi:
            self.lo, self.hi = float(lo), float(hi)

    def check(self, c):
        if not valid(c):
            return False
        self.out_count = self.out_count + 1 if (c < self.lo or c > self.hi) else 0
        return self.out_count >= self.need


def backoff_s(fails):
    """Seconds to wait before the next try after `fails` failed reports: 1, 2, 4... minutes, at most 30."""
    return min(1800, 60 * (2 ** max(0, min(5, fails - 1)))) if fails > 0 else 0


def battery_percent(volts):
    """Li-ion cell through a 1:2 divider: 3.3 V empty, 4.15 V full. Mains-powered boards report None."""
    if volts is None or volts < 1.0:
        return None
    return max(0, min(100, int(round((volts - 3.3) / (4.15 - 3.3) * 100))))
