/* Reads readings that watches and health apps export, in the browser (nothing is uploaded except the readings):
   - Apple Health: export.zip or export.xml (Apple Watch, iPhone, and meters that write to Apple Health)
   - Samsung Health, Google Fit, Mi Fitness, Noise / boAt apps: CSV files
   - Fitbit: JSON files from the Fitbit data export
   Returns rows { kind: bp|sugar|weight|temp|spo2|pulse, v1, v2, context, at: "YYYY-MM-DD HH:MM:SS" }.
   Pulse is kept at one reading per hour (watches record every few minutes); only the last 365 days are kept. */
(function (root) {
  "use strict";
  const DAYS = 365;

  function pad(n) { return String(n).padStart(2, "0"); }
  function fmt(d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + " " + pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds()); }

  /* Many date styles: 2024-05-01 08:00:00 +0530, 2024-05-01T08:00:00.000Z, 2024-05-01, 05/01/24 08:00:00 (Fitbit, month first), 01-05-2024 */
  function parseTime(s, dayFirst) {
    if (!s) return null;
    s = String(s).trim().replace(/^"|"$/g, "");
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/);
    if (m) {
      if (m[7]) { const d = new Date(m[1] + "-" + m[2] + "-" + m[3] + "T" + (m[4] || "12") + ":" + (m[5] || "00") + ":" + (m[6] || "00") + (m[7] === "Z" ? "Z" : m[7].replace(/^([+-]\d{2})(\d{2})$/, "$1:$2"))); return isNaN(d) ? null : fmt(d); }
      return m[1] + "-" + m[2] + "-" + m[3] + " " + (m[4] || "12") + ":" + (m[5] || "00") + ":" + (m[6] || "00");
    }
    m = s.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})(?:[ T,]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
    if (m) {
      let a = Number(m[1]), b = Number(m[2]), y = Number(m[3]); if (y < 100) y += 2000;
      let mon = dayFirst ? b : a, day = dayFirst ? a : b;
      if (mon > 12) { const x = mon; mon = day; day = x; }
      if (mon < 1 || mon > 12 || day < 1 || day > 31) return null;
      return y + "-" + pad(mon) + "-" + pad(day) + " " + pad(m[4] || 12) + ":" + (m[5] || "00") + ":" + (m[6] || "00");
    }
    if (/^\d{12,13}$/.test(s)) { const d = new Date(Number(s.length === 13 ? s : s + "000")); return isNaN(d) ? null : fmt(d); }
    return null;
  }

  /* Collects rows: drops old ones, keeps one pulse per hour, pairs blood-pressure halves. */
  function Collector(now) {
    const since = fmt(new Date((now || Date.now()) - DAYS * 864e5)), rows = [], seen = new Set(), bp = new Map();
    return {
      add(kind, v1, at, extra) {
        if (!at || at < since || !(v1 > 0)) return;
        if (kind === "pulse") { const k = "p" + at.slice(0, 13); if (seen.has(k)) return; seen.add(k); }
        else { const k = kind + at; if (seen.has(k)) return; seen.add(k); }
        rows.push(Object.assign({ kind, v1: Math.round(v1 * 10) / 10, v2: null, at }, extra || {}));
      },
      bpHalf(which, v, at) {
        if (!at || at < since) return;
        const r = bp.get(at) || {}; r[which] = v; bp.set(at, r);
      },
      done() {
        bp.forEach((r, at) => { if (r.sys && r.dia && r.sys > r.dia) { const k = "bp" + at; if (!seen.has(k)) { seen.add(k); rows.push({ kind: "bp", v1: Math.round(r.sys), v2: Math.round(r.dia), at }); } } });
        return rows.sort((a, b) => a.at < b.at ? -1 : 1);
      }
    };
  }

  /* ---------- Apple Health export.xml (read in pieces: the file can be several hundred MB) ---------- */
  const HK = {
    HKQuantityTypeIdentifierHeartRate: "pulse", HKQuantityTypeIdentifierRestingHeartRate: "pulse",
    HKQuantityTypeIdentifierOxygenSaturation: "spo2", HKQuantityTypeIdentifierBodyMass: "weight",
    HKQuantityTypeIdentifierBloodGlucose: "sugar", HKQuantityTypeIdentifierBodyTemperature: "temp",
    HKQuantityTypeIdentifierBloodPressureSystolic: "sys", HKQuantityTypeIdentifierBloodPressureDiastolic: "dia"
  };
  const attr = (tag, name) => { const m = tag.match(new RegExp("\\s" + name + "=\"([^\"]*)\"")); return m ? m[1] : null; };
  function appleRecord(tag, col) {
    const type = attr(tag, "type"), kind = HK[type]; if (!kind) return;
    let v = Number(attr(tag, "value")); const unit = attr(tag, "unit") || "", at = parseTime(attr(tag, "startDate"));
    if (!isFinite(v)) return;
    if (kind === "sys" || kind === "dia") { col.bpHalf(kind, v, at); return; }
    if (kind === "spo2" && v <= 1) v *= 100;
    if (kind === "weight" && /lb/.test(unit)) v *= 0.45359237;
    if (kind === "sugar" && /mmol/i.test(unit)) v *= 18.016;
    if (kind === "temp" && /F/.test(unit)) v = (v - 32) * 5 / 9;
    col.add(kind, v, at);
  }
  function XmlScanner(col) {
    let rest = "";
    return {
      push(text) {
        const s = rest + text; let i = 0;
        for (;;) {
          const a = s.indexOf("<Record ", i); if (a < 0) { rest = s.slice(Math.max(i, s.length - 8)); return; }
          const b = s.indexOf(">", a); if (b < 0) { rest = s.slice(a); return; }
          appleRecord(s.slice(a, b + 1), col); i = b + 1;
        }
      }
    };
  }

  /* ---------- CSV (Samsung Health, Google Fit, Mi Fitness and others) ---------- */
  function splitCsv(line) {
    const out = []; let cur = "", q = false;
    for (let i = 0; i < line.length; i++) {
      const c = line[i];
      if (q) { if (c === '"') { if (line[i + 1] === '"') { cur += '"'; i++; } else q = false; } else cur += c; }
      else if (c === '"') q = true; else if (c === "," || c === ";" || c === "\t") { out.push(cur); cur = ""; } else cur += c;
    }
    out.push(cur); return out.map(x => x.trim());
  }
  const COLS = [
    ["sys", /systolic/i], ["dia", /diastolic/i], ["spo2", /spo2|oxygen|blood.?oxygen/i], ["pulse", /heart.?rate|pulse|bpm/i],
    ["sugar", /glucose|blood.?sugar/i], ["weight", /(^|[^a-z])weight/i], ["temp", /temperature/i]
  ];
  const SKIP = /min|max|lower|upper|deviation|goal|target|binning|count|tag|comment|custom|source|uuid|offset|device|pkg|zone|hrv|variab/i;
  function parseCsv(text, col) {
    const lines = text.split(/\r?\n/); let head = null, hi = -1;
    for (let i = 0; i < Math.min(lines.length, 5); i++) { const c = splitCsv(lines[i]); if (c.length >= 2 && c.some(x => /time|date/i.test(x))) { head = c; hi = i; break; } }
    if (!head) return;
    const name = (x) => x.replace(/^com\.samsung\.(shealth|health)\.[a-z_.]*\./i, "");
    const timeCol = head.findIndex(x => /start.?time|^time$|date.?time|measure.*time|^date$|timestamp/i.test(name(x)));
    const dateCol = head.findIndex(x => /^date$/i.test(name(x)));
    if (timeCol < 0) return;
    const valCols = [];
    head.forEach((x, i) => { const n = name(x); if (SKIP.test(n) && !/^(average|avg)/i.test(n)) return; for (const [k, re] of COLS) if (re.test(n)) { if (!valCols.some(v => v.kind === k)) valCols.push({ kind: k, i, lb: /\(lb|lbs/i.test(n), mmol: /mmol/i.test(n), f: /°?F\)|fahrenheit/i.test(n) }); break; } });
    if (!valCols.length) return;
    for (let r = hi + 1; r < lines.length; r++) {
      if (!lines[r].trim()) continue;
      const c = splitCsv(lines[r]);
      let tx = c[timeCol];
      if (dateCol >= 0 && dateCol !== timeCol && /^\d{1,2}:\d{2}/.test(tx || "")) tx = c[dateCol] + " " + tx;
      const at = parseTime(tx, true); if (!at) continue;
      for (const v of valCols) {
        let x = Number(String(c[v.i] || "").replace(",", ".")); if (!isFinite(x) || x <= 0) continue;
        if (v.kind === "sys" || v.kind === "dia") { col.bpHalf(v.kind, x, at); continue; }
        if (v.kind === "spo2" && x <= 1) x *= 100;
        if (v.kind === "weight" && v.lb) x *= 0.45359237;
        if (v.kind === "sugar" && (v.mmol || x < 30)) x *= 18.016;
        if (v.kind === "temp" && (v.f || x > 50)) x = (x - 32) * 5 / 9;
        col.add(v.kind, x, at);
      }
    }
  }

  /* ---------- Fitbit JSON ---------- */
  function parseJson(text, col) {
    let data; try { data = JSON.parse(text); } catch (e) { return; }
    if (!Array.isArray(data)) return;
    data.forEach(d => {
      if (!d || typeof d !== "object") return;
      if (d.dateTime && d.value && typeof d.value === "object" && d.value.bpm) col.add("pulse", Number(d.value.bpm), parseTime(d.dateTime, false));
      else if (d.weight && d.date) col.add("weight", Number(d.weight), parseTime(d.date + " " + (d.time || "08:00:00"), false));
      else if (d.timestamp && d.value !== undefined && d.type === "spo2") col.add("spo2", Number(d.value), parseTime(d.timestamp));
    });
  }

  /* ---------- Zip (Apple's export.zip): find export.xml and inflate it as a stream ---------- */
  async function zipXmlStream(file) {
    const tail = new DataView(await file.slice(Math.max(0, file.size - 65557)).arrayBuffer());
    let eocd = -1; for (let i = tail.byteLength - 22; i >= 0; i--) if (tail.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
    if (eocd < 0) throw new Error("zip");
    let cdOff = tail.getUint32(eocd + 16, true), cdSize = tail.getUint32(eocd + 12, true);
    if (cdOff === 0xffffffff) {   // zip64 (exports over 4 GB)
      const loc = eocd - 20; if (loc < 0 || tail.getUint32(loc, true) !== 0x07064b50) throw new Error("zip");
      const e64 = new DataView(await file.slice(Number(tail.getBigUint64(loc + 8, true)), Number(tail.getBigUint64(loc + 8, true)) + 56).arrayBuffer());
      cdSize = Number(e64.getBigUint64(40, true)); cdOff = Number(e64.getBigUint64(48, true));
    }
    const cd = new DataView(await file.slice(cdOff, cdOff + cdSize).arrayBuffer()), dec = new TextDecoder();
    for (let p = 0; p + 46 <= cd.byteLength && cd.getUint32(p, true) === 0x02014b50;) {
      const method = cd.getUint16(p + 10, true), nl = cd.getUint16(p + 28, true), el = cd.getUint16(p + 30, true), cl = cd.getUint16(p + 32, true);
      let comp = cd.getUint32(p + 20, true), off = cd.getUint32(p + 42, true);
      const name = dec.decode(new Uint8Array(cd.buffer, cd.byteOffset + p + 46, nl));
      if (comp === 0xffffffff || off === 0xffffffff) {   // sizes in the zip64 extra field
        const ex = new DataView(cd.buffer, cd.byteOffset + p + 46 + nl, el);
        for (let q = 0; q + 4 <= el;) { const id = ex.getUint16(q, true), sz = ex.getUint16(q + 2, true); if (id === 1) { let r = q + 4; if (cd.getUint32(p + 24, true) === 0xffffffff) r += 8; if (comp === 0xffffffff) { comp = Number(ex.getBigUint64(r, true)); r += 8; } if (off === 0xffffffff) off = Number(ex.getBigUint64(r, true)); } q += 4 + sz; }
      }
      if (/(^|\/)export\.xml$/.test(name)) {
        const lh = new DataView(await file.slice(off, off + 30).arrayBuffer());
        const start = off + 30 + lh.getUint16(26, true) + lh.getUint16(28, true), body = file.slice(start, start + comp).stream();
        if (method === 0) return body;
        if (method !== 8 || typeof DecompressionStream === "undefined") throw new Error("zip");
        return body.pipeThrough(new DecompressionStream("deflate-raw"));
      }
      p += 46 + nl + el + cl;
    }
    throw new Error("noxml");
  }

  async function readStream(stream, onText, onProgress, total) {
    const reader = stream.getReader(), dec = new TextDecoder(); let n = 0;
    for (;;) { const { done, value } = await reader.read(); if (done) break; n += value.byteLength; onText(dec.decode(value, { stream: true })); if (onProgress && total) onProgress(Math.min(99, Math.round(n * 100 / total))); }
    onText(dec.decode());
  }

  /* Main entry: a File from <input type=file>. */
  async function readFile(file, onProgress) {
    const col = Collector(), name = (file.name || "").toLowerCase();
    const head = await file.slice(0, 4).arrayBuffer(), magic = new DataView(head).getUint32(0, true);
    if (magic === 0x04034b50) {   // zip
      const sc = XmlScanner(col);
      await readStream(await zipXmlStream(file), (tx) => sc.push(tx), onProgress, file.size * 8);
    } else if (/\.xml$/.test(name) || (await file.slice(0, 200).text()).includes("<HealthData")) {
      const sc = XmlScanner(col);
      await readStream(file.stream(), (tx) => sc.push(tx), onProgress, file.size);
    } else if (/\.json$/.test(name)) parseJson(await file.text(), col);
    else parseCsv(await file.text(), col);
    return col.done();
  }

  const api = { readFile, parseCsv, parseJson, parseTime, Collector, XmlScanner };
  if (typeof module !== "undefined" && module.exports) module.exports = api; else root.HealthImport = api;
})(typeof window !== "undefined" ? window : this);
