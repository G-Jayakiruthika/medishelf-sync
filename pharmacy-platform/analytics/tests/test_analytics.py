"""python -m unittest discover -s analytics/tests -t analytics   (from pharmacy-platform/)"""
import random
import unittest
from datetime import date, timedelta

from abs_analytics.forecast import croston_sba, demand_change, forecast, group_of, season_factor
from abs_analytics.routes import haversine_km, plan_round
from abs_analytics.run import suggestions
from abs_analytics.stockplan import Batch, expiry_risks, reorder, transfer_targets, usable_stock

TODAY = date(2026, 8, 10)


def history(per_day, days=180, end=TODAY, seed=1, prob=1.0):
    rnd = random.Random(seed)
    out = {}
    for i in range(days):
        d = end - timedelta(days=i)
        if rnd.random() < prob:
            out[d.isoformat()] = max(0, int(round(rnd.gauss(per_day, per_day ** 0.5))))
    return out


class Forecasts(unittest.TestCase):
    def test_groups_and_season(self):
        self.assertEqual(group_of("Paracetamol 650mg"), "fever")
        self.assertEqual(group_of("Artemether + Lumefantrine"), "antimalarial")
        self.assertIsNone(group_of("Telmisartan 40mg"))
        self.assertGreater(season_factor("fever", 8), season_factor("fever", 3))   # monsoon
        self.assertGreater(season_factor("cough_cold", 12), season_factor("cough_cold", 6))

    def test_regular_seller_uses_smoothing(self):
        f = forecast(history(5), TODAY, "Telmisartan 40mg")
        self.assertEqual(f.method, "ses")
        self.assertAlmostEqual(f.per_day, 5, delta=1.2)

    def test_intermittent_uses_croston(self):
        # one strip on about one day in eight
        f = forecast(history(1, prob=0.12, seed=3), TODAY, "Levetiracetam 500")
        self.assertEqual(f.method, "croston")
        self.assertTrue(0.05 < f.per_day < 0.35, f.per_day)

    def test_croston_known_value(self):
        self.assertAlmostEqual(croston_sba([0, 0, 4, 0, 0, 4, 0, 0, 4]), 0.925 * 4 / 3, places=2)

    def test_no_sales(self):
        self.assertEqual(forecast({}, TODAY).method, "none")

    def test_monsoon_raises_fever_forecast(self):
        h = history(10, days=60)
        self.assertGreater(forecast(h, TODAY, "Paracetamol", 60, horizon_start=date(2026, 8, 11)).per_day,
                           forecast(h, TODAY, "Paracetamol", 60, horizon_start=date(2026, 3, 1)).per_day)

    def test_demand_jump(self):
        flat = [3] * 28 + [3] * 7
        self.assertLess(demand_change(flat), 1)
        outbreak = [3, 2, 4, 3, 3, 2, 3] * 4 + [12, 14, 15, 13, 16, 18, 17]
        self.assertGreater(demand_change(outbreak), 4)
        self.assertIsNone(demand_change([1] * 10))


class StockPlans(unittest.TestCase):
    def test_reorder_when_low(self):
        f = forecast(history(5), TODAY, "Telmisartan")
        r = reorder(f, [Batch("A1", date(2027, 6, 30), 8)], TODAY, lead_days=2)
        self.assertIsNotNone(r)
        self.assertGreater(r.order_units, 30)

    def test_no_reorder_when_enough(self):
        f = forecast(history(2), TODAY, "Telmisartan")
        self.assertIsNone(reorder(f, [Batch("A1", date(2027, 6, 30), 200)], TODAY))

    def test_nearly_expired_stock_does_not_count(self):
        bs = [Batch("OLD", TODAY + timedelta(days=10), 100), Batch("NEW", TODAY + timedelta(days=300), 5)]
        self.assertEqual(usable_stock(bs, TODAY), 5)

    def test_essential_keeps_more_safety_stock(self):
        f = forecast(history(5, seed=7), TODAY, "Insulin")
        normal = reorder(f, [], TODAY, essential=False).order_units
        essential = reorder(f, [], TODAY, essential=True).order_units
        self.assertGreater(essential, normal)

    def test_expiry_fefo(self):
        f = forecast(history(1), TODAY, "Cetirizine")
        risks = expiry_risks(f, [Batch("B1", TODAY + timedelta(days=30), 100), Batch("B2", TODAY + timedelta(days=400), 50)], TODAY)
        self.assertEqual(len(risks), 1)
        self.assertEqual(risks[0].batch, "B1")
        self.assertGreater(risks[0].likely_unsold, 50)

    def test_expired_batch_flagged(self):
        f = forecast(history(1), TODAY, "Cetirizine")
        r = expiry_risks(f, [Batch("X", TODAY - timedelta(days=3), 4)], TODAY)
        self.assertEqual((r[0].likely_unsold, r[0].days_to_exp), (4, -3))

    def test_transfer_targets(self):
        sib = [{"id": 2, "name": "Town shop", "lat": 11.10, "lng": 77.34, "sales": [{"p": 9, "n": 40}]},
               {"id": 3, "name": "Far shop", "lat": 13.08, "lng": 80.27, "sales": [{"p": 9, "n": 80}]},
               {"id": 4, "name": "No sales", "lat": 11.11, "lng": 77.35, "sales": []}]
        t = transfer_targets(9, 30, sib, (11.08, 77.30))      # about 6 km from the town shop
        self.assertEqual([x["branch_id"] for x in t], [2])   # far shop is >40 km, the other doesn't sell it


class Routes(unittest.TestCase):
    def test_haversine(self):
        self.assertAlmostEqual(haversine_km(11.0168, 76.9558, 13.0827, 80.2707), 422, delta=8)   # Coimbatore - Chennai

    def test_round_visits_all_and_is_short(self):
        shop = (11.34, 77.72)
        stops = [{"id": i, "name": str(i), "lat": 11.34 + 0.05 * dx, "lng": 77.72 + 0.05 * dy}
                 for i, (dx, dy) in enumerate([(1, 0), (2, 0), (2, 1), (1, 1), (0, 1)], start=1)]
        r = plan_round(shop, stops, "rural")
        self.assertEqual(sorted(r["order"]), [1, 2, 3, 4, 5])
        self.assertIn(r["order"][0], (1, 5))                 # goes round the loop, no zig-zag
        self.assertLess(r["km"], 6 * 5.6 * 1.45 * 1.05)
        self.assertGreater(r["minutes"], 30)

    def test_empty_round(self):
        self.assertEqual(plan_round((1, 1), []), {"order": [], "km": 0.0, "minutes": 0})


class EndToEnd(unittest.TestCase):
    def test_suggestions_from_history(self):
        sales = []
        for d, n in history(6, seed=2).items():
            sales.append({"p": 1, "d": d, "n": n})
        for d, n in history(3, days=35, seed=5).items():
            dd = date.fromisoformat(d)
            sales.append({"p": 2, "d": d, "n": 15 if (TODAY - dd).days < 7 else n})   # fever outbreak this week
        h = {"today": TODAY.isoformat(), "days": 180, "branch": {"name": "Sri Sai", "area": "rural", "lat": 11.34, "lng": 77.72},
             "products": [{"id": 1, "name": "Telma 40", "generic": "Telmisartan 40mg", "schedule": "H", "pack": "strips", "cold": 0},
                          {"id": 2, "name": "Dolo 650", "generic": "Paracetamol 650mg", "schedule": "OTC", "pack": "strips", "cold": 0},
                          {"id": 3, "name": "Cetzine", "generic": "Cetirizine", "schedule": "OTC", "pack": "strips", "cold": 0}],
             "sales": sales,
             "stock": [{"p": 1, "batch": "T1", "exp": "2027-03-31", "qty": 6}, {"p": 3, "batch": "C1", "exp": (TODAY + timedelta(days=40)).isoformat(), "qty": 20}],
             "siblings": []}
        items = suggestions(h)
        kinds = {(i["kind"], i["product_id"]) for i in items}
        self.assertIn(("reorder", 1), kinds)
        self.assertIn(("demand", 2), kinds)
        self.assertTrue(any("dengue" in i["title"] for i in items if i["product_id"] == 2))
        self.assertIn(("expiry", 3), kinds)
        self.assertTrue(any("packs (strips each)" in i["title"] for i in items if i["kind"] == "reorder"))

    def test_new_shop_without_history_is_not_told_everything_expires(self):
        h = {"today": TODAY.isoformat(), "days": 180, "branch": {"name": "New", "area": "urban", "lat": None, "lng": None},
             "products": [{"id": 5, "name": "Asthalin", "generic": "Salbutamol", "schedule": "H", "pack": None, "cold": 0}],
             "sales": [{"p": 9, "d": (TODAY - timedelta(days=5)).isoformat(), "n": 1}],
             "stock": [{"p": 5, "batch": "A", "exp": (TODAY + timedelta(days=60)).isoformat(), "qty": 10}], "siblings": []}
        self.assertEqual([i for i in suggestions(h) if i["kind"] == "expiry"], [])


if __name__ == "__main__":
    unittest.main()
