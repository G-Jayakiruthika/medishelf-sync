"""Demand forecasts for one medicine at one shop.

Indian pharmacies sell most medicines a few strips a week, with long gaps (intermittent demand), and a few every day.
Two methods, chosen by how often the medicine sells:
  - Croston's method with the Syntetos-Boylan correction (SBA) for medicines sold on fewer than half of the days;
  - simple exponential smoothing with a day-of-week profile for regular sellers.
On top, a season factor by medicine group and month: fever, ORS and antimalarials rise in the monsoon (June to
September, dengue into November), cough and cold medicines in winter, antihistamines in spring. The factors are modest
defaults and can be replaced by the shop's own history once it has a full year.
Only the standard library is used, so it runs on a cheap server or the shop's own computer."""
from __future__ import annotations

import math
from dataclasses import dataclass
from datetime import date, timedelta

# Medicine groups recognised from the generic name, and their month factors (1 = normal).
GROUPS = {
    "fever": ("paracetamol", "ibuprofen", "mefenamic", "nimesulide"),
    "ors": ("oral rehydration", "ors", "zinc"),
    "antimalarial": ("artemether", "artesunate", "chloroquine", "primaquine", "lumefantrine"),
    "antibiotic": ("amoxicillin", "azithromycin", "cefixime", "ciprofloxacin", "doxycycline", "ofloxacin", "metronidazole"),
    "cough_cold": ("dextromethorphan", "ambroxol", "guaifenesin", "phenylephrine", "chlorpheniramine", "levocetirizine", "cetirizine", "montelukast"),
}
SEASON = {   # month 1..12
    "fever":        [1.10, 1.00, 0.95, 0.95, 1.00, 1.15, 1.35, 1.45, 1.45, 1.35, 1.20, 1.10],
    "ors":          [0.85, 0.85, 1.00, 1.20, 1.35, 1.30, 1.30, 1.25, 1.15, 1.00, 0.90, 0.85],
    "antimalarial": [0.80, 0.75, 0.75, 0.80, 0.90, 1.20, 1.50, 1.60, 1.50, 1.30, 1.00, 0.85],
    "antibiotic":   [1.10, 1.05, 1.00, 0.95, 0.95, 1.00, 1.10, 1.15, 1.10, 1.05, 1.05, 1.10],
    "cough_cold":   [1.35, 1.20, 1.05, 0.90, 0.80, 0.85, 0.95, 1.00, 1.00, 1.05, 1.20, 1.40],
}


def group_of(name: str) -> str | None:
    n = (name or "").lower()
    for g, words in GROUPS.items():
        if any(w in n for w in words):
            return g
    return None


def season_factor(group: str | None, month: int) -> float:
    return SEASON[group][month - 1] if group in SEASON else 1.0


@dataclass
class Forecast:
    per_day: float        # expected units a day over the coming days
    sd_per_day: float     # day-to-day spread (for safety stock)
    method: str           # croston, ses or none
    sold_days: int        # days with at least one sale in the history
    history_days: int


def daily_series(sales: dict[str, int], end: date, days: int) -> list[int]:
    """sales: {'YYYY-MM-DD': units}. Returns `days` values ending at `end` (inclusive), zeros where nothing sold."""
    start = end - timedelta(days=days - 1)
    return [max(0, int(sales.get((start + timedelta(i)).isoformat(), 0))) for i in range(days)]


def croston_sba(series: list[int], alpha: float = 0.15) -> float:
    """Average demand per day for intermittent demand (Croston 1972, Syntetos & Boylan 2005 bias correction)."""
    z = p = None
    q = 1
    for x in series:
        if x > 0:
            if z is None:
                z, p = float(x), float(q)
            else:
                z = z + alpha * (x - z)
                p = p + alpha * (q - p)
            q = 1
        else:
            q += 1
    if z is None:
        return 0.0
    return (1 - alpha / 2) * z / p


def ses(series: list[int], alpha: float = 0.2) -> float:
    level = None
    for x in series:
        level = float(x) if level is None else level + alpha * (x - level)
    return level or 0.0


def weekday_profile(series: list[int], end: date) -> list[float]:
    """Share of sales by weekday (Mon=0), normalised to average 1. Flat when there is too little data."""
    n = len(series)
    sums, counts = [0.0] * 7, [0] * 7
    for i, x in enumerate(series):
        wd = (end - timedelta(days=n - 1 - i)).weekday()
        sums[wd] += x
        counts[wd] += 1
    means = [sums[i] / counts[i] if counts[i] else 0 for i in range(7)]
    avg = sum(means) / 7
    if avg <= 0 or sum(1 for x in series if x > 0) < 28:
        return [1.0] * 7
    return [m / avg if m > 0 else 0.5 for m in means]   # never zero: a closed-day pattern can change


def forecast(sales: dict[str, int], end: date, name: str = "", history_days: int = 180, horizon_start: date | None = None) -> Forecast:
    series = daily_series(sales, end, history_days)
    sold = sum(1 for x in series if x > 0)
    if sold == 0:
        return Forecast(0.0, 0.0, "none", 0, history_days)
    # Measure seasonality out of the history, forecast, then put the coming month's season back.
    grp = group_of(name)
    start = end - timedelta(days=history_days - 1)
    deseason = [x / season_factor(grp, (start + timedelta(i)).month) for i, x in enumerate(series)]
    if sold / history_days < 0.5:
        base, method = croston_sba(deseason), "croston"
    else:
        base, method = ses(deseason), "ses"
    target = horizon_start or (end + timedelta(days=1))
    per_day = base * season_factor(grp, target.month)
    recent = series[-56:]
    mean = sum(recent) / len(recent)
    sd = math.sqrt(sum((x - mean) ** 2 for x in recent) / max(1, len(recent) - 1))
    return Forecast(round(per_day, 4), round(sd, 4), method, sold, history_days)


def demand_change(series: list[int], recent_days: int = 7, base_days: int = 28) -> float | None:
    """Robust jump score of the last week against the four weeks before (like a z-score, using median and MAD).
    Large positive values are an early sign of an outbreak (fever, diarrhoea) or a shortage nearby."""
    if len(series) < recent_days + base_days:
        return None
    base = sorted(series[-(recent_days + base_days):-recent_days])
    recent = sum(series[-recent_days:]) / recent_days
    med = base[len(base) // 2]
    mad = sorted(abs(x - med) for x in base)[len(base) // 2]
    scale = 1.4826 * mad if mad > 0 else max(0.5, (sum(base) / len(base)) ** 0.5)
    return round((recent - med) / scale, 2)
