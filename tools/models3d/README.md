# AushadhBox 3D models

Every screen of the family app, the public website and the pharmacy portal shows a small 3D model
(drag to turn it). The models are described once and built two ways.

| File | What it does |
|---|---|
| `spec.py` | The 21 models as simple parts (rounded boxes, cylinders, spun profiles, extruded outlines). Writes `models.json`. |
| `build_glb.py` | Plain Python, no Blender: turns `models.json` into the `.glb` files the pages use (`public_html/assets/3d`, `pharmacy-platform/public/assets/3d`). |
| `blender_build.py` | Inside Blender 4.x: the same models with bevels, smooth shading and Principled materials (glass uses transmission). Exports `.glb` and optional Eevee renders. |
| `../../public_html/scene3d.js` | The viewer: about 9 KB of WebGL, no libraries (the site's security policy allows only its own scripts). A copy lives in `pharmacy-platform/public/lib/`. |

```sh
python3 tools/models3d/spec.py && python3 tools/models3d/build_glb.py          # web models
blender -b -P tools/models3d/blender_build.py -- --out tools/models3d/blender_glb --renders tools/models3d/renders
```

To use the Blender versions on the site, export into `public_html/assets/3d` instead (`--out public_html/assets/3d`)
and copy them to `pharmacy-platform/public/assets/3d`. CI rebuilds the web models and fails if the committed files
differ from `spec.py`, so change `spec.py` and rebuild, never the `.glb` files by hand (unless you switch to Blender
exports, then remove the CI check).

| Screen | Model |
|---|---|
| Cabinet, Scan, Doses, Notebook, Care+, Card, Pharmacies, Safety, Guide, Account | `cabinet`, `scan`, `doses`, `notebook`, `care`, `card`, `shops`, `safety`, `guide`, `account` |
| Website "How it works" | `hero` (the pillbox and a phone) |
| Pharmacy portal | `ph_home`, `ph_counter`, `ph_orders`, `ph_stock`, `ph_network`, `card` (customers), `ph_payments`, `ph_delivery`, `ph_devices`, `ph_staff`, `ph_settings`, `ph_reports` |

Behaviour: draws only while on screen, holds still for "reduce motion", is skipped in low-data mode and when the
browser has no WebGL. The pages are complete without it. Models are 25 to 190 KB each and are cached for a week.
