"""Reorder suggestions, expiry risk and moving stock between shops of the same business."""
from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import date

from .forecast import Forecast

Z = {0.90: 1.2816, 0.95: 1.6449, 0.98: 2.0537, 0.99: 2.3263}


@dataclass
class Batch:
    batch: str
    exp: date | None
    qty: int


@dataclass
class Reorder:
    order_units: int
    reorder_point: float
    on_hand: int
    days_left: float | None
    reason: str


def usable_stock(batches: list[Batch], today: date, min_shelf_days: int = 30) -> int:
    """Stock that can still be sold: batches expiring within `min_shelf_days` don't count (customers refuse them,
    and a course must finish before expiry)."""
    return sum(b.qty for b in batches if b.exp is None or (b.exp - today).days >= min_shelf_days)


def reorder(fc: Forecast, batches: list[Batch], today: date, lead_days: int = 2, review_days: int = 7,
            service: float = 0.95, essential: bool = False) -> Reorder | None:
    """Order-up-to policy: cover lead time + review period, with safety stock for the day-to-day spread.
    Essential medicines (insulin, antiepileptics, TB drugs...) use a 99% service level."""
    if fc.per_day <= 0:
        return None
    z = Z[0.99 if essential else service]
    cover = lead_days + review_days
    safety = z * fc.sd_per_day * math.sqrt(cover)
    target = fc.per_day * cover + safety
    rop = fc.per_day * lead_days + safety
    have = usable_stock(batches, today)
    days_left = have / fc.per_day if fc.per_day else None
    if have > rop:
        return None
    units = max(1, math.ceil(target - have))
    why = f"Sells about {fc.per_day * 7:.1f} a week; {have} left (about {days_left:.0f} days)." if days_left is not None else ""
    return Reorder(units, round(rop, 1), have, round(days_left, 1) if days_left is not None else None, why)


@dataclass
class ExpiryRisk:
    batch: str
    exp: date
    qty: int
    likely_unsold: int
    days_to_exp: int
    suggestions: list[str] = field(default_factory=list)


def expiry_risks(fc: Forecast, batches: list[Batch], today: date, window_days: int = 120) -> list[ExpiryRisk]:
    """First-expiring batches are sold first (FEFO). For each batch, how much will probably still be on the shelf
    when it expires, given the forecast."""
    out = []
    used_before = 0.0
    for b in sorted((b for b in batches if b.exp), key=lambda b: b.exp):
        days = (b.exp - today).days
        if days < 0:
            out.append(ExpiryRisk(b.batch, b.exp, b.qty, b.qty, days, ["Expired: move to the 'Not for sale' box and return or destroy it."]))
            continue
        can_sell = max(0.0, fc.per_day * days - used_before)
        sold = min(b.qty, can_sell)
        used_before += sold
        left = int(round(b.qty - sold))
        if left > 0 and days <= window_days:
            out.append(ExpiryRisk(b.batch, b.exp, b.qty, left, days))
    return out


def transfer_targets(product_id: int, units: int, siblings: list[dict], here: tuple | None, max_km: float = 40) -> list[dict]:
    """Shops of the same business that sell this medicine faster. Moving stock between shops of one business needs
    no wholesale licence; selling to another business does (Form 20B/21B)."""
    from .routes import haversine_km
    out = []
    for s in siblings:
        weekly = sum(int(x["n"]) for x in s.get("sales", []) if int(x["p"]) == product_id) / 4
        if weekly <= 0:
            continue
        km = None
        if here and s.get("lat") is not None and s.get("lng") is not None and None not in here:
            km = round(haversine_km(here[0], here[1], float(s["lat"]), float(s["lng"])), 1)
            if km > max_km:
                continue
        out.append({"branch_id": int(s["id"]), "name": s["name"], "weekly": round(weekly, 1), "km": km, "units": min(units, math.ceil(weekly * 4))})
    return sorted(out, key=lambda x: (-x["weekly"], x["km"] if x["km"] is not None else 999))
