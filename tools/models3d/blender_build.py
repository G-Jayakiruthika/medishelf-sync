"""Builds the AushadhBox 3D models in Blender (4.x) from models.json and exports each as .glb.

The same models.json drives build_glb.py (plain Python, used for the web copies). This script gives the
higher-quality versions: bevelled edges, smooth shading, Principled BSDF materials (glass uses transmission),
one collection per model, and optional preview renders.

Run headless:
    blender -b -P tools/models3d/blender_build.py -- --out public_html/assets/3d --renders tools/models3d/renders
Or open Blender > Scripting > open this file > Run Script (it writes next to the .blend / into the folders below).

Options after "--":
    --out DIR       where to write <model>.glb (default: tools/models3d/blender_glb)
    --renders DIR   also render a 800x800 PNG of each model with Eevee
    --only a,b      build only these models
    --keep          keep the scene (do not clear it first), for editing by hand afterwards
"""
import json
import math
import os
import sys

import bmesh
import bpy
from mathutils import Euler, Matrix, Vector

HERE = os.path.dirname(os.path.abspath(__file__)) if "__file__" in globals() else os.getcwd()


def args():
    a = sys.argv[sys.argv.index("--") + 1:] if "--" in sys.argv else []
    opt = {"out": os.path.join(HERE, "blender_glb"), "renders": None, "only": None, "keep": False}
    i = 0
    while i < len(a):
        if a[i] == "--out": opt["out"] = a[i + 1]; i += 2
        elif a[i] == "--renders": opt["renders"] = a[i + 1]; i += 2
        elif a[i] == "--only": opt["only"] = a[i + 1].split(","); i += 2
        elif a[i] == "--keep": opt["keep"] = True; i += 1
        else: i += 1
    return opt


def srgb_to_linear(c):
    c /= 255.0
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


MATS = {}


def material(hexc, finish):
    key = (hexc.upper(), finish)
    if key in MATS: return MATS[key]
    m = bpy.data.materials.new("ab_%s_%s" % (hexc.strip("#"), finish))
    m.use_nodes = True
    bsdf = m.node_tree.nodes.get("Principled BSDF")
    rgb = [srgb_to_linear(int(hexc[i:i + 2], 16)) for i in (1, 3, 5)]
    bsdf.inputs["Base Color"].default_value = rgb + [1.0]
    bsdf.inputs["Roughness"].default_value = {"gloss": 0.32, "matte": 0.78, "glass": 0.05}.get(finish, 0.4)
    if finish == "glass":
        for name in ("Transmission Weight", "Transmission"):   # Blender 4.x / 3.x
            if name in bsdf.inputs: bsdf.inputs[name].default_value = 0.9
        bsdf.inputs["Alpha"].default_value = 0.55
        if hasattr(m, "blend_method"): m.blend_method = "BLEND"
    MATS[key] = m
    return m


def new_object(name, mesh, coll):
    ob = bpy.data.objects.new(name, mesh)
    coll.objects.link(ob)
    return ob


def bm_to_object(bm, name, coll):
    me = bpy.data.meshes.new(name)
    bm.to_mesh(me); bm.free()
    return new_object(name, me, coll)


def lathe_bm(prof, seg):
    """Profile [(radius, y)] spun around Y (Blender's Z is up, so we build in X/Z and rotate the result)."""
    bm = bmesh.new()
    rings = []
    for r, y in prof:
        ring = [bm.verts.new((r * math.cos(2 * math.pi * s / seg), -r * math.sin(2 * math.pi * s / seg), y)) for s in range(seg)]
        rings.append(ring)
    for a, b in zip(rings, rings[1:]):
        for s in range(seg):
            try: bm.faces.new((a[s], a[(s + 1) % seg], b[(s + 1) % seg], b[s]))
            except ValueError: pass
    bmesh.ops.remove_doubles(bm, verts=bm.verts, dist=1e-5)
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    return bm


def extrude_bm(poly, depth):
    bm = bmesh.new()
    bottom = [bm.verts.new((x, y, -depth / 2)) for x, y in poly]
    face = bm.faces.new(bottom)
    ext = bmesh.ops.extrude_face_region(bm, geom=[face])
    top = [v for v in ext["geom"] if isinstance(v, bmesh.types.BMVert)]
    bmesh.ops.translate(bm, verts=top, vec=(0, 0, depth))
    bmesh.ops.recalc_face_normals(bm, faces=bm.faces)
    return bm


# glTF/web convention in models.json: +Y up, +Z towards the viewer. Blender: +Z up, -Y towards the viewer.
Y_UP_TO_Z_UP = Matrix.Rotation(math.radians(90), 4, "X")


def place(ob, p, rot):
    """Applies the part's position and XYZ Euler rotation (degrees, in Y-up space) and converts to Blender's Z-up."""
    m = Matrix.Translation(Vector(p)) @ Euler([math.radians(v) for v in rot], "XYZ").to_matrix().to_4x4()
    ob.matrix_world = Y_UP_TO_Z_UP @ m @ ob.matrix_world


def build_part(pt, i, coll):
    t, name = pt["t"], "%s_%02d" % (pt["t"], i)
    if t == "rbox":
        w, h, d = pt["s"]
        bm = bmesh.new(); bmesh.ops.create_cube(bm, size=1.0)
        bmesh.ops.scale(bm, vec=(w, d, h), verts=bm.verts)          # built Z-up, then turned to Y-up below
        ob = bm_to_object(bm, name, coll)
        ob.matrix_world = Y_UP_TO_Z_UP.inverted()
        r = min(pt.get("r", 0.08), w / 2, h / 2, d / 2) * 0.98
        if r > 0.004:
            mod = ob.modifiers.new("bevel", "BEVEL"); mod.width = r; mod.segments = 5 if r > 0.05 else 2; mod.limit_method = "NONE"
    elif t in ("cyl", "lathe"):
        prof = pt["prof"] if t == "lathe" else [[0, -pt["h"] / 2], [pt["r"], -pt["h"] / 2], [pt["r"], pt["h"] / 2], [0, pt["h"] / 2]]
        ob = bm_to_object(lathe_bm(prof, pt.get("seg", 48 if t == "cyl" else 64)), name, coll)
        ob.matrix_world = Y_UP_TO_Z_UP.inverted()
        if t == "cyl":
            mod = ob.modifiers.new("bevel", "BEVEL"); mod.width = min(0.03, pt["r"] / 4); mod.segments = 3; mod.limit_method = "ANGLE"
    elif t == "sphere":
        bm = bmesh.new(); bmesh.ops.create_uvsphere(bm, u_segments=48, v_segments=24, radius=pt["r"])
        ob = bm_to_object(bm, name, coll)
    elif t == "torus":
        bm = bmesh.new()
        R, r, seg, sides = pt["R"], pt["r"], 64, 24
        rings = [[bm.verts.new(((R + r * math.cos(2 * math.pi * j / sides)) * math.cos(2 * math.pi * i / seg),
                                (R + r * math.cos(2 * math.pi * j / sides)) * math.sin(2 * math.pi * i / seg),
                                r * math.sin(2 * math.pi * j / sides))) for j in range(sides)] for i in range(seg)]
        for i in range(seg):
            for j in range(sides):
                a, b = rings[i], rings[(i + 1) % seg]
                bm.faces.new((a[j], b[j], b[(j + 1) % sides], a[(j + 1) % sides]))
        ob = bm_to_object(bm, name, coll)
        ob.matrix_world = Y_UP_TO_Z_UP.inverted()
    elif t == "extrude":
        ob = bm_to_object(extrude_bm(pt["poly"], pt["depth"]), name, coll)
        mod = ob.modifiers.new("bevel", "BEVEL"); mod.width = min(0.03, pt["depth"] / 4); mod.segments = 3; mod.limit_method = "ANGLE"
        # authored directly in the Y-up frame (XY outline, depth along Z), so no pre-turn: place() converts it
    elif t == "link":
        a, b, r = Vector(pt["a"]), Vector(pt["b"]), pt["r"]
        bm = bmesh.new()
        bmesh.ops.create_cone(bm, cap_ends=True, segments=24, radius1=r, radius2=r, depth=(b - a).length)
        for end in (-(b - a).length / 2, (b - a).length / 2):
            s = bmesh.ops.create_uvsphere(bm, u_segments=24, v_segments=12, radius=r)
            bmesh.ops.translate(bm, verts=s["verts"], vec=(0, 0, end))
        ob = bm_to_object(bm, name, coll)
        d = (b - a).normalized()
        q = Vector((0, 0, 1)).rotation_difference(d)
        ob.matrix_world = Y_UP_TO_Z_UP @ Matrix.Translation((a + b) / 2) @ q.to_matrix().to_4x4()
        ob.data.materials.append(material(pt["c"], pt.get("m", "gloss")))
        smooth(ob)
        return ob
    else:
        raise ValueError("unknown part " + t)
    place(ob, pt.get("p", [0, 0, 0]), pt.get("rot", [0, 0, 0]))
    ob.data.materials.append(material(pt["c"], pt.get("m", "gloss")))
    smooth(ob)
    return ob


def smooth(ob):
    for f in ob.data.polygons: f.use_smooth = True
    if hasattr(ob.data, "use_auto_smooth"):          # Blender < 4.1
        ob.data.use_auto_smooth = True; ob.data.auto_smooth_angle = math.radians(40)
    else:                                            # Blender 4.1+: smooth by angle
        try: ob.modifiers.new("smooth", "NODES").node_group = bpy.data.node_groups.get("Smooth by Angle")
        except Exception: pass


def export(coll, path):
    bpy.ops.object.select_all(action="DESELECT")
    for ob in coll.objects: ob.select_set(True)
    bpy.ops.export_scene.gltf(filepath=path, export_format="GLB", use_selection=True, export_apply=True, export_yup=True)


def render(coll, path):
    scene = bpy.context.scene
    for c in bpy.data.collections: c.hide_render = c is not coll and c.name.startswith("ab_")
    pts = [ob.matrix_world @ Vector(c) for ob in coll.objects for c in ob.bound_box]
    lo = Vector([min(p[k] for p in pts) for k in range(3)]); hi = Vector([max(p[k] for p in pts) for k in range(3)])
    centre, size = (lo + hi) / 2, max(hi - lo)
    cam = bpy.data.objects.get("ab_cam") or bpy.data.objects.new("ab_cam", bpy.data.cameras.new("ab_cam"))
    if cam.name not in scene.collection.objects: scene.collection.objects.link(cam)
    cam.location = centre + Vector((size * 1.2, -size * 1.9, size * 0.9))
    cam.rotation_euler = (centre - cam.location).to_track_quat("-Z", "Y").to_euler()
    scene.camera = cam
    if not bpy.data.objects.get("ab_sun"):
        sun = bpy.data.objects.new("ab_sun", bpy.data.lights.new("ab_sun", "SUN")); sun.data.energy = 3.5
        sun.rotation_euler = (math.radians(50), 0, math.radians(35)); scene.collection.objects.link(sun)
    scene.render.engine = "BLENDER_EEVEE_NEXT" if "BLENDER_EEVEE_NEXT" in bpy.types.RenderSettings.bl_rna.properties["engine"].enum_items.keys() else "BLENDER_EEVEE"
    scene.render.resolution_x = scene.render.resolution_y = 800
    scene.render.film_transparent = True
    scene.render.filepath = path
    bpy.ops.render.render(write_still=True)


def main():
    opt = args()
    with open(os.path.join(HERE, "models.json")) as f:
        models = json.load(f)
    if not opt["keep"]:
        bpy.ops.wm.read_factory_settings(use_empty=True)
    os.makedirs(opt["out"], exist_ok=True)
    if opt["renders"]: os.makedirs(opt["renders"], exist_ok=True)
    x = 0.0
    for name, parts in models.items():
        if opt["only"] and name not in opt["only"]: continue
        coll = bpy.data.collections.new("ab_" + name)
        bpy.context.scene.collection.children.link(coll)
        for i, pt in enumerate(parts): build_part(pt, i, coll)
        export(coll, os.path.join(opt["out"], name + ".glb"))
        if opt["renders"]: render(coll, os.path.join(opt["renders"], name + ".png"))
        for ob in coll.objects: ob.location.x += x        # lay them out side by side for editing
        x += 6.0
        print("built", name, len(parts), "parts")


if __name__ == "__main__":
    main()
