"""AushadhBox 3D models, described once as simple parts.

Both builders read the models.json this file writes:
  build_glb.py      pure Python, makes the .glb files the web pages use (runs anywhere, no Blender needed)
  blender_build.py  inside Blender: the same models with bevels, smooth shading and real materials, exported as .glb

A part:  {"t": type, ...size, "p": [x, y, z] position, "rot": [x, y, z] degrees, "c": "#rrggbb", "m": "gloss"|"matte"|"glass"}
Types:   rbox  s=[w, h, d], r=corner radius      cyl  r, h, seg        sphere  r
         lathe prof=[[radius, y], ...] (spun around Y)                  torus  R, r
         extrude poly=[[x, y], ...] (in the XY plane, star-shaped), depth (along Z)
         link  a=[x,y,z], b=[x,y,z], r (a rod between two points)
Units are roughly decimetres; +Y is up, the camera looks from +Z.

    python3 tools/models3d/spec.py   # writes tools/models3d/models.json
"""
import json
import math
import os

# Palette: the app's indigo (ai-iro), leaf green, warm amber, cream and coral
INK, AI, GREEN, LEAF, AMBER, CREAM, CORAL, SKY, WHITE, GREY, STEEL = (
    "#16202E", "#1F3358", "#3E7D49", "#7BC08A", "#E8A33C", "#F4EFE6", "#E46A5A", "#5AA9E6", "#FAFAF7", "#9AA4AE", "#C9D1D8")


def part(t, c, p=(0, 0, 0), rot=(0, 0, 0), m="gloss", **k):
    return dict(t=t, c=c, p=list(p), rot=list(rot), m=m, **k)


def rbox(s, c, p=(0, 0, 0), r=0.08, **k): return part("rbox", c, p, s=list(s), r=r, **k)
def cyl(r, h, c, p=(0, 0, 0), seg=32, **k): return part("cyl", c, p, r=r, h=h, seg=seg, **k)
def sphere(r, c, p=(0, 0, 0), **k): return part("sphere", c, p, r=r, **k)
def lathe(prof, c, p=(0, 0, 0), **k): return part("lathe", c, p, prof=[list(x) for x in prof], **k)
def torus(R, r, c, p=(0, 0, 0), **k): return part("torus", c, p, R=R, r=r, **k)
def extrude(poly, depth, c, p=(0, 0, 0), **k): return part("extrude", c, p, poly=[list(x) for x in poly], depth=depth, **k)
def link(a, b, r, c, **k): return part("link", c, (0, 0, 0), a=list(a), b=list(b), r=r, **k)


def bottle(x, z, h=1.0, body=CREAM, cap=GREEN, y=0.0, label=None):
    r = 0.28 * h
    out = [lathe([[0, 0], [r, 0], [r, 0.62 * h], [r * 0.62, 0.74 * h], [r * 0.5, 0.78 * h], [0, 0.78 * h]], body, (x, y, z), m="glass" if body == SKY else "gloss"),
           cyl(r * 0.56, 0.16 * h, cap, (x, y + 0.86 * h, z))]
    if label: out.append(cyl(r * 1.01, 0.3 * h, label, (x, y + 0.3 * h, z), m="matte"))
    return out


def capsule(x, y, z, a=AMBER, b=WHITE, rot=(0, 0, 90), s=1.0):
    r, h = 0.1 * s, 0.18 * s
    half = lambda c, sign: lathe([[0, 0], [r * 0.7, sign * r * 0.3], [r, sign * r * 0.7], [r, sign * (r + h / 2)], [0, sign * (r + h / 2)]] if sign > 0 else
                                 [[0, sign * (r + h / 2)], [r, sign * (r + h / 2)], [r, sign * r * 0.7], [r * 0.7, sign * r * 0.3], [0, 0]], c, (x, y, z), rot=rot)
    return [half(a, 1), half(b, -1)]


def cross(cx, cy, z, s, c, depth=0.08):
    a, b = s / 2, s / 6
    return extrude([[-b, -a], [b, -a], [b, -b], [a, -b], [a, b], [b, b], [b, a], [-b, a], [-b, b], [-a, b], [-a, -b], [-b, -b]], depth, c, (cx, cy, z))


def heart(cx, cy, z, s, c, depth=0.25):
    pts = []
    for i in range(48):
        t = 2 * math.pi * i / 48
        pts.append([s * 16 * math.sin(t) ** 3 / 17, s * (13 * math.cos(t) - 5 * math.cos(2 * t) - 2 * math.cos(3 * t) - math.cos(4 * t)) / 17])
    return extrude(pts, depth, c, (cx, cy, z))


def gear(cx, cy, z, R, teeth, c, depth=0.25):
    pts = []
    for i in range(teeth * 4):
        a = 2 * math.pi * i / (teeth * 4)
        rr = R if (i % 4) in (1, 2) else R * 0.78
        pts.append([rr * math.cos(a), rr * math.sin(a)])
    return extrude(pts, depth, c, (cx, cy, z))


def phone(x=0, y=0, z=0, screen=AI):
    return [rbox((1.3, 2.5, 0.16), INK, (x, y, z), r=0.18), rbox((1.16, 2.24, 0.02), screen, (x, y + 0.02, z + 0.08), r=0.08, m="glass")]


def qr(x, y, z, size, c=WHITE, dark=INK, n=7, seed=7):
    out = [rbox((size, size, 0.02), c, (x, y, z), r=0.02, m="matte")]
    cell = size / (n + 2)
    rnd = seed
    for i in range(n):
        for j in range(n):
            finder = (i < 2 and j < 2) or (i < 2 and j > n - 3) or (i > n - 3 and j < 2)
            rnd = (rnd * 1103515245 + 12345) % 2 ** 31
            if finder or rnd % 3 == 0:
                out.append(rbox((cell * 0.92, cell * 0.92, 0.03), dark, (x - size / 2 + cell * (j + 1.5), y + size / 2 - cell * (i + 1.5), z + 0.02), r=0.005, m="matte"))
    return out


def bust(x, z, c=SKY, skin="#D9A27A", y=0.0, s=1.0):
    return [lathe([[0, 0], [0.62 * s, 0], [0.66 * s, 0.32 * s], [0.5 * s, 0.62 * s], [0.22 * s, 0.74 * s], [0, 0.76 * s]], c, (x, y, z)),
            sphere(0.34 * s, skin, (x, y + 1.1 * s, z))]


def wheel(x, y, z):
    return [torus(0.36, 0.12, INK, (x, y, z), rot=(0, 0, 90)), cyl(0.2, 0.12, GREY, (x, y, z), rot=(0, 0, 90))]


def scooter():
    return [*wheel(0, 0.48, -1.1), *wheel(0, 0.48, 1.1),
            rbox((0.62, 0.3, 1.9), CORAL, (0, 0.72, 0.0), r=0.12), rbox((0.56, 0.7, 0.5), CORAL, (0, 1.05, 0.95), r=0.15),
            link((0, 0.6, 1.1), (0, 1.9, 1.25), 0.05, STEEL), link((-0.45, 1.95, 1.25), (0.45, 1.95, 1.25), 0.05, INK),
            rbox((0.5, 0.16, 0.7), INK, (0, 1.0, -0.35), r=0.07),
            rbox((0.9, 0.8, 0.8), CREAM, (0, 1.5, -0.85), r=0.06), cross(0, 1.5, -0.44, 0.42, GREEN, 0.03)]


MODELS = {
    # ---------- Family app ----------
    "cabinet": [  # open medicine cupboard: back, sides, top, bottom, shelves, door swung open
        rbox((2.4, 2.8, 0.08), CREAM, (0, 1.4, -0.42), r=0.03, m="matte"),
        rbox((0.1, 2.8, 0.9), WHITE, (-1.15, 1.4, 0), r=0.04), rbox((0.1, 2.8, 0.9), WHITE, (1.15, 1.4, 0), r=0.04),
        rbox((2.4, 0.12, 0.9), WHITE, (0, 2.76, 0), r=0.04), rbox((2.4, 0.12, 0.9), WHITE, (0, 0.06, 0), r=0.04),
        rbox((2.2, 0.06, 0.8), STEEL, (0, 1.0, 0.0), r=0.02), rbox((2.2, 0.06, 0.8), STEEL, (0, 1.9, 0.0), r=0.02),
        rbox((2.3, 2.7, 0.08), GREEN, (1.9, 1.4, 1.2), rot=(0, -78, 0), r=0.04), cross(2.0, 1.95, 1.45, 0.45, WHITE, 0.03) | {"rot": [0, -78, 0]},
        *bottle(-0.7, 0.05, 0.8, CREAM, GREEN, 0.12, AMBER), *bottle(-0.15, 0.05, 0.65, SKY, WHITE, 0.12), *bottle(0.5, 0.0, 0.7, WHITE, CORAL, 0.12, AI),
        *bottle(-0.55, 0.0, 0.7, WHITE, AI, 1.03), rbox((0.7, 0.45, 0.4), AMBER, (0.35, 1.26, 0.05), r=0.04, m="matte"),
        *capsule(-0.4, 2.05, 0.1), *capsule(0.1, 2.05, 0.2, CORAL), rbox((0.6, 0.3, 0.4), SKY, (0.55, 2.08, 0.0), r=0.04, m="matte"),
    ],
    "scan": [*phone(), *qr(0, 0.15, 0.1, 0.9), rbox((1.0, 0.04, 0.03), CORAL, (0, 0.15, 0.14), r=0.01, m="matte"),
             rbox((0.5, 0.16, 0.03), LEAF, (0, -0.75, 0.1), r=0.06)],
    "doses": [  # the smart pillbox: four lids, one per dose time
        rbox((3.2, 0.5, 1.1), WHITE, (0, 0.25, 0), r=0.15),
        *[rbox((0.72, 0.16, 1.0), c, (-1.17 + i * 0.78, 0.58, 0), r=0.08) for i, c in enumerate([AMBER, CORAL, SKY, AI])],
        rbox((0.72, 0.16, 1.0), AMBER, (-1.17, 0.95, -0.3), rot=(-62, 0, 0), r=0.08),
        *capsule(-1.25, 0.66, 0.1, GREEN, WHITE, (0, 30, 90)), sphere(0.13, WHITE, (-1.0, 0.64, -0.15)),
        sphere(0.06, LEAF, (1.45, 0.42, 0.56), m="glass"), sphere(0.06, LEAF, (1.2, 0.42, 0.56), m="glass"),
    ],
    "notebook": [  # health notebook (お薬手帳) with a pen and a heart
        rbox((2.2, 0.22, 2.8), GREEN, (0, 0.11, 0), r=0.06), rbox((2.05, 0.12, 2.7), WHITE, (0.05, 0.2, 0), r=0.03, m="matte"),
        *[rbox((1.5, 0.01, 0.06), GREY, (0.15, 0.27, -0.9 + i * 0.35), r=0.01, m="matte") for i in range(6)],
        heart(-0.55, 0.45, -1.05, 0.28, CORAL, 0.12) | {"rot": [-90, 0, 0]},
        link((1.25, 0.45, -1.1), (0.2, 0.36, 1.3), 0.07, AI), lathe([[0, 0], [0.07, 0.12], [0, 0.12]], INK, (0.2, 0.36, 1.3), rot=(-95, 0, 23)),
    ],
    "safety": [  # shield with a check mark
        extrude([[0, 1.5], [1.2, 1.1], [1.15, 0.0], [0.7, -0.9], [0, -1.4], [-0.7, -0.9], [-1.15, 0.0], [-1.2, 1.1]], 0.35, AI, (0, 1.5, 0)),
        extrude([[0, 1.25], [0.98, 0.92], [0.94, 0.0], [0.57, -0.75], [0, -1.15], [-0.57, -0.75], [-0.94, 0.0], [-0.98, 0.92]], 0.1, WHITE, (0, 1.5, 0.23)),
        link((-0.45, 1.5, 0.32), (-0.1, 1.15, 0.32), 0.11, GREEN), link((-0.1, 1.15, 0.32), (0.5, 1.95, 0.32), 0.11, GREEN),
        sphere(0.11, GREEN, (-0.1, 1.15, 0.32)),
    ],
    "guide": [  # a tablet playing a how-to video
        rbox((2.8, 2.0, 0.14), INK, (0, 1.2, 0), r=0.16), rbox((2.6, 1.8, 0.02), SKY, (0, 1.2, 0.07), r=0.06, m="glass"),
        extrude([[-0.25, -0.35], [0.4, 0], [-0.25, 0.35]], 0.06, WHITE, (0.05, 1.2, 0.08)), rbox((2.2, 0.06, 0.02), WHITE, (0, 0.5, 0.09), r=0.02),
        rbox((0.8, 0.07, 0.03), CORAL, (-0.7, 0.5, 0.1), r=0.02), link((-0.4, 0.2, -0.3), (0, 0.2, -0.6), 0.06, GREY), link((0.4, 0.2, -0.3), (0, 0.2, -0.6), 0.06, GREY),
    ],
    "account": [*bust(0, 0, AI), rbox((0.7, 0.45, 0.06), WHITE, (0.0, 0.38, 0.6), r=0.05), cross(-0.18, 0.38, 0.64, 0.2, GREEN, 0.02),
                gear(0.95, 1.55, 0, 0.42, 9, AMBER, 0.18), cyl(0.14, 0.2, WHITE, (0.95, 1.55, 0.0), rot=(90, 0, 0))],
    "care": [  # mother and child, a heart: Care+
        *bust(-0.55, 0, CORAL, s=1.1), *bust(0.6, 0.15, AMBER, s=0.7), heart(0.05, 2.0, 0, 0.42, GREEN, 0.28),
        cross(0.05, 2.0, 0.17, 0.32, WHITE, 0.05),
    ],
    "shops": [  # a neighbourhood pharmacy
        rbox((3.4, 2.2, 1.8), CREAM, (0, 1.1, 0), r=0.05, m="matte"), rbox((3.6, 0.2, 2.0), INK, (0, 2.3, 0), r=0.04),
        *[rbox((0.42, 0.08, 0.7), GREEN if i % 2 == 0 else WHITE, (-1.5 + i * 0.43, 1.92, 1.15), rot=(25, 0, 0), r=0.01) for i in range(8)],
        rbox((1.1, 1.3, 0.05), SKY, (-0.75, 0.75, 0.91), r=0.03, m="glass"), rbox((0.8, 1.5, 0.05), AI, (0.95, 0.75, 0.91), r=0.03),
        rbox((0.9, 0.9, 0.12), WHITE, (0, 2.85, 0.5), r=0.08), cross(0, 2.85, 0.56, 0.6, GREEN, 0.06),
        *bottle(-1.05, 0.95, 0.35, WHITE, CORAL, 0.55), *bottle(-0.55, 0.95, 0.35, WHITE, AMBER, 0.55),
    ],
    "card": [  # the AushadhBox family card with its QR
        rbox((3.0, 1.9, 0.08), AI, (0, 1.1, 0), r=0.14), rbox((3.0, 0.3, 0.09), GREEN, (0, 1.6, 0), r=0.05),
        *qr(0.75, 0.95, 0.06, 1.0), rbox((1.1, 0.12, 0.02), WHITE, (-0.7, 1.1, 0.06), r=0.04), rbox((0.8, 0.1, 0.02), STEEL, (-0.85, 0.85, 0.06), r=0.04),
        sphere(0.25, AMBER, (-1.05, 0.45, 0.08)),
    ],
    "hero": [  # the AushadhBox box with a phone beside it (landing page)
        rbox((3.2, 0.5, 1.1), WHITE, (0, 0.25, 0), r=0.15),
        *[rbox((0.72, 0.16, 1.0), c, (-1.17 + i * 0.78, 0.58, 0), r=0.08) for i, c in enumerate([AMBER, CORAL, SKY, AI])],
        *[part | {"p": [part["p"][0] + 2.4, part["p"][1] + 1.3, part["p"][2] - 0.4], "rot": [0, -20, 0]} for part in phone()],
        heart(2.4, 1.6, -0.3, 0.2, CORAL, 0.06), *capsule(-0.4, 0.15, 0.95, GREEN), *capsule(0.3, 0.15, 1.0, CORAL, WHITE, (0, 70, 90)),
    ],

    # ---------- Pharmacy portal ----------
    "ph_home": "shops",
    "ph_counter": [  # counter QR stand and a scanner
        rbox((3.4, 1.2, 1.4), GREEN, (0, 0.6, 0), r=0.06, m="matte"), rbox((3.6, 0.1, 1.6), CREAM, (0, 1.25, 0), r=0.03),
        rbox((0.9, 1.2, 0.08), WHITE, (-0.9, 1.95, 0), rot=(-12, 0, 0), r=0.05), *[q | {"rot": [-12, 0, 0]} for q in qr(-0.9, 2.0, 0.05, 0.7)],
        rbox((0.4, 0.3, 0.8), INK, (0.9, 1.55, 0.1), rot=(0, 0, 0), r=0.1), rbox((0.22, 0.6, 0.22), INK, (0.9, 1.6, 0.45), rot=(30, 0, 0), r=0.08),
        rbox((0.3, 0.02, 0.02), CORAL, (0.9, 1.4, -0.32), r=0.005, m="matte"),
    ],
    "ph_orders": [  # a pharmacy bag
        lathe([[0, 0], [0.9, 0], [1.0, 1.9], [0, 1.9]], CREAM, (0, 0, 0), rot=(0, 45, 0)) | {"seg": 4},
        cross(0, 1.0, 0.72, 0.6, GREEN, 0.04), torus(0.35, 0.05, INK, (0, 1.9, 0), rot=(90, 0, 0)),
        *bottle(0.25, -0.2, 0.5, WHITE, CORAL, 1.65), rbox((0.5, 0.35, 0.2), AMBER, (-0.3, 1.95, -0.1), r=0.03),
    ],
    "ph_stock": [  # shelves of stock
        *[rbox((3.2, 0.08, 1.0), STEEL, (0, 0.05 + i * 0.95, 0), r=0.02) for i in range(4)],
        rbox((0.08, 2.95, 1.0), STEEL, (-1.6, 1.45, 0), r=0.02), rbox((0.08, 2.95, 1.0), STEEL, (1.6, 1.45, 0), r=0.02),
        *[rbox((0.42, 0.3 + (i * 7 % 3) * 0.12, 0.55), [AMBER, SKY, CORAL, LEAF, WHITE, AI][i % 6], (-1.25 + (i % 6) * 0.5, 0.1 + 0.15 + (i * 7 % 3) * 0.06 + (i // 6) * 0.95, 0), r=0.03, m="matte") for i in range(15)],
    ],
    "ph_network": [  # linked pharmacies
        *[sphere(0.32, c, p) for c, p in [(GREEN, (0, 1.2, 0)), (AMBER, (-1.5, 2.0, -0.4)), (SKY, (1.6, 2.1, -0.2)), (CORAL, (-1.3, 0.2, 0.5)), (AI, (1.4, 0.3, 0.6))]],
        *[link(a, b, 0.05, GREY) for a, b in [((0, 1.2, 0), (-1.5, 2.0, -0.4)), ((0, 1.2, 0), (1.6, 2.1, -0.2)), ((0, 1.2, 0), (-1.3, 0.2, 0.5)), ((0, 1.2, 0), (1.4, 0.3, 0.6)), ((-1.5, 2.0, -0.4), (1.6, 2.1, -0.2))]],
        cross(0, 1.2, 0.3, 0.3, WHITE, 0.04),
    ],
    "ph_delivery": scooter(),
    "ph_devices": [  # medicine fridge with a thermometer
        rbox((1.8, 2.9, 1.4), WHITE, (0, 1.45, 0), r=0.1), rbox((1.6, 2.6, 0.04), SKY, (0, 1.45, 0.71), r=0.05, m="glass"),
        *[rbox((1.5, 0.04, 1.1), STEEL, (0, 0.6 + i * 0.7, 0), r=0.01) for i in range(3)],
        *[rbox((0.3, 0.25, 0.3), c, (-0.45 + j * 0.45, 0.75 + i * 0.7, 0.1), r=0.03, m="matte") for i in range(3) for j, c in enumerate([AMBER, CORAL, CREAM])],
        cyl(0.1, 1.4, WHITE, (1.25, 1.6, 0.4)), cyl(0.06, 0.6, CORAL, (1.25, 1.2, 0.47)), sphere(0.17, CORAL, (1.25, 0.85, 0.4)),
    ],
    "ph_payments": [*[p | {"p": [p["p"][0] - 0.6, p["p"][1] + 1.3, p["p"][2]], "rot": [0, 15, 0]} for p in phone(screen=GREEN)],
                    *qr(-0.6, 1.4, 0.12, 0.8, WHITE, INK, seed=3),
                    *[cyl(0.5, 0.12, AMBER, (1.0, 0.06 + i * 0.13, 0.3)) for i in range(5)], cyl(0.5, 0.12, AMBER, (1.25, 0.5, 0.95), rot=(70, 0, 0))],
    "ph_reports": [rbox((3.4, 0.08, 1.6), CREAM, (0, 0.04, 0), r=0.03),
                   *[rbox((0.5, h, 0.5), c, (-1.2 + i * 0.8, 0.08 + h / 2, 0), r=0.04) for i, (h, c) in enumerate([(0.8, SKY), (1.3, LEAF), (1.0, AMBER), (2.0, GREEN)])],
                   link((-1.2, 1.2, 0.4), (-0.4, 1.7, 0.4), 0.05, CORAL), link((-0.4, 1.7, 0.4), (0.4, 1.4, 0.4), 0.05, CORAL), link((0.4, 1.4, 0.4), (1.2, 2.4, 0.4), 0.05, CORAL)],
    "ph_staff": [*bust(-1.0, 0, SKY, s=0.85), *bust(0, 0.2, GREEN, s=1.0), *bust(1.0, 0, AMBER, s=0.85), cross(0, 0.5, 0.82, 0.22, WHITE, 0.03)],
    "ph_settings": [gear(-0.4, 1.3, 0, 0.9, 10, AI, 0.3), cyl(0.3, 0.32, WHITE, (-0.4, 1.3, 0), rot=(90, 0, 0)), gear(0.85, 0.55, 0.2, 0.55, 8, AMBER, 0.25),
                    cyl(0.18, 0.27, WHITE, (0.85, 0.55, 0.2), rot=(90, 0, 0))],
}


def resolved():
    out = {}
    for k, v in MODELS.items():
        out[k] = MODELS[v] if isinstance(v, str) else v
    return out


if __name__ == "__main__":
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "models.json")
    with open(path, "w") as f:
        json.dump(resolved(), f, separators=(",", ":"))
    print("wrote", path, len(MODELS), "models")
