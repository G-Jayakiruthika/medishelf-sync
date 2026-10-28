"""Render the two explainer videos from scratch (Pillow frames -> ffmpeg H.264),
then mix the English narration and the original music bed underneath.
Usage: python3 video_gen.py <projects_root> [waterguard|medishelf|both]
"""
import math, os, subprocess, sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = sys.argv[1] if len(sys.argv) > 1 else "projects"
WHICH = sys.argv[2] if len(sys.argv) > 2 else "both"
W, H, FPS = 1280, 720, 30
INTER = "/usr/share/fonts/opentype/inter/Inter-%s.otf"
NOTO = "/tmp/nf/fonts/NotoSans%s/hinted/ttf/NotoSans%s-Bold.ttf"


def F(size, weight="Bold"):
    return ImageFont.truetype(INTER % weight, size)


def FI(script, size):
    return ImageFont.truetype(NOTO % (script, script), size)


def ease(x):
    x = max(0.0, min(1.0, x))
    return 1 - (1 - x) ** 3


def prog(t, start, dur):
    return ease((t - start) / dur)


def hexrgb(h, a=255):
    h = h.lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4)) + (a,)


def mix(c1, c2, k):
    return tuple(int(a + (b - a) * k) for a, b in zip(c1, c2))


def text_c(d, xy, s, font, fill, anchor="mm"):
    d.text(xy, s, font=font, fill=fill, anchor=anchor)


def rr(d, box, r, fill=None, outline=None, width=1):
    d.rounded_rectangle(box, r, fill=fill, outline=outline, width=width)


def fade_alpha(t, start, end, f=0.4):
    if t < start or t > end:
        return 0
    return min(1, (t - start) / f, (end - t) / f)


def layer(base, draw_fn, alpha):
    """Draw on a transparent layer and composite with the given opacity."""
    if alpha <= 0:
        return
    lay = Image.new("RGBA", base.size, (0, 0, 0, 0))
    draw_fn(ImageDraw.Draw(lay), lay)
    if alpha < 1:
        a = lay.getchannel("A").point(lambda v: int(v * alpha))
        lay.putalpha(a)
    base.alpha_composite(lay)


def encode(name, render, dur, narration, music, out_mp4, poster_t, poster_path):
    tmp = f"/tmp/{name}_silent.mp4"
    p = subprocess.Popen(["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24",
                          "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-", "-c:v", "libx264", "-preset", "veryfast",
                          "-crf", "23", "-pix_fmt", "yuv420p", tmp], stdin=subprocess.PIPE)
    n = int(dur * FPS)
    for i in range(n):
        t = i / FPS
        img = render(t)
        if abs(t - poster_t) < 0.5 / FPS:
            img.convert("RGB").save(poster_path, quality=86)
        p.stdin.write(img.convert("RGB").tobytes())
    p.stdin.close(); p.wait()
    # narration starts at 1.2 s; music ducked underneath
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", tmp, "-i", narration, "-i", music,
                    "-filter_complex",
                    "[1:a]adelay=1200|1200,volume=1.0[v];[2:a]volume=0.22,afade=t=out:st=%f:d=2[m];[v][m]amix=inputs=2:duration=longest:normalize=0,alimiter=limit=0.95[a]" % (dur - 2.5),
                    "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "aac", "-b:a", "128k", "-t", str(dur), "-movflags", "+faststart", out_mp4], check=True)
    os.remove(tmp)
    print("wrote", out_mp4, "and", poster_path)


# ======================= WATERGUARD =======================
WG = dict(deep=hexrgb("#0B3D52"), teal=hexrgb("#0E7490"), aqua=hexrgb("#22B3D6"), sky=hexrgb("#E8F6FA"),
          white=hexrgb("#FFFFFF"), safe=hexrgb("#1F9D68"), warn=hexrgb("#E8A317"), bad=hexrgb("#D84A3A"), ink=hexrgb("#0F2A36"))


def wave_poly(t, base_y, amp, k, speed, phase=0):
    pts = [(0, H)]
    for x in range(0, W + 20, 20):
        y = base_y + amp * math.sin(x * k + t * speed + phase) + amp * 0.4 * math.sin(x * k * 2.3 - t * speed * 1.3)
        pts.append((x, y))
    pts.append((W, H))
    return pts


def wg_render(t):
    c = WG
    img = Image.new("RGBA", (W, H), c["sky"])
    d = ImageDraw.Draw(img)
    # Ambient water at bottom all the time
    level = 560 - 40 * prog(t, 0, 2)
    d.polygon(wave_poly(t, level, 14, 0.008, 1.4), fill=mix(c["aqua"], c["sky"], 0.45))
    d.polygon(wave_poly(t, level + 30, 12, 0.01, -1.1, 2), fill=mix(c["teal"], c["sky"], 0.25))

    # Scene 1: title 0-5.5
    def s1(dd, lay):
        k = prog(t, 0.2, 1.0)
        # droplet logo
        cx, cy = 640, 230 - 20 * (1 - k)
        dd.ellipse((cx - 56, cy - 30, cx + 56, cy + 82), fill=c["teal"])
        dd.polygon([(cx - 50, cy + 10), (cx, cy - 90), (cx + 50, cy + 10)], fill=c["teal"])
        dd.ellipse((cx - 22, cy + 8, cx + 4, cy + 34), fill=mix(c["aqua"], c["white"], 0.6))
        text_c(dd, (640, 400), "WaterGuard", F(92, "ExtraBold"), c["deep"])
        text_c(dd, (640, 470), "Is my tank water safe today?", F(36, "Medium"), c["teal"])
    layer(img, s1, fade_alpha(t, 0.0, 5.5))

    # Scene 2: big status ring 5.5-12.5
    def s2(dd, lay):
        k = prog(t, 6.0, 2.2)
        cx, cy, r = 640, 300, 170
        dd.ellipse((cx - r, cy - r, cx + r, cy + r), outline=mix(c["safe"], c["sky"], 0.8), width=26)
        dd.arc((cx - r, cy - r, cx + r, cy + r), -90, -90 + 360 * 0.92 * k, fill=c["safe"], width=26)
        text_c(dd, (cx, cy - 18), f"{int(92 * k)}", F(110, "ExtraBold"), c["deep"])
        text_c(dd, (cx, cy + 62), "WATER SCORE", F(22, "SemiBold"), c["teal"])
        rr(dd, (470, 505, 810, 565), 30, fill=c["safe"])
        text_c(dd, (640, 535), "Safe to drink", F(30, "Bold"), c["white"])
    layer(img, s2, fade_alpha(t, 5.5, 12.5))

    # Scene 3: traffic-light legend 12.5-19
    def s3(dd, lay):
        items = [("Green", "Safe to drink", c["safe"]), ("Yellow", "Boil before drinking", c["warn"]), ("Red", "Do not drink. Clean tank", c["bad"])]
        text_c(dd, (640, 120), "Three colours. One simple answer.", F(44, "ExtraBold"), c["deep"])
        for i, (n, s, col) in enumerate(items):
            k = prog(t, 13.0 + i * 0.6, 0.8)
            x = 130 + i * 350
            y = 220 + 50 * (1 - k)
            rr(dd, (x, y, x + 320, y + 260), 28, fill=c["white"])
            dd.ellipse((x + 110, y + 34, x + 210, y + 134), fill=col)
            text_c(dd, (x + 160, y + 170), n, F(34, "ExtraBold"), c["ink"])
            text_c(dd, (x + 160, y + 214), s, F(22, "Medium"), c["teal"])
    layer(img, s3, fade_alpha(t, 12.5, 19.2))

    # Scene 4: tank + calendar 19-25.5
    def s4(dd, lay):
        k = prog(t, 19.6, 1.2)
        # overhead tank
        tx, ty = 250, 150
        rr(dd, (tx, ty, tx + 300, ty + 330), 40, fill=c["white"], outline=c["deep"], width=8)
        fill_h = 300 * (0.25 + 0.55 * k)
        rr(dd, (tx + 14, ty + 316 - fill_h, tx + 286, ty + 316), 28, fill=c["aqua"])
        dd.rectangle((tx + 120, ty - 30, tx + 180, ty), fill=c["deep"])
        text_c(dd, (tx + 150, ty + 380), "Overhead tank", F(26, "SemiBold"), c["deep"])
        # calendar card
        cx = 690
        rr(dd, (cx, 150, cx + 380, 480), 28, fill=c["white"])
        rr(dd, (cx, 150, cx + 380, 220), 28, fill=c["teal"])
        dd.rectangle((cx, 190, cx + 380, 220), fill=c["teal"])
        text_c(dd, (cx + 190, 186), "Tank wash", F(30, "Bold"), c["white"])
        text_c(dd, (cx + 190, 300), "Every", F(30, "Medium"), c["teal"])
        text_c(dd, (cx + 190, 365), "3–6 months", F(54, "ExtraBold"), c["deep"])
        text_c(dd, (cx + 190, 435), "as advised for Indian homes", F(20, "Medium"), c["teal"])
    layer(img, s4, fade_alpha(t, 19.0, 25.6))

    # Scene 5: CTA + languages 25.5-end
    def s5(dd, lay):
        pulse = 1 + 0.04 * math.sin(t * 6)
        bw, bh = 380 * pulse, 92 * pulse
        rr(dd, (640 - bw / 2, 230 - bh / 2, 640 + bw / 2, 230 + bh / 2), 46, fill=c["teal"])
        text_c(dd, (640, 230), "Check water", F(40, "Bold"), c["white"])
        text_c(dd, (640, 340), "Speaks your language", F(34, "ExtraBold"), c["deep"])
        langs = [("Tamil", "தமிழ்"), ("Devanagari", "हिन्दी"), ("Telugu", "తెలుగు"), ("Kannada", "ಕನ್ನಡ"), ("Malayalam", "മലയാളം"), ("Bengali", "বাংলা")]
        for i, (sc, word) in enumerate(langs):
            k = prog(t, 26.2 + i * 0.18, 0.5)
            x = 175 + i * 186
            rr(dd, (x - 80, 395, x + 80, 455), 30, fill=mix(c["white"], c["sky"], 0.0) if k > 0 else None)
            dd.text((x, 425 + 10 * (1 - k)), word, font=FI(sc, 28), fill=c["teal"][:3] + (int(255 * k),), anchor="mm")
    layer(img, s5, fade_alpha(t, 25.5, 40, 0.5))
    return img


# ======================= MEDISHELF =======================
MS = dict(paper=hexrgb("#EEF2EF"), ai=hexrgb("#1F3358"), ai2=hexrgb("#3A5487"), matcha=hexrgb("#4F8A57"), amber=hexrgb("#D79A1E"),
          shu=hexrgb("#C2412F"), white=hexrgb("#FFFFFF"), ink=hexrgb("#16202E"), line=hexrgb("#C9D3CD"), foil=hexrgb("#D6DBE0"))


def ms_bg(d, t):
    # subtle seigaiha-like dots grid
    for y in range(30, H, 48):
        for x in range(30 + (24 if (y // 48) % 2 else 0), W, 48):
            d.ellipse((x - 2, y - 2, x + 2, y + 2), fill=MS["line"])


def strip(dd, x, y, w, h, scan=None):
    c = MS
    rr(dd, (x, y, x + w, y + h), 18, fill=c["foil"], outline=c["line"], width=2)
    for i in range(5):
        for j in range(2):
            cx = x + 60 + i * (w - 120) / 4
            cy = y + 70 + j * (h - 140)
            dd.ellipse((cx - 30, cy - 30, cx + 30, cy + 30), fill=c["white"], outline=c["line"], width=3)
    # red line strip (Rx antibiotics marker used in India)
    dd.rectangle((x + 10, y + h / 2 - 5, x + w - 10, y + h / 2 + 5), fill=c["shu"])
    dd.text((x + 24, y + h + 16), "B.No. AMX24117   MFG 04/2025   EXP 03/2027   MRP ₹68.40", font=F(22, "SemiBold"), fill=c["ink"])
    if scan is not None:
        sy = y - 10 + (h + 20) * scan
        dd.rectangle((x - 20, sy - 2, x + w + 20, sy + 2), fill=c["matcha"])


def ms_render(t):
    c = MS
    img = Image.new("RGBA", (W, H), c["paper"])
    d = ImageDraw.Draw(img)
    ms_bg(d, t)

    # Scene 1: cabinet drawers 0-6
    def s1(dd, lay):
        cols, rows = 4, 3
        bx, by, cw, ch = 340, 110, 150, 96
        for r in range(rows):
            for q in range(cols):
                k = prog(t, 0.2 + (r * cols + q) * 0.06, 0.6)
                x, y = bx + q * (cw + 12), by + r * (ch + 12) + 20 * (1 - k)
                col = [c["matcha"], c["amber"], c["white"], c["white"], c["white"], c["matcha"], c["white"], c["shu"], c["white"], c["white"], c["matcha"], c["white"]][r * cols + q]
                rr(dd, (x, y, x + cw, y + ch), 14, fill=c["white"], outline=c["line"], width=2)
                rr(dd, (x + cw / 2 - 26, y + ch - 26, x + cw / 2 + 26, y + ch - 16), 5, fill=col if col != c["white"] else c["line"])
        text_c(dd, (640, 500), "MediShelf-Sync", F(80, "ExtraBold"), c["ai"])
        text_c(dd, (640, 568), "Your family medicine cabinet", F(32, "Medium"), c["ai2"])
    layer(img, s1, fade_alpha(t, 0, 6.2))

    # Scene 2: scan strip 6-14
    def s2(dd, lay):
        text_c(dd, (640, 90), "Snap the strip, bottle or QR code", F(42, "ExtraBold"), c["ai"])
        scan = ((t - 6.6) % 2.2) / 2.2
        strip(dd, 300, 170, 680, 260, scan if t < 11 else None)
        k = prog(t, 11.0, 0.7)
        if k > 0:
            for i, (lab, val) in enumerate([("Batch", "AMX24117"), ("Expiry", "Mar 2027"), ("Status", "Safe · 17 months left")]):
                x = 160 + i * 330
                rr(dd, (x, 500 + 30 * (1 - k), x + 300, 600 + 30 * (1 - k)), 20, fill=c["white"], outline=c["line"], width=2)
                dd.text((x + 22, 518 + 30 * (1 - k)), lab.upper(), font=F(18, "SemiBold"), fill=c["ai2"])
                dd.text((x + 22, 546 + 30 * (1 - k)), val, font=F(28 if i < 2 else 22, "Bold"), fill=c["matcha"] if i == 2 else c["ink"])
    layer(img, s2, fade_alpha(t, 6.0, 14.2))

    # Scene 3: status chips 14-21
    def s3(dd, lay):
        text_c(dd, (640, 110), "Colour tells you at a glance", F(44, "ExtraBold"), c["ai"])
        rows = [("Paracetamol 650", "Safe", "Exp Aug 2027", c["matcha"]), ("Cetirizine 10", "Use soon", "Exp in 54 days", c["amber"]), ("Cough syrup", "Expired", "Return to chemist", c["shu"])]
        for i, (n, s, e, col) in enumerate(rows):
            k = prog(t, 14.6 + i * 0.7, 0.7)
            y = 190 + i * 140
            x = 230 - 60 * (1 - k)
            rr(dd, (x, y, x + 820, y + 116), 22, fill=c["white"], outline=c["line"], width=2)
            dd.rectangle((x, y + 16, x + 8, y + 100), fill=col)
            dd.text((x + 40, y + 26), n, font=F(32, "Bold"), fill=c["ink"])
            dd.text((x + 40, y + 70), e, font=F(22, "Medium"), fill=c["ai2"])
            rr(dd, (x + 600, y + 34, x + 790, y + 82), 24, fill=col)
            text_c(dd, (x + 695, y + 58), s, F(24, "Bold"), c["white"])
    layer(img, s3, fade_alpha(t, 14.0, 21.4))

    # Scene 4: dose calendar (Japanese one-dose packets: morning/noon/evening/bed) 21-28
    def s4(dd, lay):
        text_c(dd, (640, 100), "Doses sorted by time of day", F(44, "ExtraBold"), c["ai"])
        slots = ["Morning", "Afternoon", "Evening", "Bedtime"]
        cols = [c["amber"], c["matcha"], c["ai2"], c["ai"]]
        for i, s in enumerate(slots):
            k = prog(t, 21.6 + i * 0.35, 0.6)
            x = 150 + i * 255
            y = 180 + 40 * (1 - k)
            rr(dd, (x, y, x + 225, y + 330), 22, fill=c["white"], outline=c["line"], width=2)
            rr(dd, (x, y, x + 225, y + 60), 22, fill=cols[i])
            dd.rectangle((x, y + 36, x + 225, y + 60), fill=cols[i])
            text_c(dd, (x + 112, y + 32), s, F(24, "Bold"), c["white"])
            for j in range(1 + (i % 2) + (1 if i == 0 else 0)):
                py = y + 100 + j * 70
                rr(dd, (x + 28, py, x + 197, py + 52), 26, fill=c["paper"])
                dd.ellipse((x + 40, py + 10, x + 72, py + 42), fill=cols[i])
                dd.text((x + 86, py + 14), ["Metformin", "Vitamin D", "Telmisartan"][j], font=F(18, "SemiBold"), fill=c["ink"])
        text_c(dd, (640, 575), "Gentle chime reminds you at each time", F(26, "Medium"), c["ai2"])
    layer(img, s4, fade_alpha(t, 21.0, 28.4))

    # Scene 5: safety rules 28-35
    def s5(dd, lay):
        text_c(dd, (640, 100), "Keep every family member safe", F(44, "ExtraBold"), c["ai"])
        rules = [("Away from children", c["ai"]), ("Below 30 °C", c["amber"]), ("Out of sunlight", c["matcha"]), ("Return expired to chemist", c["shu"])]
        for i, (r, col) in enumerate(rules):
            k = prog(t, 28.6 + i * 0.4, 0.6)
            x = 200 + (i % 2) * 460
            y = 190 + (i // 2) * 170 + 30 * (1 - k)
            rr(dd, (x, y, x + 420, y + 140), 24, fill=c["white"], outline=c["line"], width=2)
            dd.ellipse((x + 28, y + 40, x + 88, y + 100), fill=col)
            dd.text((x + 110, y + 52), r, font=F(26, "Bold"), fill=c["ink"])
        text_c(dd, (640, 580), "Side effect? PvPI helpline 1800-180-3024", F(26, "SemiBold"), c["ai2"])
    layer(img, s5, fade_alpha(t, 28.0, 35.4))

    # Scene 6: languages end card 35-end
    def s6(dd, lay):
        text_c(dd, (640, 220), "MediShelf-Sync", F(76, "ExtraBold"), c["ai"])
        text_c(dd, (640, 290), "Scan · Track · Stay safe", F(32, "Medium"), c["ai2"])
        langs = [("Tamil", "தமிழ்"), ("Devanagari", "हिन्दी"), ("Telugu", "తెలుగు"), ("Kannada", "ಕನ್ನಡ"), ("Malayalam", "മലയാളം"), ("Bengali", "বাংলা")]
        for i, (sc, word) in enumerate(langs):
            k = prog(t, 35.6 + i * 0.18, 0.5)
            x = 175 + i * 186
            rr(dd, (x - 80, 380, x + 80, 440), 30, fill=c["white"], outline=c["line"], width=2)
            dd.text((x, 410 + 8 * (1 - k)), word, font=FI(sc, 28), fill=c["ai"][:3] + (int(255 * k),), anchor="mm")
    layer(img, s6, fade_alpha(t, 35.0, 60, 0.5))
    return img


if WHICH in ("waterguard", "both"):
    out = os.path.join(ROOT, "waterguard", "assets", "video")
    os.makedirs(out, exist_ok=True)
    encode("wg", wg_render, 31.0, "/tmp/waterguard_narration.mp3", "/tmp/wg_music.wav",
           os.path.join(out, "waterguard-guide.mp4"), 8.5, os.path.join(out, "waterguard-poster.jpg"))
if WHICH in ("medishelf", "both"):
    out = os.path.join(ROOT, "medishelf-sync", "assets", "video")
    os.makedirs(out, exist_ok=True)
    encode("ms", ms_render, 40.0, "/tmp/medishelf-sync_narration.mp3", "/tmp/ms_music.wav",
           os.path.join(out, "medishelf-guide.mp4"), 12.0, os.path.join(out, "medishelf-poster.jpg"))
