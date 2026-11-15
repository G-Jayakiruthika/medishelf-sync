"""Pretend to be a fridge thermometer, against a real server (no hardware needed).

    python3 simulate.py --server http://127.0.0.1:8090 --code 123456 --temps 5,5.2,9.5,9.8,10.1

Uses the same logic.py as the firmware. Prints what the server answers after each report."""
import argparse
import json
import time
import urllib.request

import logic


def post(server, action, body, token=None):
    h = {"Content-Type": "application/json"}
    if token:
        h["Authorization"] = "Bearer " + token
    req = urllib.request.Request(server.rstrip("/") + "/device.php?action=" + action, json.dumps(body).encode(), h, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--server", required=True); ap.add_argument("--code"); ap.add_argument("--token")
    ap.add_argument("--temps", default="5.1,5.3,4.9"); ap.add_argument("--minutes-apart", type=int, default=5)
    a = ap.parse_args()
    token = a.token
    if not token:
        s, d = post(a.server, "claim", {"code": a.code})
        print("claim", s, {k: v for k, v in d.items() if k != "token"})
        token = d.get("token")
        if not token:
            return 1
    buf = logic.Buffer()
    temps = [float(x) for x in a.temps.split(",")]
    start = int(time.time()) - len(temps) * a.minutes_apart * 60
    for i, c in enumerate(temps):
        buf.add(start + i * a.minutes_apart * 60, c)
        s, d = post(a.server, "report", {"readings": buf.batch(), "battery": 87}, token)
        if s == 200:
            buf.sent(len(buf.batch()))
        print(f"{c:5.1f} °C → {s} {d}")
    print("token:", token[:6] + "…")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
