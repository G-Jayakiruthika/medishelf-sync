#!/usr/bin/env python3
"""Build a static preview of AushadhBox (website snapshot + app running on demo-api.js).
Needs the site running locally:  cd public_html && php -S 127.0.0.1:8080 index.php
Usage: python3 tools/preview/build_preview.py OUT_DIR [BASE_URL]
Output: OUT_DIR/site/... (all pages and assets, flat, linked relatively). OUT_DIR/index.html is written separately."""
import json, os, re, shutil, subprocess, sys, urllib.request, urllib.parse

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PUB = os.path.join(ROOT, "public_html")
OUT = os.path.abspath(sys.argv[1])
BASE = (sys.argv[2] if len(sys.argv) > 2 else "http://127.0.0.1:8080").rstrip("/")
SITE = os.path.join(OUT, "site")
LANGS = ["hi", "bn", "mr", "te", "ta", "gu", "kn", "ml", "pa"]
MAX_MEDICINES = 140

def get(path):
    req = urllib.request.Request(BASE + path, headers={"Cookie": "ms_lang=en"})
    with urllib.request.urlopen(req) as r:
        return r.read().decode("utf-8")

def page_file(path):
    """Map a site URL path (no host) to a preview file name, or None if not part of the preview."""
    p = urllib.parse.urlsplit(path)
    route, query, frag = p.path.strip("/"), urllib.parse.parse_qs(p.query), ("#" + p.fragment if p.fragment else "")
    if frag == "#upgrade": frag = "#account"   # 'upgrade' is reserved in the preview frame
    if route == "": return "home.html" + frag
    if route in LANGS: return route + ".html" + frag
    if route in [l + "/pricing" for l in LANGS]: return route.replace("/", "-") + ".html" + frag
    if route in ("pricing", "about", "contact", "help", "privacy", "terms", "refund-policy", "cookie-policy", "guides", "ingredients"): return route + ".html" + frag
    if route == "medicines":
        if "letter" in query: return "medicines-" + ("num" if query["letter"][0] == "#" else query["letter"][0].lower()) + ".html"
        return "medicines.html"
    m = re.match(r"^(medicines|ingredients|guides)/([a-z0-9-]+)$", route)
    if m: return {"medicines": "med-", "ingredients": "ing-", "guides": "guide-"}[m.group(1)] + m.group(2) + ".html"
    if route in ("app", "report"): return "app.html" + (frag if route == "app" else "#account")
    if route == "admin.html": return "admin.html"
    return None

STATIC = re.compile(r"^/(site\.css|site\.js|consent\.js|consent-i18n\.js|styles\.css|app\.js|admin\.js|rx-parse\.js|i18n[\w-]*\.js|manifest\.webmanifest|img/[\w.-]+|icons/[\w.-]+|lib/[\w.-]+|assets/[\w/.-]+)(\?.*)?$")

def rewrite_url(u, have):
    if u.startswith(BASE): u = u[len(BASE):] or "/"
    if not u.startswith("/") or u.startswith("//"): return u
    m = STATIC.match(u)
    if m: return m.group(1)
    f = page_file(u)
    if f is None: return "preview-note.html"
    name = f.split("#")[0]
    if name not in have and not name.startswith(("app.html", "admin.html")): return "preview-note.html"
    return f

def rewrite_html(html, have, add_demo=True, banner=True):
    html = re.sub(r'((?:href|src|action|content|poster)=")([^"]*)(")', lambda m: m.group(1) + rewrite_url(m.group(2), have) + m.group(3) if (m.group(2).startswith("/") or m.group(2).startswith(BASE)) else m.group(0), html)
    html = html.replace('<link rel="manifest" href="manifest.webmanifest">', "")
    if add_demo:
        html = html.replace("<head>", '<head>\n<script src="demo-api.js"></script>', 1)
    if banner:
        html = html.replace("</body>", '<a href="../" class="ab-preview-pill">Preview · back to guide</a>\n<style>.ab-preview-pill{position:fixed;z-index:70;right:max(12px,env(safe-area-inset-right,0px));top:calc(78px + env(safe-area-inset-top,0px));background:#E7B24E;color:#16202E;font:700 12px/1 "Noto Sans",system-ui,sans-serif;padding:8px 12px;border-radius:999px;text-decoration:none;box-shadow:0 6px 18px -6px rgba(0,0,0,.4)}</style>\n</body>', 1)
    return html

def main():
    if os.path.exists(SITE): shutil.rmtree(SITE)
    os.makedirs(SITE)
    # 1. Data for the demo API
    php = r'''require "core/bootstrap.php"; require "core/catalog.php";
      $c = array_map(fn($r) => ["id"=>(int)$r["id"],"n"=>$r["generic_name"],"u"=>$r["unit_size"],"c"=>$r["drug_code"],"i"=>$r["info_id"]?(int)$r["info_id"]:null,"s"=>$r["search_text"]], q("SELECT id, generic_name, unit_size, drug_code, info_id, search_text FROM medicine_catalog ORDER BY id")->fetchAll());
      $i = array_map(fn($r) => ["id"=>(int)$r["id"],"ingredient"=>$r["ingredient"],"keywords"=>$r["keywords"],"use"=>$r["use_text"],"rx"=>(int)$r["rx"],"storage"=>$r["storage"],"note"=>$r["note"],"slug"=>$r["slug"],"ut"=>$r["usual_times"],"cd"=>$r["course_days"]===null?null:(int)$r["course_days"],"fd"=>$r["food"]], q("SELECT * FROM medicine_info ORDER BY id")->fetchAll());
      echo json_encode(["catalog"=>$c,"info"=>$i], JSON_UNESCAPED_UNICODE);'''
    data = subprocess.run(["php", "-r", php], cwd=PUB, capture_output=True, text=True, check=True).stdout
    open(os.path.join(SITE, "demo-data.json"), "w").write(data)
    info = json.loads(data)["info"]

    # 2. Which pages go into the preview
    paths = ["/", "/pricing", "/about", "/contact", "/help", "/privacy", "/terms", "/refund-policy", "/cookie-policy", "/medicines", "/ingredients", "/guides", "/nonexistent-page-404"]
    paths += ["/" + l + "/" for l in LANGS] + ["/" + l + "/pricing" for l in LANGS]
    paths += ["/medicines?letter=" + L for L in "ABCDEFGHIJKLMNOPQRSTUVWXYZ"] + ["/medicines?letter=%23"]
    paths += ["/ingredients/" + i["slug"] for i in info]
    guides = re.findall(r'href="/guides/([a-z0-9-]+)"', get("/guides"))
    paths += ["/guides/" + g for g in dict.fromkeys(guides)]
    # medicine pages: those linked from the home page and the ingredient pages, up to a budget
    meds = []
    for p in ["/"] + ["/ingredients/" + i["slug"] for i in info]:
        for s in re.findall(r'href="/medicines/([a-z0-9-]+)"', get(p))[:(50 if p == "/" else 2)]:
            if s not in meds: meds.append(s)
    paths += ["/medicines/" + s for s in meds[:MAX_MEDICINES]]

    pages = {}
    for p in paths:
        f = "404.html" if p == "/nonexistent-page-404" else page_file(p.replace("%23", "#"))
        try:
            pages[f] = get(p)
        except urllib.error.HTTPError as e:
            pages[f] = e.read().decode("utf-8")
    have = set(pages)
    for f, html in pages.items():
        open(os.path.join(SITE, f), "w").write(rewrite_html(html, have))

    # 3. App and admin (static files) with the demo API in front
    for name in ("app.html", "admin.html"):
        html = open(os.path.join(PUB, name)).read()
        html = rewrite_html(html, have, banner=(name == "admin.html"))
        open(os.path.join(SITE, name), "w").write(html)

    # 4. Assets
    for d in ("img", "icons", "lib", "assets"):
        shutil.copytree(os.path.join(PUB, d), os.path.join(SITE, d), ignore=shutil.ignore_patterns("*.txt"))
    for f in os.listdir(PUB):
        if f.endswith((".css", ".js")) and f != "sw.js":
            s = open(os.path.join(PUB, f)).read()
            s = s.replace('"/api.php', '"api.php').replace("'/api.php", "'api.php").replace('"/assets/', '"assets/').replace('"/app#', '"app.html#').replace('"/report"', '"app.html#account"')
            s = s.replace('href="/', 'href="').replace("url(/", "url(")
            open(os.path.join(SITE, f), "w").write(s)
    for f in ("demo-api.js", "start-app.html"):
        shutil.copy(os.path.join(os.path.dirname(__file__), f), os.path.join(SITE, f))

    # 4b. Pillbox build guide (the app links to it; the GitHub repo may be private)
    import markdown
    hw = os.path.join(os.path.dirname(PUB), "hardware")
    body = markdown.markdown(open(os.path.join(hw, "README.md")).read(), extensions=["tables", "fenced_code"])
    shutil.copy(os.path.join(hw, "wiring.svg"), os.path.join(SITE, "wiring.svg"))
    open(os.path.join(SITE, "hardware.html"), "w").write("""<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart pillbox build guide</title><style>
:root{--ink:#1B2430;--muted:#5A6577;--line:#D8DED9;--bg:#F7F9F7;--ai:#1F3358}
@media (prefers-color-scheme:dark){:root{--ink:#E8ECF2;--muted:#9AA6B8;--line:#2C3644;--bg:#11161D;--ai:#A9BDEA}}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 system-ui,sans-serif}main{max-width:900px;margin:0 auto;padding:24px 16px 60px}
h1,h2{color:var(--ai);line-height:1.25}img{max-width:100%;height:auto;background:#fff;border-radius:12px}table{border-collapse:collapse;width:100%;display:block;overflow-x:auto;font-size:.92rem}
th,td{border:1px solid var(--line);padding:6px 9px;text-align:left;vertical-align:top}pre{background:rgba(127,127,127,.12);padding:12px;border-radius:10px;overflow-x:auto}code{font-size:.88em}
a{color:var(--ai)}</style></head><body><main><p><a href="app.html#account">← Back to the app</a></p>""" + body + "</main></body></html>")
    ap = os.path.join(SITE, "app.html")
    html = open(ap).read().replace("https://github.com/G-Jayakiruthika/medishelf-sync/tree/main/hardware", "hardware.html")
    open(ap, "w").write(html)

    # 5. Note shown for pages that exist on the live site but are not in this preview
    open(os.path.join(SITE, "preview-note.html"), "w").write(rewrite_html(pages["404.html"].replace("This drawer is empty", "In the full site").replace(
        "The page you were looking for has moved or never existed.", "This preview includes a sample of the 2,111 medicine pages. The live site has all of them."), have))
    files = sum(len(fs) for _, _, fs in os.walk(SITE))
    print(f"preview built: {len(pages)} pages, {files} files in {SITE}")

main()
