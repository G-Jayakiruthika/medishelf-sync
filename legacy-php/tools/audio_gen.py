"""Generate all original sound files for WaterGuard and AushadhBox.
Everything is synthesised from scratch with numpy (no samples, no downloads).
Usage: python3 audio_gen.py <projects_root>
"""
import sys, os, subprocess, wave
import numpy as np

SR = 44100
ROOT = sys.argv[1] if len(sys.argv) > 1 else "projects"


def env_adsr(n, a=0.01, d=0.1, s=0.6, r=0.2):
    a_n, d_n, r_n = int(a * SR), int(d * SR), int(r * SR)
    s_n = max(n - a_n - d_n - r_n, 0)
    e = np.concatenate([
        np.linspace(0, 1, a_n, endpoint=False),
        np.linspace(1, s, d_n, endpoint=False),
        np.full(s_n, s),
        np.linspace(s, 0, r_n),
    ])
    return np.pad(e, (0, max(0, n - len(e))))[:n]


def tone(freq, dur, kind="sine", harmonics=(1.0,), **adsr):
    n = int(dur * SR)
    t = np.arange(n) / SR
    out = np.zeros(n)
    for i, amp in enumerate(harmonics, start=1):
        if kind == "sine":
            out += amp * np.sin(2 * np.pi * freq * i * t)
    return out * env_adsr(n, **adsr)


def bell(freq, dur=2.0):
    """Soft glassy bell: inharmonic partials with exponential decay."""
    n = int(dur * SR)
    t = np.arange(n) / SR
    partials = [(1.0, 1.0, 1.6), (2.0, 0.45, 2.4), (2.76, 0.25, 3.4), (5.4, 0.08, 5.0)]
    out = sum(a * np.sin(2 * np.pi * freq * r * t) * np.exp(-k * t) for r, a, k in partials)
    attack = np.minimum(t / 0.004, 1)
    return out * attack


def pluck(freq, dur=2.5, brightness=0.996):
    """Karplus-Strong plucked string (koto-like)."""
    n = int(dur * SR)
    period = int(SR / freq)
    rng = np.random.default_rng(int(freq * 10))
    buf = rng.uniform(-1, 1, period)
    out = np.zeros(n)
    for i in range(n):
        out[i] = buf[i % period]
        buf[i % period] = brightness * 0.5 * (buf[i % period] + buf[(i + 1) % period])
    # gentle low-pass for warmth
    k = np.ones(4) / 4
    return np.convolve(out, k, mode="same")


def place(track, sig, at):
    s = int(at * SR)
    e = min(len(track), s + len(sig))
    track[s:e] += sig[: e - s]


def reverb(x, decay=0.35, delays=(0.029, 0.037, 0.043, 0.051)):
    out = x.copy()
    for d in delays:
        ds = int(d * SR)
        y = np.zeros_like(x)
        for k in range(1, 6):
            if ds * k < len(x):
                y[ds * k:] += x[: len(x) - ds * k] * (decay ** k)
        out += y * 0.5
    return out


def save(sig, path, fmt="mp3"):
    sig = sig / (np.max(np.abs(sig)) + 1e-9) * 0.85
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".wav"
    with wave.open(tmp, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((sig * 32767).astype(np.int16).tobytes())
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", tmp, "-b:a", "128k", path], check=True)
    os.remove(tmp)
    print("wrote", path)


def save_wav(sig, path):
    sig = sig / (np.max(np.abs(sig)) + 1e-9) * 0.8
    with wave.open(path, "w") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((sig * 32767).astype(np.int16).tobytes())


# ---------------- WaterGuard ----------------
wg = os.path.join(ROOT, "waterguard", "assets", "audio")

# 1. Tank-full alarm: friendly two-tone buzzer pattern, 3 cycles
track = np.zeros(int(6.2 * SR))
for c in range(3):
    base = c * 2.0
    for j, f in enumerate([1046.5, 1318.5, 1046.5, 1318.5]):
        place(track, tone(f, 0.18, harmonics=(1, 0.3, 0.12), a=0.005, d=0.03, s=0.8, r=0.05), base + j * 0.22)
save(track, os.path.join(wg, "tank-full-alarm.mp3"))

# 2. Test passed: rising sparkle chime (C major arpeggio of bells)
track = np.zeros(int(3.0 * SR))
for i, f in enumerate([523.25, 659.25, 783.99, 1046.5]):
    place(track, bell(f, 2.2) * (0.8 + i * 0.07), i * 0.11)
save(reverb(track), os.path.join(wg, "test-pass.mp3"))

# 3. Attention tone: soft descending two notes, repeated twice
track = np.zeros(int(3.2 * SR))
for c in range(2):
    place(track, bell(659.25, 1.2), c * 1.3)
    place(track, bell(493.88, 1.4), c * 1.3 + 0.35)
save(reverb(track), os.path.join(wg, "attention.mp3"))

# 4. Pump running loop: low motor hum + water trickle noise (3 s loop)
n = int(3.0 * SR); t = np.arange(n) / SR
hum = 0.5 * np.sin(2 * np.pi * 100 * t) + 0.25 * np.sin(2 * np.pi * 200 * t) + 0.1 * np.sin(2 * np.pi * 300 * t)
rng = np.random.default_rng(3)
noise = rng.normal(0, 1, n)
noise = np.convolve(noise, np.ones(30) / 30, mode="same")  # rumbly water
bubbles = np.zeros(n)
for _ in range(60):
    at = rng.uniform(0, 2.9); f = rng.uniform(600, 1400)
    m = int(0.05 * SR); tt = np.arange(m) / SR
    place(bubbles, np.sin(2 * np.pi * (f + 3000 * tt) * tt) * np.exp(-tt * 70), at)
pump = hum * 0.35 + noise * 1.2 + bubbles * 0.25
fade = np.minimum(1, np.minimum(t / 0.05, (3.0 - t) / 0.05))
save(pump * fade, os.path.join(wg, "pump-running.mp3"))

# Background music for the WaterGuard video (bright, flowing, 36 s)
def music_bed(dur, chords, bpm, voice="bell", seed=1):
    n = int(dur * SR)
    track = np.zeros(n)
    beat = 60 / bpm
    rng = np.random.default_rng(seed)
    step = 0
    tpos = 0.0
    while tpos < dur - 1:
        chord = chords[(step // 8) % len(chords)]
        note = chord[[0, 1, 2, 1, 2, 3 % len(chord), 2, 1][step % 8]]
        if voice == "bell":
            place(track, bell(note, 1.6) * 0.5, tpos)
        else:
            place(track, pluck(note, 1.8) * 0.6, tpos)
        if step % 8 == 0:  # pad on chord change
            pad_d = beat * 8
            for f in chord[:3]:
                place(track, tone(f / 2, pad_d, harmonics=(1, 0.2), a=0.6, d=0.5, s=0.7, r=0.8) * 0.12, tpos)
        step += 1
        tpos += beat / 2
    t = np.arange(n) / SR
    fade = np.minimum(1, np.minimum(t / 1.5, (dur - t) / 2.5))
    return reverb(track) * np.clip(fade, 0, 1)

C, Am, F, G = [261.63, 329.63, 392.0, 523.25], [220.0, 261.63, 329.63, 440.0], [174.61, 220.0, 261.63, 349.23], [196.0, 246.94, 293.66, 392.0]
save_wav(music_bed(36, [C, Am, F, G], 96, "bell"), "/tmp/wg_music.wav")
print("wrote /tmp/wg_music.wav")

# ---------------- AushadhBox ----------------
ms = os.path.join(ROOT, "aushadhbox", "assets", "audio")
# Japanese-feel pentatonic (in-sen-ish / yo scale on D)
D4, E4, G4, A4, B4, D5, E5 = 293.66, 329.63, 392.0, 440.0, 493.88, 587.33, 659.25

# 1. Dose reminder: koto pluck phrase
track = np.zeros(int(4.0 * SR))
for i, (f, at) in enumerate([(D5, 0), (B4, 0.22), (A4, 0.44), (D5, 0.9), (E5, 1.15)]):
    place(track, pluck(f, 2.6), at)
save(reverb(track, 0.3), os.path.join(ms, "dose-reminder.mp3"))

# 2. Expiry alert: soft "temple bell" double strike, three times
track = np.zeros(int(5.0 * SR))
for c in range(3):
    place(track, bell(440.0, 1.6), c * 1.4)
    place(track, bell(349.23, 1.8) * 0.8, c * 1.4 + 0.3)
save(reverb(track), os.path.join(ms, "expiry-alert.mp3"))

# 3. Saved / success: two quick plucks up
track = np.zeros(int(2.2 * SR))
place(track, pluck(G4 * 2, 1.8), 0)
place(track, pluck(D5 * 2, 1.8), 0.12)
save(reverb(track, 0.3), os.path.join(ms, "saved.mp3"))

# 4. Calm ambience (45 s) to play while organising the cabinet
amb = music_bed(45, [[D4, G4, A4, D5], [E4, A4, B4, E5], [G4 / 1, B4, D5, E5], [A4 / 2 * 2, D5, E5, A4]], 72, "pluck", 7)
save(amb, os.path.join(ms, "calm-cabinet-ambience.mp3"))

save_wav(music_bed(36, [[D4, G4, A4, D5], [E4, A4, B4, E5], [G4, B4, D5, E5], [A4, D5, E5, A4]], 84, "pluck", 9), "/tmp/ms_music.wav")
print("wrote /tmp/ms_music.wav")
