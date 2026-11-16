"""Delivery rounds for village pickup points and home deliveries.

Rural roads wind, so straight-line distance is multiplied by a road factor (1.3 in towns, 1.45 in villages).
The round starts and ends at the shop: nearest-neighbour start, then 2-opt to remove crossings. Good enough for the
5-25 stops of a pharmacy round; exact solvers are not worth it at this size."""
from __future__ import annotations

import math

ROAD_FACTOR = {"urban": 1.3, "semiurban": 1.35, "rural": 1.45}
SPEED_KMH = {"urban": 18, "semiurban": 25, "rural": 28}   # two-wheeler, including traffic or bad roads
STOP_MIN = 6                                                   # parking, handing over, code check, cash


def haversine_km(lat1: float, lng1: float, lat2: float, lng2: float) -> float:
    r = 6371.0088
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp, dl = p2 - p1, math.radians(lng2 - lng1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def road_km(a: tuple, b: tuple, area: str = "semiurban") -> float:
    return haversine_km(a[0], a[1], b[0], b[1]) * ROAD_FACTOR.get(area, 1.35)


def _length(order: list[int], d: list[list[float]]) -> float:
    return sum(d[order[i]][order[i + 1]] for i in range(len(order) - 1))


def plan_round(shop: tuple, stops: list[dict], area: str = "semiurban") -> dict:
    """stops: [{id, name, lat, lng}]. Returns the visiting order, distance and time."""
    pts = [shop] + [(float(s["lat"]), float(s["lng"])) for s in stops]
    n = len(pts)
    if n == 1:
        return {"order": [], "km": 0.0, "minutes": 0}
    d = [[road_km(pts[i], pts[j], area) for j in range(n)] for i in range(n)]
    # nearest neighbour from the shop
    route, left = [0], set(range(1, n))
    while left:
        nxt = min(left, key=lambda j: d[route[-1]][j])
        route.append(nxt)
        left.remove(nxt)
    route.append(0)
    # 2-opt
    improved = True
    while improved:
        improved = False
        for i in range(1, len(route) - 2):
            for k in range(i + 1, len(route) - 1):
                a, b, c, e = route[i - 1], route[i], route[k], route[k + 1]
                if d[a][c] + d[b][e] < d[a][b] + d[c][e] - 1e-9:
                    route[i:k + 1] = reversed(route[i:k + 1])
                    improved = True
    km = _length(route, d)
    minutes = km / SPEED_KMH.get(area, 25) * 60 + STOP_MIN * (n - 1)
    return {"order": [stops[i - 1]["id"] for i in route[1:-1]], "km": round(km, 1), "minutes": round(minutes)}
