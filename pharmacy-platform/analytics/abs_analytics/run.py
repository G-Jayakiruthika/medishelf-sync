"""Nightly analytics for one shop: pull sales and stock, compute suggestions, send them back.

    python -m abs_analytics.run --url https://pharmacy.example.in --token abs_...   (the shop's sync token)
    python -m abs_analytics.run --file history.json --dry-run                        (offline, prints the suggestions)

Run it from cron once a night per shop (see analytics/README.md)."""
from __future__ import annotations

import argparse
import json
import sys
import urllib.request
from datetime import date

from .forecast import demand_change, daily_series, forecast, group_of
from .stockplan import Batch, expiry_risks, reorder, transfer_targets

ESSENTIAL = ("insulin", "levetiracetam", "phenytoin", "valproate", "carbamazepine", "isoniazid", "rifampicin",
             "levothyroxine", "warfarin", "salbutamol", "budesonide", "nifedipine", "metformin")
LEAD_DAYS = {"urban": 1, "semiurban": 2, "rural": 4}   # how long a distributor order usually takes to arrive


def suggestions(h: dict) -> list[dict]:
    today = date.fromisoformat(h["today"])
    branch = h["branch"]
    area = branch.get("area") or "semiurban"
    here = (float(branch["lat"]), float(branch["lng"])) if branch.get("lat") is not None and branch.get("lng") is not None else None
    products = {int(p["id"]): p for p in h["products"]}
    sales: dict[int, dict[str, int]] = {}
    for s in h["sales"]:
        sales.setdefault(int(s["p"]), {})[s["d"]] = sales.get(int(s["p"]), {}).get(s["d"], 0) + int(s["n"])
    stock: dict[int, list[Batch]] = {}
    for s in h["stock"]:
        stock.setdefault(int(s["p"]), []).append(Batch(s["batch"], date.fromisoformat(s["exp"]) if s.get("exp") else None, int(s["qty"])))
    # A shop that joined recently has no sales yet for most medicines: don't call all of its stock "unsold".
    first = min((s["d"] for s in h["sales"]), default=None)
    history_days = (today - date.fromisoformat(first)).days if first else 0
    out = []
    for pid, p in products.items():
        name = p.get("generic") or p["name"]
        fc = forecast(sales.get(pid, {}), today, name, history_days=int(h.get("days", 180)))
        batches = stock.get(pid, [])
        essential = bool(int(p.get("cold") or 0)) or any(w in name.lower() for w in ESSENTIAL)
        r = reorder(fc, batches, today, lead_days=LEAD_DAYS.get(area, 2), essential=essential)
        if r:
            out.append({"kind": "reorder", "product_id": pid, "title": f"Reorder {p['name']}: about {r.order_units} " + (f"packs ({p['pack']} each)" if p.get('pack') else "units") + (" · essential, don't run out" if essential else ""),
                        "data": {"units": r.order_units, "onHand": r.on_hand, "daysLeft": r.days_left, "perWeek": round(fc.per_day * 7, 1),
                                 "method": fc.method, "essential": essential, "why": r.reason}})
        for e in expiry_risks(fc, batches, today):
            if e.days_to_exp >= 0 and fc.method == "none" and history_days < 60:
                continue
            targets = transfer_targets(pid, e.likely_unsold, h.get("siblings", []), here) if e.days_to_exp >= 0 else []
            if e.days_to_exp < 0:
                title = f"{p['name']} batch {e.batch} has expired ({e.qty} left): keep apart, return or destroy"
            elif targets:
                t = targets[0]
                title = f"Move {min(e.likely_unsold, t['units'])} of {p['name']} (batch {e.batch}, expires {e.exp:%b %Y}) to {t['name']}: it sells {t['weekly']} a week there"
            else:
                title = f"{p['name']} batch {e.batch}: about {e.likely_unsold} may expire unsold by {e.exp:%b %Y}. Return to the distributor in time or offer it first"
            out.append({"kind": "transfer" if targets else "expiry", "product_id": pid, "title": title,
                        "data": {"batch": e.batch, "exp": e.exp.isoformat(), "unsold": e.likely_unsold, "days": e.days_to_exp, "targets": targets[:3]}})
        jump = demand_change(daily_series(sales.get(pid, {}), today, 35))
        if jump is not None and jump >= 4 and sum(daily_series(sales.get(pid, {}), today, 7)) >= 5:
            grp = group_of(name)
            hint = {"fever": "Fever medicines are selling much faster: possible dengue or flu in the area.",
                    "ors": "ORS is selling much faster: possible diarrhoea outbreak; check drinking water advisories.",
                    "antimalarial": "Antimalarials are selling much faster: possible malaria in the area."}.get(grp, "Selling much faster than usual.")
            out.append({"kind": "demand", "product_id": pid, "title": f"{p['name']}: {hint} Keep extra stock.", "data": {"score": jump, "group": grp}})
    return out


def _post(url: str, token: str, action: str, body: dict) -> dict:
    req = urllib.request.Request(url.rstrip("/") + "/sync.php?action=" + action, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json", "Authorization": "Bearer " + token}, method="POST")
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="AushadhBox Pharmacy analytics for one shop")
    ap.add_argument("--url"); ap.add_argument("--token"); ap.add_argument("--file"); ap.add_argument("--days", type=int, default=180)
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args(argv)
    if a.file:
        with open(a.file, encoding="utf-8") as f:
            h = json.load(f)
    elif a.url and a.token:
        h = _post(a.url, a.token, "history", {"days": a.days})
    else:
        ap.error("give --url and --token, or --file")
    items = suggestions(h)
    if a.dry_run or not (a.url and a.token):
        print(json.dumps(items, indent=2, ensure_ascii=False))
        return 0
    r = _post(a.url, a.token, "insights", {"items": items})
    print(f"{h['branch']['name']}: {r.get('saved', 0)} suggestions saved")
    return 0


if __name__ == "__main__":
    sys.exit(main())
