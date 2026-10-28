#!/usr/bin/env python3
"""AushadhBox pillbox simulator: talks to the server exactly like the ESP32 box does.

Use it to demonstrate or test the IoT flow without building the hardware.

  python3 pillbox_sim.py --server https://aushadhbox.in pair 123456      # code from Account -> Smart pillbox
  python3 pillbox_sim.py status                                          # today's dose times per lid
  python3 pillbox_sim.py open 1                                          # open lid 1 (morning) now
  python3 pillbox_sim.py open 1 --at "2026-11-02 08:10"                  # ...or at a given time (within 3 days)
  python3 pillbox_sim.py offline-open 3 ; python3 pillbox_sim.py flush   # keep an opening while "offline", send later

The token is kept in .pillbox-sim.json next to this file (delete it to pair again).
Only the Python standard library is needed.
"""
import argparse, json, os, sys, time, urllib.request, urllib.error
from datetime import datetime

STATE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".pillbox-sim.json")
SLOTS = {"m": "morning", "a": "afternoon", "e": "evening", "b": "bedtime"}
OUTCOMES = {"taken": "dose ticked in the app", "again": "opened AGAIN after the dose: family warned about a double dose",
            "outside": "outside every dose window (e.g. refilling): only recorded", "already": "dose was already ticked",
            "nodose": "no medicine due at this time", "ok": "recorded"}


def load():
    try:
        with open(STATE) as f: return json.load(f)
    except (OSError, ValueError): return {}


def save(s):
    with open(STATE, "w") as f: json.dump(s, f, indent=2)


def call(server, method, action, body=None, token=None):
    req = urllib.request.Request(server.rstrip("/") + "/device.php?action=" + action, method=method,
                                 data=json.dumps(body).encode() if body is not None else None,
                                 headers={"Content-Type": "application/json", "User-Agent": "AushadhBox-Sim/1.0"})
    if token:
        req.add_header("Authorization", "Bearer " + token); req.add_header("X-Device-Token", token)
    try:
        with urllib.request.urlopen(req, timeout=15) as r: return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        try: msg = json.loads(e.read().decode()).get("message", "")
        except ValueError: msg = ""
        sys.exit(f"Server said {e.code}: {msg}")


def show(sched):
    print(f"Box: {sched.get('name')}")
    if not sched.get("slots"): print("  No doses due today."); return
    for s in sched["slots"]:
        print(f"  lid {s['lid']}  {s['time']}  {SLOTS[s['slot']]:<9}  {s['count']} medicine(s)  {'done' if s['done'] else 'waiting'}")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--server", help="AushadhBox address, e.g. https://aushadhbox.in or http://127.0.0.1:8080")
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("pair"); p.add_argument("code")
    sub.add_parser("status")
    for name in ("open", "offline-open"):
        p = sub.add_parser(name); p.add_argument("lid", type=int, choices=[1, 2, 3, 4]); p.add_argument("--at", help='"YYYY-MM-DD HH:MM" (local time)')
    sub.add_parser("flush")
    a = ap.parse_args()

    st = load()
    server = a.server or st.get("server") or "http://127.0.0.1:8080"
    if a.cmd == "pair":
        r = call(server, "POST", "claim", {"code": a.code, "hw": "SIMULATOR", "fw": "sim-1.0"})
        save({"server": server, "token": r["token"], "name": r["name"], "queue": []})
        print(f"Paired as \"{r['name']}\" ({r['compartments']} lid{'s' if r['compartments'] > 1 else ''}).")
        return
    if not st.get("token"): sys.exit("Not paired yet: run  pair <code>  first.")
    tok = st["token"]
    if a.cmd == "status":
        show(call(server, "GET", "sync", token=tok)); return
    t = int(datetime.strptime(a.at, "%Y-%m-%d %H:%M").timestamp()) if getattr(a, "at", None) else int(time.time())
    if a.cmd == "offline-open":
        st.setdefault("queue", []).append({"type": "open", "lid": a.lid, "t": t})
        st["queue"].append({"type": "close", "lid": a.lid, "t": t + 8}); save(st)
        print(f"Kept offline: lid {a.lid} at {datetime.fromtimestamp(t):%H:%M}. Run  flush  to send."); return
    events = st.get("queue", []) if a.cmd == "flush" else [{"type": "open", "lid": a.lid, "t": t}, {"type": "close", "lid": a.lid, "t": t + 8}]
    if not events: print("Nothing waiting."); return
    r = call(server, "POST", "events", {"fw": "sim-1.0", "battery": 80, "events": events}, token=tok)
    for e, res in zip(events, r.get("results", [])):
        if e["type"] == "open": print(f"lid {e['lid']} opened {datetime.fromtimestamp(e['t']):%d %b %H:%M} -> {OUTCOMES.get(res, res)}")
    if a.cmd == "flush": st["queue"] = []; save(st)
    show(r)


if __name__ == "__main__":
    main()
