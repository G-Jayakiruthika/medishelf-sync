"""python3 -m unittest test_logic   (in this folder). Tests the firmware's decisions without hardware."""
import unittest

import logic


class ColdChain(unittest.TestCase):
    def test_bad_probe_values_rejected(self):
        b = logic.Buffer()
        self.assertFalse(b.add(1, 85.0))        # DS18B20 power-on value
        self.assertFalse(b.add(1, -127.0))      # not connected
        self.assertFalse(b.add(1, None))
        self.assertTrue(b.add(1, 5.04))
        self.assertEqual(b.items, [{"t": 1, "c": 5.0}])

    def test_buffer_keeps_newest_24h(self):
        b = logic.Buffer()
        for i in range(400):
            b.add(i, 5.0)
        self.assertEqual(len(b.items), 288)
        self.assertEqual(b.items[0]["t"], 400 - 288)
        b.sent(100)
        self.assertEqual(len(b.items), 188)

    def test_local_alarm_after_15_minutes(self):
        a = logic.LocalAlarm()
        self.assertFalse(a.check(9.5))
        self.assertFalse(a.check(9.6))
        self.assertTrue(a.check(9.8))            # third 5-minute reading out of range
        self.assertFalse(a.check(6.0))           # back in range resets
        a.update_range(15, 25)                   # e.g. a cool cabinet for other medicines
        self.assertFalse(a.check(9.0) or a.check(9.0))
        self.assertTrue(a.check(9.0))

    def test_freezing_also_alarms(self):
        a = logic.LocalAlarm()
        self.assertTrue([a.check(0.5) for _ in range(3)][-1])

    def test_backoff(self):
        self.assertEqual([logic.backoff_s(n) for n in (0, 1, 2, 3, 9)], [0, 60, 120, 240, 1800])

    def test_battery(self):
        self.assertEqual(logic.battery_percent(4.2), 100)
        self.assertEqual(logic.battery_percent(3.3), 0)
        self.assertEqual(logic.battery_percent(3.725), 50)
        self.assertIsNone(logic.battery_percent(0.1))


if __name__ == "__main__":
    unittest.main()
