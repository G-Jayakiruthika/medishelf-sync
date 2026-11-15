"""Builds the web .glb models from models.json, with only the Python standard library.

    python3 tools/models3d/spec.py && python3 tools/models3d/build_glb.py [out_dir]

Each model becomes one glTF 2.0 binary: one mesh, one primitive per colour/finish, smooth normals where the shape is
round and flat ones on faces. Kept small (a few hundred KB at most) so phones on 3G can load them; pages skip them
entirely in low-data mode. blender_build.py makes higher-quality versions of the same models in Blender.
"""
import json
import math
import os
import struct
import sys

HERE = os.path.dirname(os.path.abspath(__file__))


# ---------- small vector helpers ----------
def sub(a, b): return [a[0] - b[0], a[1] - b[1], a[2] - b[2]]
def add(a, b): return [a[0] + b[0], a[1] + b[1], a[2] + b[2]]
def scale(a, k): return [a[0] * k, a[1] * k, a[2] * k]
def dot(a, b): return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]
def crossv(a, b): return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]
def norm(a):
    n = math.sqrt(dot(a, a)) or 1.0
    return [a[0] / n, a[1] / n, a[2] / n]


def euler(rot):
    """Rotation matrix for XYZ Euler angles in degrees, applied X then Y then Z (Blender's 'XYZ' mode)."""
    x, y, z = (math.radians(v) for v in rot)
    cx, sx, cy, sy, cz, sz = math.cos(x), math.sin(x), math.cos(y), math.sin(y), math.cos(z), math.sin(z)
    rx = [[1, 0, 0], [0, cx, -sx], [0, sx, cx]]
    ry = [[cy, 0, sy], [0, 1, 0], [-sy, 0, cy]]
    rz = [[cz, -sz, 0], [sz, cz, 0], [0, 0, 1]]
    mul = lambda a, b: [[sum(a[i][k] * b[k][j] for k in range(3)) for j in range(3)] for i in range(3)]
    return mul(rz, mul(ry, rx))


def apply(m, v): return [m[0][0] * v[0] + m[0][1] * v[1] + m[0][2] * v[2], m[1][0] * v[0] + m[1][1] * v[1] + m[1][2] * v[2], m[2][0] * v[0] + m[2][1] * v[1] + m[2][2] * v[2]]


class Mesh:
    def __init__(self):
        self.pos, self.nor, self.idx = [], [], []

    def vert(self, p, n):
        self.pos.append(p); self.nor.append(n)
        return len(self.pos) - 1

    def tri(self, a, b, c): self.idx += [a, b, c]

    def quad(self, a, b, c, d): self.idx += [a, b, c, a, c, d]

    def flat_poly(self, pts, n):
        base = [self.vert(p, n) for p in pts]
        for i in range(1, len(base) - 1): self.tri(base[0], base[i], base[i + 1])

    def extend(self, other, m=None, t=(0, 0, 0)):
        off = len(self.pos)
        for p, n in zip(other.pos, other.nor):
            self.pos.append(add(apply(m, p), t) if m else add(p, t)); self.nor.append(norm(apply(m, n)) if m else n)
        self.idx += [i + off for i in other.idx]


# ---------- shapes (same conventions as blender_build.py) ----------
def rbox(w, h, d, r):
    """Rounded box centred on the origin: a subdivided cube whose corners are pushed onto spheres of radius r."""
    r = max(0.0, min(r, w / 2, h / 2, d / 2))
    half = [w / 2, h / 2, d / 2]
    inner = [half[0] - r, half[1] - r, half[2] - r]
    m = Mesh()
    steps = 1 if r < 0.025 else 4 if r < 0.09 else 6   # tiny corners need no rounding; big ones a few rows
    for axis in range(3):
        for sign in (-1, 1):
            u_ax, v_ax = [a for a in range(3) if a != axis]
            grid = []
            for i in range(steps + 1):
                row = []
                for j in range(steps + 1):
                    p = [0.0, 0.0, 0.0]
                    p[axis] = sign * half[axis]
                    p[u_ax] = -half[u_ax] + 2 * half[u_ax] * i / steps
                    p[v_ax] = -half[v_ax] + 2 * half[v_ax] * j / steps
                    c = [max(-inner[k], min(inner[k], p[k])) for k in range(3)]
                    dvec = sub(p, c)
                    if r > 0 and dot(dvec, dvec) > 1e-12:
                        nn = norm(dvec); q = add(c, scale(nn, r))
                    else:
                        nn = [0.0, 0.0, 0.0]; nn[axis] = float(sign); q = p
                    row.append(m.vert(q, nn))
                grid.append(row)
            flip = (sign > 0) != ((u_ax, v_ax) in ((1, 2), (2, 0), (0, 1)))
            for i in range(steps):
                for j in range(steps):
                    a, b, c2, d2 = grid[i][j], grid[i + 1][j], grid[i + 1][j + 1], grid[i][j + 1]
                    m.quad(a, d2, c2, b) if flip else m.quad(a, b, c2, d2)
    return m


def lathe(prof, seg=40):
    """Spins a profile [(radius, y), ...] around Y. Smooth normals around, sharp at profile corners."""
    m = Mesh()
    for k in range(len(prof) - 1):
        (r0, y0), (r1, y1) = prof[k], prof[k + 1]
        dr, dy = r1 - r0, y1 - y0
        if abs(dr) < 1e-9 and abs(dy) < 1e-9: continue
        ln = math.hypot(dr, dy)
        nr, ny = dy / ln, -dr / ln     # outward normal of this profile edge (profile goes up/out -> normal points out)
        ring = []
        for s in range(seg + 1):
            a = 2 * math.pi * s / seg
            ca, sa = math.cos(a), math.sin(a)
            ring.append((m.vert([r0 * ca, y0, r0 * sa], norm([nr * ca, ny, nr * sa])), m.vert([r1 * ca, y1, r1 * sa], norm([nr * ca, ny, nr * sa]))))
        for s in range(seg):
            a0, b0 = ring[s]; a1, b1 = ring[s + 1]
            m.quad(a0, b0, b1, a1)
    return m


def cyl(r, h, seg=32):
    m = lathe([[0, -h / 2], [r, -h / 2], [r, h / 2], [0, h / 2]], seg)
    return m


def sphere(r, seg=32):
    prof = [[r * math.sin(math.pi * i / 16), -r * math.cos(math.pi * i / 16)] for i in range(17)]
    m = Mesh()
    rings = []
    for i, (rr, y) in enumerate(prof):
        rings.append([m.vert([rr * math.cos(2 * math.pi * s / seg), y, rr * math.sin(2 * math.pi * s / seg)],
                             norm([rr * math.cos(2 * math.pi * s / seg), y, rr * math.sin(2 * math.pi * s / seg)]) if r else [0, 1, 0]) for s in range(seg + 1)])
    for i in range(len(rings) - 1):
        for s in range(seg):
            m.quad(rings[i][s], rings[i + 1][s], rings[i + 1][s + 1], rings[i][s + 1])
    return m


def torus(R, r, seg=40, sides=16):
    m = Mesh()
    rings = []
    for i in range(seg + 1):
        a = 2 * math.pi * i / seg
        row = []
        for j in range(sides + 1):
            b = 2 * math.pi * j / sides
            n = [math.cos(b) * math.cos(a), math.sin(b), math.cos(b) * math.sin(a)]
            row.append(m.vert([(R + r * math.cos(b)) * math.cos(a), r * math.sin(b), (R + r * math.cos(b)) * math.sin(a)], n))
        rings.append(row)
    for i in range(seg):
        for j in range(sides):
            m.quad(rings[i][j], rings[i][j + 1], rings[i + 1][j + 1], rings[i + 1][j])
    return m


def extrude(poly, depth):
    """A flat shape in XY pushed out along Z, centred. Caps are fans from the centroid (shapes are star-shaped)."""
    m = Mesh()
    area = sum(poly[i][0] * poly[(i + 1) % len(poly)][1] - poly[(i + 1) % len(poly)][0] * poly[i][1] for i in range(len(poly)))
    if area < 0: poly = poly[::-1]
    cx = sum(p[0] for p in poly) / len(poly); cy = sum(p[1] for p in poly) / len(poly)
    z0, z1 = -depth / 2, depth / 2
    for z, n, order in ((z1, [0, 0, 1], 1), (z0, [0, 0, -1], -1)):
        c = m.vert([cx, cy, z], n)
        ring = [m.vert([p[0], p[1], z], n) for p in poly]
        for i in range(len(ring)):
            a, b = ring[i], ring[(i + 1) % len(ring)]
            m.tri(c, a, b) if order > 0 else m.tri(c, b, a)
    for i in range(len(poly)):
        p, q = poly[i], poly[(i + 1) % len(poly)]
        n = norm([q[1] - p[1], -(q[0] - p[0]), 0])
        a, b, c, d = (m.vert([p[0], p[1], z0], n), m.vert([q[0], q[1], z0], n), m.vert([q[0], q[1], z1], n), m.vert([p[0], p[1], z1], n))
        m.quad(a, b, c, d)
    return m


def link(a, b, r):
    d = sub(b, a); ln = math.sqrt(dot(d, d)) or 1e-6
    m = cyl(r, ln, 16)
    caps = [sphere(r, 16), sphere(r, 16)]
    y = norm(d); x = norm(crossv(y, [0, 0, 1] if abs(y[2]) < 0.9 else [1, 0, 0])); z = crossv(x, y)
    rot = [[x[0], y[0], z[0]], [x[1], y[1], z[1]], [x[2], y[2], z[2]]]
    out = Mesh()
    out.extend(m, rot, add(a, scale(d, 0.5)))
    out.extend(caps[0], None, a); out.extend(caps[1], None, b)
    return out


def part_mesh(p):
    t = p["t"]
    if t == "rbox": m = rbox(*p["s"], p.get("r", 0.08))
    elif t == "cyl": m = cyl(p["r"], p["h"], p.get("seg", 32))
    elif t == "sphere": m = sphere(p["r"], 24)
    elif t == "lathe": m = lathe(p["prof"], p.get("seg", 40))
    elif t == "torus": m = torus(p["R"], p["r"])
    elif t == "extrude": m = extrude(p["poly"], p["depth"])
    elif t == "link": return link(p["a"], p["b"], p["r"])
    else: raise ValueError("unknown part type " + t)
    out = Mesh()
    out.extend(m, euler(p.get("rot", [0, 0, 0])), p.get("p", [0, 0, 0]))
    return out


# ---------- glTF binary ----------
def srgb_to_linear(c):
    c /= 255.0
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def material(hexc, finish):
    rgb = [srgb_to_linear(int(hexc[i:i + 2], 16)) for i in (1, 3, 5)]
    rough = {"gloss": 0.35, "matte": 0.8, "glass": 0.08}.get(finish, 0.4)
    mat = {"name": hexc + "-" + finish, "pbrMetallicRoughness": {"baseColorFactor": rgb + [0.55 if finish == "glass" else 1.0], "metallicFactor": 0.0, "roughnessFactor": rough}}
    if finish == "glass": mat["alphaMode"] = "BLEND"
    return mat


def to_glb(name, parts):
    groups = {}
    for p in parts:
        key = (p["c"].upper(), p.get("m", "gloss"))
        groups.setdefault(key, Mesh()).extend(part_mesh(p))
    gl = {"asset": {"version": "2.0", "generator": "AushadhBox build_glb.py"}, "scene": 0, "scenes": [{"nodes": [0]}],
          "nodes": [{"mesh": 0, "name": name}], "meshes": [{"name": name, "primitives": []}], "materials": [], "accessors": [], "bufferViews": [], "buffers": []}
    blob = bytearray()

    def view(data, target):
        while len(blob) % 4: blob.append(0)
        gl["bufferViews"].append({"buffer": 0, "byteOffset": len(blob), "byteLength": len(data), "target": target})
        blob.extend(data)
        return len(gl["bufferViews"]) - 1

    for (hexc, finish), m in groups.items():
        if not m.idx: continue
        pos = b"".join(struct.pack("<3f", *v) for v in m.pos)
        nor = b"".join(struct.pack("<3f", *v) for v in m.nor)
        big = len(m.pos) > 65535
        idx = struct.pack("<%d%s" % (len(m.idx), "I" if big else "H"), *m.idx)
        lo = [min(v[k] for v in m.pos) for k in range(3)]; hi = [max(v[k] for v in m.pos) for k in range(3)]
        acc = len(gl["accessors"])
        gl["accessors"] += [
            {"bufferView": view(pos, 34962), "componentType": 5126, "count": len(m.pos), "type": "VEC3", "min": lo, "max": hi},
            {"bufferView": view(nor, 34962), "componentType": 5126, "count": len(m.nor), "type": "VEC3"},
            {"bufferView": view(idx, 34963), "componentType": 5125 if big else 5123, "count": len(m.idx), "type": "SCALAR"}]
        gl["materials"].append(material(hexc, finish))
        gl["meshes"][0]["primitives"].append({"attributes": {"POSITION": acc, "NORMAL": acc + 1}, "indices": acc + 2, "material": len(gl["materials"]) - 1})
    while len(blob) % 4: blob.append(0)
    gl["buffers"].append({"byteLength": len(blob)})
    js = json.dumps(gl, separators=(",", ":")).encode()
    js += b" " * ((4 - len(js) % 4) % 4)
    total = 12 + 8 + len(js) + 8 + len(blob)
    return struct.pack("<III", 0x46546C67, 2, total) + struct.pack("<II", len(js), 0x4E4F534A) + js + struct.pack("<II", len(blob), 0x004E4942) + bytes(blob)


def main(out_dirs):
    with open(os.path.join(HERE, "models.json")) as f:
        models = json.load(f)
    for name, parts in models.items():
        data = to_glb(name, parts)
        for d in out_dirs:
            os.makedirs(d, exist_ok=True)
            with open(os.path.join(d, name + ".glb"), "wb") as f: f.write(data)
        print("%-12s %3d parts %7.1f KB" % (name, len(parts), len(data) / 1024))


if __name__ == "__main__":
    root = os.path.abspath(os.path.join(HERE, "..", ".."))
    main(sys.argv[1:] or [os.path.join(root, "public_html", "assets", "3d"), os.path.join(root, "pharmacy-platform", "public", "assets", "3d")])
