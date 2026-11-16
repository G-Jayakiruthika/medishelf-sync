#!/usr/bin/env python3
"""Load test: many families using the app at the same time. Python standard library only.

    php tests/load_seed.php 200                      # test families load001..load200@load.test
    python3 tests/load_test.py http://127.0.0.1:8080 --users 10,50,100,200 --seconds 20
    php tests/load_seed.php clean

Each virtual user signs in once, then loops like a real person: open the app (session + bootstrap), tick a dose,
look at the medicine encyclopedia, open the home page. Prints requests per second, response times (median, 95th and
99th percentile) and errors for each level. Exit code 1 if any level has more than 1% errors (server errors or
timeouts; a polite 503 "busy, try again" counts separately).
"""
import argparse
import http.cookiejar
import json
import random
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request


class User:
    def __init__(self, base, n):
        self.base = base
        self.email = "load%03d@load.test" % n
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ""
        self.items = []

    def call(self, path, body=None, timeout=30):
        h = {"Accept": "application/json"}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            h.update({"Content-Type": "application/json", "X-CSRF-Token": self.csrf, "Origin": self.base})
        req = urllib.request.Request(self.base + path, data=data, headers=h, method="POST" if body is not None else "GET")
        t0 = time.perf_counter()
        try:
            with self.op.open(req, timeout=timeout) as r:
                raw = r.read()
                code = r.status
        except urllib.error.HTTPError as e:
            raw, code = e.read(), e.code
        except Exception:
            return time.perf_counter() - t0, 0, None
        dt = time.perf_counter() - t0
        try:
            j = json.loads(raw) if raw[:1] in (b"{", b"[") else None
        except ValueError:
            j = None
        return dt, code, j

    def sign_in(self):
        _, _, j = self.call("/api.php?action=session")
        self.csrf = (j or {}).get("csrf", "")
        _, code, j = self.call("/api.php?action=login", {"email": self.email, "password": "load test password"})
        if code != 200:
            return False
        _, _, j = self.call("/api.php?action=session")
        self.csrf = (j or {}).get("csrf", self.csrf)
        _, _, b = self.call("/api.php?action=bootstrap")
        self.items = [i["id"] for i in (b or {}).get("items", [])]
        return True

    def one_round(self, out):
        steps = [("session", "/api.php?action=session", None), ("bootstrap", "/api.php?action=bootstrap", None)]
        if self.items:
            steps.append(("dose_toggle", "/api.php?action=dose_toggle", {"item_id": random.choice(self.items), "slot": random.choice(["m", "e"]), "taken": random.random() < 0.7}))
        steps.append(("medicines", "/medicines?q=" + random.choice(["para", "metformin", "amlo", "atorva", "cetiri"]), None))
        steps.append(("home", "/", None))
        for name, path, body in steps:
            dt, code, _ = self.call(path, body)
            out.append((name, dt, code))


def level(base, users, seconds):
    people = [User(base, i + 1) for i in range(users)]
    ok = [None] * users

    def login(i):
        ok[i] = people[i].sign_in()
    th = [threading.Thread(target=login, args=(i,)) for i in range(users)]
    for t in th: t.start()
    for t in th: t.join()
    if not all(ok):
        print("  %d of %d sign-ins failed (run tests/load_seed.php with enough users)" % (ok.count(False), users))
    results, stop = [], time.time() + seconds
    lock = threading.Lock()

    def run(u):
        mine = []
        while time.time() < stop:
            u.one_round(mine)
            time.sleep(random.uniform(0.2, 1.0))   # people pause between taps
        with lock:
            results.extend(mine)
    th = [threading.Thread(target=run, args=(u,)) for u in people if u.csrf]
    t0 = time.time()
    for t in th: t.start()
    for t in th: t.join()
    elapsed = time.time() - t0
    lat = sorted(r[1] for r in results)
    errors = [r for r in results if r[2] == 0 or r[2] >= 500 and r[2] != 503]
    busy = [r for r in results if r[2] == 503]
    other = [r for r in results if 400 <= r[2] < 500]
    q = lambda p: lat[min(len(lat) - 1, int(p * len(lat)))] * 1000 if lat else 0
    print("  %3d users: %6d requests, %6.1f req/s, median %5.0f ms, p95 %6.0f ms, p99 %6.0f ms, errors %d (%.2f%%), busy 503 %d, 4xx %d"
          % (users, len(results), len(results) / elapsed, q(0.5), q(0.95), q(0.99), len(errors), 100 * len(errors) / max(1, len(results)), len(busy), len(other)))
    by = {}
    for n, dt, c in results: by.setdefault(n, []).append(dt * 1000)
    print("            " + "  ".join("%s %d ms" % (n, statistics.median(v)) for n, v in sorted(by.items())))
    return len(errors) / max(1, len(results))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("base", nargs="?", default="http://127.0.0.1:8080")
    ap.add_argument("--users", default="10,50,100")
    ap.add_argument("--seconds", type=int, default=20)
    a = ap.parse_args()
    worst = 0.0
    for n in [int(x) for x in a.users.split(",")]:
        worst = max(worst, level(a.base.rstrip("/"), n, a.seconds))
    sys.exit(1 if worst > 0.01 else 0)


if __name__ == "__main__":
    main()
