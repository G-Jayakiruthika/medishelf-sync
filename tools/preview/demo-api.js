/* AushadhBox preview: an in-browser stand-in for api.php, so the app runs on a static preview link.
   Data lives only in this browser (localStorage). Loaded before every other script on preview pages. */
(function () {
  "use strict";
  var KEY = "ab-demo-v4", DATA = null, dataPromise = null;
  var today = new Date(), iso = function (d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); };
  var TODAY = iso(today);
  var ym = function (add) { var d = new Date(today.getFullYear(), today.getMonth() + add, 1); return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0"); };
  var dayAdd = function (n) { var d = new Date(today); d.setDate(d.getDate() + n); return iso(d); };

  function seed() {
    var users = [
      { id: 1, name: "Lakshmi", email: "demo@aushadhbox.in", password: "demo1234", phone: "", language: "en", role: "admin", plan: "premium", planUntil: dayAdd(240), household: null, weekly: true, onboarded: true },
      { id: 2, name: "Ravi", email: "ravi@aushadhbox.in", password: "demo1234", phone: "", language: "ta", role: "user", plan: "free", planUntil: null, household: 1, weekly: true, onboarded: true }
    ];
    var members = [{ id: 1, hid: 1, name: "Lakshmi", relation: "Self", allergies: "", self: true }, { id: 2, hid: 1, name: "Amma", relation: "Mother", allergies: "Penicillin", self: false, bloodGroup: "B+", conditions: "High blood pressure", emergencyContact: "Lakshmi 98765 43210" },
      { id: 3, hid: 1, name: "Appa", relation: "Father", allergies: "", self: false, bloodGroup: "O+", conditions: "Type 2 diabetes, cholesterol", emergencyContact: "Ravi 98400 12345" }, { id: 4, hid: 1, name: "Anu", relation: "Daughter", allergies: "", self: false }, { id: 5, hid: 1, name: "Ravi", relation: "Husband", allergies: "", self: false }];
    var it = function (id, name, member, exp, times, batch, stock, courseEnd, ing) { return { id: id, hid: 1, member_id: member, name: name, exp: exp, mfg: null, batch: batch, quantity: "", notes: "", times: times, stock: stock, perDose: 1, courseEnd: courseEnd || null, ing: ing, catalog_id: null }; };
    var items = [
      it(1, "Dolo 650 (Paracetamol 650 mg)", 1, ym(1), [], "DOL4471", null, null, "paracetamol"),
      it(2, "Telmisartan 40 mg", 2, ym(10), ["m"], "TLM2210", 9, null, "telmisartan"),
      it(3, "Metformin 500 mg", 3, ym(5), ["m", "e"], "MTF0912", 46, null, "metformin"),
      it(4, "Amlodipine 5 mg", 2, ym(2), ["e"], "AML3381", 22, null, "amlodipine"),
      it(5, "Cetirizine 10 mg", 4, ym(-1), [], "CTZ1180", null, null, "cetirizine"),
      it(6, "Pantoprazole 40 mg", 3, ym(7), ["m"], "PAN5521", 28, null, "pantoprazole"),
      it(7, "Atorvastatin 10 mg", 3, ym(13), ["b"], "ATV7705", 30, null, "atorvastatin"),
      it(8, "Azithromycin 500 mg", 5, ym(18), ["m"], "AZX2309", 3, dayAdd(2), "azithromycin"),
      it(9, "ORS sachet", 4, ym(-2), [], "ORS1002", null, null, null),
      it(10, "Crocin 500", 1, ym(6), ["e"], "CRO8812", 15, null, "paracetamol")
    ];
    items[0].times = ["m"];
    // A week of history, so the weekly report and the pillbox have something to show.
    var taken = [], rnd = 7;
    var next = function () { rnd = (rnd * 9301 + 49297) % 233280; return rnd / 233280; };
    for (var d = 7; d >= 1; d--) items.forEach(function (i) {
      i.times.forEach(function (sl) {
        var r = next(), day = dayAdd(-d), hh = { m: "08", a: "13", e: "19", b: "22" }[sl];
        if (i.member_id === 2) { if (r < 0.86) taken.push({ hid: 1, item: i.id, slot: sl, day: day, status: "taken", source: "box", at: hh + ":0" + Math.floor(r * 9) }); return; }
        if (i.member_id === 3 && sl === "e" && r < 0.45) return;                                   // Appa often forgets the evening dose
        if (i.member_id === 3 && sl === "b" && d === 3) { taken.push({ hid: 1, item: i.id, slot: sl, day: day, status: "skipped", reason: "side", source: "app" }); return; }
        if (r < 0.9) taken.push({ hid: 1, item: i.id, slot: sl, day: day, status: "taken", source: r < 0.3 ? "notif" : "app", at: hh + ":1" + Math.floor(r * 9) });
      });
    });
    var devices = [{ id: 1, hid: 1, name: "Amma's pillbox", memberId: 2, compartments: 4, paired: true, pairCode: null, battery: 82, firmware: "1.0.0", lastSeen: TODAY + " 07:58" }];
    var devEvents = [{ deviceId: 1, kind: "open", lid: 1, at: dayAdd(-1) + " 08:04", slot: "m", outcome: "taken" }, { deviceId: 1, kind: "open", lid: 3, at: dayAdd(-1) + " 19:41", slot: "e", outcome: "taken" },
      { deviceId: 1, kind: "open", lid: 3, at: dayAdd(-1) + " 21:15", slot: "e", outcome: "again" }, { deviceId: 1, kind: "open", lid: 1, at: dayAdd(-2) + " 18:02", slot: null, outcome: "outside" }].reverse();
    return { users: users, members: members, items: items, taken: taken, devices: devices, devEvents: devEvents, kit: ["supply", "numbers"], session: null, nextId: 100, invites: {},
      auto: { refill: true, refillDays: 5, missed: true, missedAfter: 60, course: true, cleanup: true, timing: true, watchHours: 24, calendar: true },
      reminders: { 1: { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" }, 2: { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" } },
      payments: [{ created_at: TODAY + " 10:12", period: "year", amount: 399, status: "paid", order_id: "demo_order_1", name: "Lakshmi" }],
      messages: [
        triage({ id: 3, name: "Ravi Kumar", email: "ravi.k@example.in", topic: "billing", message: "Money was debited twice from my UPI but Premium is not active!! Please refund one.", handled: 0, created_at: TODAY + " 08:12" }),
        triage({ id: 2, name: "Meena", email: "meena@example.in", topic: "privacy", message: "Please delete my account and all my personal data.", handled: 0, created_at: dayAdd(-1) + " 19:05" }),
        triage({ id: 1, name: "Priya S", email: "priya@example.in", topic: "help", message: "Can my father use this on his phone in Tamil? Thank you, great app!", handled: 0, created_at: dayAdd(-2) + " 09:40" })],
      vitals: (function () { var v = [], id = 0; for (var d = 13; d >= 0; d--) { var sys = 128 + Math.round(Math.sin(d) * 8) + (d < 3 ? 14 : 0), dia = 82 + Math.round(Math.cos(d) * 5) + (d < 3 ? 6 : 0);
          v.push({ id: ++id, memberId: 2, kind: "bp", v1: sys, v2: dia, context: null, flag: sys >= 140 || dia >= 90 ? "high" : "ok", note: null, at: dayAdd(-d) + " 08:1" + (d % 9) });
          v.push({ id: ++id, memberId: 3, kind: "sugar", v1: 118 + Math.round(Math.sin(d * 2) * 14), v2: null, context: "fasting", flag: 118 + Math.round(Math.sin(d * 2) * 14) >= 126 ? "high" : "ok", note: null, at: dayAdd(-d) + " 07:3" + (d % 9) }); } return v; })(),
      followups: [{ id: 1, itemId: 6, name: "Pantoprazole 40 mg", memberId: 3, step: 1, due: TODAY }],
      fuAnswers: [{ name: "Metformin 500 mg", answer: "side", note: "Mild stomach upset after the evening tablet", d: dayAdd(-6), memberId: 3 }],
      appointments: [{ id: 1, memberId: 3, doctor: "Dr. S. Kumar (Diabetologist)", place: "KMCH, Coimbatore", at: dayAdd(2) + " 10:30:00", questions: "Can the evening Metformin be taken after dinner instead?" }],
      pharmacy: { name: "MedPlus Gandhipuram", phone: "9876543210" },
      ai: false, settings: { digest_on: true, nudges_on: true, ack_on: true, ai_on: true, autoheal_on: true },
      insights: [{ id: 1, day: dayAdd(-1), kind: "anomaly", metric: "messages", severity: "high", title: "Support messages jumped to 9 on " + dayAdd(-1).slice(5), body: "Usually about 2 a day. Something may be confusing or broken; read the newest support messages." },
        { id: 2, day: TODAY, kind: "growth", metric: "upgrade", severity: "medium", title: "4 free families are close to the Free plan limits", body: "They are active and near 30 medicines or 4 people. The autopilot sends them one friendly note; consider a first-month discount." },
        { id: 3, day: dayAdd(-1), kind: "anomaly", metric: "signups", severity: "good", title: "Sign-ups jumped to 23 on " + dayAdd(-1).slice(5), body: "Usually about 6 a day. Find out where they came from (Where visitors come from) and do more of it." },
        { id: 4, day: TODAY, kind: "growth", metric: "source", severity: "good", title: "Top source this week: whatsapp (420 visitors)", body: "Do more of what brings these visitors." }],
      log: [{ kind: "heal", title: "Activated Premium for 1 payment(s) that got stuck", created_at: TODAY + " 07:00" }, { kind: "digest", title: "Daily digest sent (2)", created_at: TODAY + " 09:00" },
        { kind: "growth", title: "Sent 7 helpful reminders", created_at: TODAY + " 10:00" }, { kind: "heal", title: "Sorted 3 support messages", created_at: dayAdd(-1) + " 22:00" }, { kind: "seo", title: "IndexNow: submitted 846 of 846 pages", created_at: dayAdd(-2) + " 06:05" }],
      audit: [{ action: "support_reply", detail: "AB-000117 (e-mailed)", created_at: dayAdd(-1) + " 18:20", name: "Lakshmi" }] };
  }
  var S;
  try { S = JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) { S = null; }
  if (!S || !S.users) S = seed();
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* private window: keep in memory */ } }
  window.AB_DEMO = { reset: function () { S = seed(); save(); }, signInDemo: function () { S.session = 1; save(); } };
  if (/[?&]demo=signin/.test(location.search)) { S.session = 1; save(); }

  function loadData() {
    if (DATA) return Promise.resolve(DATA);
    if (!dataPromise) dataPromise = fetch("demo-data.json").then(function (r) { return r.json(); }).then(function (d) { DATA = d; return d; });
    return dataPromise;
  }
  var norm = function (s) { return String(s || "").toLowerCase().replace(/[^a-z0-9.%+]+/g, " ").trim(); };
  function infoBySlug(slug) { if (!DATA || !slug) return null; var i = DATA.info.find(function (x) { return x.slug === slug; }); return i ? pubInfo(i) : null; }
  function infoById(id) { if (!DATA || !id) return null; var i = DATA.info.find(function (x) { return x.id === id; }); return i ? pubInfo(i) : null; }
  function pubInfo(i) { return { id: i.id, ingredient: i.ingredient, use: i.use, rx: !!i.rx, storage: i.storage, note: i.note, slug: i.slug, usualTimes: i.ut ? i.ut.split(",") : [], courseDays: i.cd || null, food: i.fd || null }; }
  function ingredientsIn(text) { var t = " " + norm(text) + " ", out = []; (DATA ? DATA.info : []).forEach(function (i) { if (i.keywords.split(",").some(function (k) { return k && t.indexOf(" " + k.trim()) >= 0; })) out.push(i.id); }); return out; }
  function cat(c) { return { id: c.id, name: c.n, unit: c.u, code: c.c, source: "janaushadhi-2025-12", info: infoById(c.i) }; }

  var me = function () { return S.users.find(function (u) { return u.id === S.session; }) || null; };
  var hid = function (u) { return u.household || u.id; };
  var owner = function (u) { return S.users.find(function (x) { return x.id === hid(u); }); };
  var premium = function (u) { var o = owner(u); return o.plan === "premium" && o.planUntil >= TODAY; };
  function limits(u) { return premium(u) ? { members: 15, items: 2000, accounts: 6 } : { members: 4, items: 30, accounts: 1 }; }
  function billing(u) {
    var h = hid(u), o = owner(u), p = premium(u);
    return { plan: p ? "premium" : "free", until: p ? o.planUntil : null, expiredOn: null, owner: !u.household, ownerName: o.name, limits: limits(u),
      usage: { members: S.members.filter(function (m) { return m.hid === h; }).length, items: S.items.filter(function (i) { return i.hid === h; }).length, accounts: S.users.filter(function (x) { return x.id === h || x.household === h; }).length },
      payments: "demo", prices: { month: 49, year: 399 } };
  }
  function pubUser(u) { return { id: u.id, name: u.name, email: u.email, phone: u.phone, language: u.language, admin: u.role === "admin", consentCurrent: true, plan: premium(u) ? "premium" : "free", planUntil: premium(u) ? owner(u).planUntil : null, owner: !u.household, weeklyEmail: u.weekly, onboarded: u.onboarded, aiOptIn: !!u.aiOptIn }; }
  function pubItem(i) {
    var info = i.info_id ? infoById(i.info_id) : (i.ing ? infoBySlug(i.ing) : null);
    return { id: i.id, member_id: i.member_id, name: i.name, catalog_id: i.catalog_id, info: info, batch: i.batch, mfg: i.mfg, exp: i.exp, quantity: i.quantity, times: i.times, notes: i.notes, stock: i.stock, perDose: i.perDose || 1, courseEnd: i.courseEnd };
  }
  function vflag(k, v1, v2, ctx) {
    if (k === "bp") return v1 >= 180 || v2 >= 120 ? "urgent" : v1 < 90 || v2 < 60 ? "low" : v1 >= 140 || v2 >= 90 ? "high" : "ok";
    if (k === "sugar") return v1 < 54 || v1 >= 300 ? "urgent" : v1 < 70 ? "low" : v1 >= (ctx === "fasting" ? 126 : 200) ? "high" : "ok";
    if (k === "temp") return v1 >= 40 || v1 < 35 ? "urgent" : v1 >= 38 ? "high" : "ok";
    if (k === "spo2") return v1 < 92 ? "urgent" : v1 < 95 ? "low" : "ok";
    if (k === "pulse") return v1 < 40 || v1 > 130 ? "urgent" : v1 < 50 ? "low" : v1 > 100 ? "high" : "ok";
    return "ok";
  }
  function admin() { var u = need(); if (u.role !== "admin") fail(403, "forbidden", "Only administrators can do this."); return u; }
  function demoAsk(q) {
    q = q.toLowerCase();
    var days = /week|7 days/.test(q) ? 7 : /today/.test(q) ? 1 : 30, label = days === 7 ? "the last 7 days" : days === 1 ? "today" : "the last 30 days";
    var series = function (base, spread) { var pts = []; for (var i = days - 1; i >= 0; i--) pts.push({ d: dayAdd(-i), n: Math.max(0, Math.round(base + Math.sin(i * 1.7) * spread + (i < 2 ? spread : 0))) }); return pts; };
    var sum = function (pts) { return pts.reduce(function (a, p) { return a + p.n; }, 0); };
    var M = [[/revenue|money|earn|sales|₹/, "Revenue (₹)", 300, 220, true], [/sign|new user|joined|registration/, "Sign-ups", 7, 5], [/visitor|traffic|visits/, "Website visitors", 170, 50], [/dose|adherence|taken/, "Doses taken", 700, 60], [/fail/, "Failed payments", 0.4, 0.6], [/message|support|ticket/, "Support messages", 2, 2], [/pillbox|box|iot/, "Pillbox openings", 40, 8], [/medicines added|added|scan/, "Medicines added", 45, 15]];
    if (/where|source|come from|channel/.test(q)) return { answer: "Most visitors in " + label + " came from google.com (812), whatsapp (420) and direct visits (390). WhatsApp is growing fastest; keep sharing links with ?utm_source=whatsapp.", table: [{ label: "google.com", n: 812 }, { label: "whatsapp", n: 420 }, { label: "(direct)", n: 390 }, { label: "instagram", n: 118 }], chart: null, ai: false };
    if (/top page|popular/.test(q)) return { answer: "Top pages: the home page (1,210 views), Paracetamol 500 mg (402) and How to read the expiry date (388).", table: [{ label: "/", n: 1210 }, { label: "/medicines/paracetamol-tablets-ip-500-mg-23", n: 402 }, { label: "/guides/how-to-read-expiry-date-on-medicine", n: 388 }], chart: null, ai: false };
    if (/conversion|convert/.test(q)) return { answer: "Free → Premium conversion: 7.2% (9 of 125 families).", chart: null, table: null, ai: false };
    var hit = M.find(function (m) { return m[0].test(q); });
    if (!hit) return { answer: "I can answer questions about sign-ups, active families, doses, medicines added, revenue, purchases, failed payments, visitors, page views, support messages, pillbox openings, Premium families and conversion, top pages and traffic sources. Try \"revenue this week\" or \"where do visitors come from\".", chart: null, table: null, ai: false };
    var pts = series(hit[2], hit[3]), prev = Math.round(sum(pts) * 0.82), cur = sum(pts), chg = prev ? Math.round(100 * (cur - prev) / prev) : 0;
    return { answer: hit[1] + " in " + label + ": " + (hit[4] ? "₹" : "") + cur.toLocaleString("en-IN") + " (" + (chg >= 0 ? "+" : "") + chg + "% vs the period before).", chart: days > 1 ? { label: hit[1], points: pts } : null, table: null, ai: false };
  }
  function nowHM() { var n = new Date(); return String(n.getHours()).padStart(2, "0") + ":" + String(n.getMinutes()).padStart(2, "0"); }
  function dueNow(h, slot) { return S.items.filter(function (i) { return i.hid === h && (i.times || []).indexOf(slot) >= 0 && (!i.courseEnd || i.courseEnd >= TODAY); }); }
  function pubDevice(d) { return { id: d.id, name: d.name, memberId: d.memberId, compartments: d.compartments, paired: d.paired, pairCode: d.paired ? null : d.pairCode, battery: d.battery, firmware: d.firmware, lastSeen: d.lastSeen, online: d.paired && !!d.lastSeen && d.lastSeen.slice(0, 10) === TODAY }; }
  function myDevice(id) { var h = hid(need()), d = S.devices.find(function (x) { return x.id === id && x.hid === h; }); if (!d) fail(404, "not_found", "That record was not found."); return d; }
  /* Same rules as core/support.php (shortened) */
  function triage(m) {
    var t = (m.topic + " " + m.message).toLowerCase(), cat = "other";
    var rules = [["privacy", /delete my|erase|my data|privacy|personal data/], ["billing", /refund|charged|debited|payment|premium|upi|razorpay/], ["bug", /not working|crash|error|blank|stuck|can't|cannot/], ["medical", /side effect|can i take|overdose|dose of/], ["feature", /please add|feature|suggest/]];
    rules.some(function (r) { if (r[1].test(t)) { cat = r[0]; return true; } return false; });
    var prio = cat === "privacy" || /refund|charged|debited|overdose/.test(t) ? "high" : cat === "feature" ? "low" : "normal";
    var sent = /worst|useless|fraud|!!/.test(t) ? "negative" : /thank|great|love/.test(t) ? "positive" : "neutral";
    var first = m.name.split(" ")[0], h = prio === "high" ? 24 : prio === "normal" ? 72 : 168;
    var d = new Date(m.created_at.replace(" ", "T")); d.setHours(d.getHours() + h);
    var body = { billing: "Thank you for writing about your payment. Premium never renews by itself, so you are never charged without paying yourself. If money was taken twice, the extra amount is refunded to the same UPI account within 5–7 working days once we confirm it; reply with the Razorpay payment ID from your SMS and we'll check it today.",
      privacy: "Thank you for your request about your personal data. You can download everything we hold (Account → Download my data) and delete your account and all its data at any time (Account → Delete my account). If you'd like us to do it for you, reply from this e-mail address to confirm.",
      other: "Thank you for your message. Yes: the app works in Tamil and 9 other Indian languages. Choose தமிழ் in the language menu, and the voice assistant will speak Tamil too.",
      bug: "Thank you for telling us, and sorry for the trouble. Could you tell us your phone model and browser, and the steps that led to the problem?" }[cat] || "Thank you for your message. We'll get back to you soon.";
    return Object.assign(m, { category: cat, priority: prio, sentiment: sent, due_at: iso(d) + " " + String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0"),
      suggested: "Hello " + first + ",\n\n" + body + "\n\nWarm regards,\nAushadhBox support", ticket: "AB-" + String(m.id).padStart(6, "0") });
  }
  /* Same tables as core/safety.php */
  var CLS = { nsaid: ["Ibuprofen", "Aceclofenac", "Diclofenac", "Etoricoxib", "Nimesulide", "Aspirin"], antihistamine: ["Cetirizine", "Levocetirizine", "Fexofenadine", "Chlorpheniramine"], ppi: ["Pantoprazole", "Omeprazole", "Rabeprazole"],
    penicillin: ["Amoxicillin", "Amoxicillin + Clavulanate"], quinolone: ["Ciprofloxacin", "Ofloxacin", "Levofloxacin", "Norfloxacin"], bp_raas: ["Telmisartan", "Losartan", "Ramipril"], statin: ["Atorvastatin", "Rosuvastatin"], benzo: ["Alprazolam", "Clonazepam"], minerals: ["Antacid", "Calcium + Vitamin D3", "Iron + Folic acid"] };
  var SAMEKIND = ["nsaid", "antihistamine", "ppi", "penicillin", "quinolone", "bp_raas", "statin", "benzo"];
  var PAIRS = [["nsaid", "Clopidogrel", "high", "bleed"], ["nsaid", "bp_raas", "medium", "kidney"], ["Clopidogrel", "Omeprazole", "medium", "clopi"], ["Tramadol", "benzo", "high", "breathing"], ["Tramadol", "Escitalopram", "high", "serotonin"]];
  var GAPS = [["quinolone", "minerals", 2], ["Doxycycline", "minerals", 2], ["Levothyroxine", "minerals", 4]];
  var clsOf = function (ing) { return Object.keys(CLS).find(function (c) { return CLS[c].indexOf(ing) >= 0; }) || null; };
  var matches = function (ing, spec) { return ing === spec || (CLS[spec] && CLS[spec].indexOf(ing) >= 0); };
  function safety(h) {
    var out = [], seen = {}, add = function (a) { var k = a.code + a.items.join(","); if (!seen[k]) { seen[k] = 1; out.push(a); } };
    var people = {};
    S.items.filter(function (i) { return i.hid === h; }).forEach(function (i) { var inf = pubItem(i).info; if (inf) (people[i.member_id || 0] = people[i.member_id || 0] || []).push({ i: i, ing: inf.ingredient }); });
    Object.keys(people).forEach(function (pid) {
      var mem = S.members.find(function (m) { return m.id === Number(pid); }), who = mem ? mem.name : "", allergy = (mem && mem.allergies || "").toLowerCase();
      people[pid].forEach(function (x) { var hit = allergy && (allergy.indexOf(x.ing.split(" + ")[0].toLowerCase()) >= 0 || (allergy.indexOf("penicillin") >= 0 && matches(x.ing, "penicillin"))) ? (allergy.split(",")[0].trim() || x.ing) : null;
        if (hit) add({ level: "high", code: "allergy", memberId: Number(pid) || null, items: [x.i.id], p: { who: who, allergy: hit, a: x.i.name, ing: x.ing } }); });
      var use = people[pid].filter(function (x) { return x.i.times.length && (!x.i.courseEnd || x.i.courseEnd >= TODAY) && (!x.i.exp || x.i.exp >= TODAY.slice(0, 7)); });
      for (var a = 0; a < use.length; a++) for (var b = a + 1; b < use.length; b++) {
        var A = use[a], B = use[b], base = { memberId: Number(pid) || null, items: [A.i.id, B.i.id] }, p = { who: who, a: A.i.name, b: B.i.name, ing: A.ing };
        if (A.ing === B.ing) { add(Object.assign({ level: "high", code: "same", p: p }, base)); continue; }
        var ca = clsOf(A.ing); if (ca && ca === clsOf(B.ing) && SAMEKIND.indexOf(ca) >= 0) add(Object.assign({ level: "medium", code: "kind", p: p }, base));
        PAIRS.forEach(function (r) { if ((matches(A.ing, r[0]) && matches(B.ing, r[1])) || (matches(A.ing, r[1]) && matches(B.ing, r[0]))) add(Object.assign({ level: r[2], code: "pair", p: Object.assign({ why: r[3] }, p) }, base)); });
        var shared = A.i.times.filter(function (x) { return B.i.times.indexOf(x) >= 0; });
        if (shared.length) GAPS.forEach(function (r) { if ((matches(A.ing, r[0]) && matches(B.ing, r[1])) || (matches(A.ing, r[1]) && matches(B.ing, r[0]))) add(Object.assign({ level: "low", code: "gap", p: Object.assign({ h: r[2], slot: shared[0] }, p) }, base)); });
      }
    });
    return out.sort(function (x, y) { return ["high", "medium", "low"].indexOf(x.level) - ["high", "medium", "low"].indexOf(y.level); });
  }
  function fail(status, code, message) { var e = new Error(message); e.status = status; e.code = code; throw e; }
  function need() { var u = me(); if (!u) fail(401, "not_signed_in", "Please sign in."); return u; }

  var actions = {
    session: function () { var u = me(); return { user: u ? pubUser(u) : null, csrf: "demo", consentVersion: "2026.2", features: { mail: false, push: false, bhashini: false } }; },
    signup: function (b) {
      if (!b.consent) fail(400, "no_consent", "Please read and agree to the privacy notice to create an account.");
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(b.email || "")) fail(400, "bad_email", "Please enter a valid e-mail address.");
      if (String(b.password || "").length < 8) fail(400, "weak_password", "Use at least 8 characters for your password.");
      if (S.users.some(function (u) { return u.email === String(b.email).toLowerCase(); })) fail(409, "email_taken", "An account with this e-mail already exists. Try signing in.");
      var id = ++S.nextId; S.users.push({ id: id, name: b.name, email: String(b.email).toLowerCase(), password: b.password, phone: b.phone || "", language: b.language || "en", role: "user", plan: "free", planUntil: null, household: null, weekly: true, onboarded: false });
      S.members.push({ id: ++S.nextId, hid: id, name: b.name, relation: "Self", allergies: "", self: true });
      S.reminders[id] = { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" }; S.session = id; return { ok: true, user: pubUser(me()) };
    },
    login: function (b) { var u = S.users.find(function (x) { return x.email === String(b.email || "").toLowerCase() && x.password === b.password; }); if (!u) fail(401, "bad_login", "E-mail or password is not correct. In this preview use demo@aushadhbox.in / demo1234."); S.session = u.id; return { ok: true, user: pubUser(u) }; },
    logout: function () { S.session = null; return { ok: true, csrf: "demo" }; },
    forgot: function () { fail(503, "mail_off", "Password reset e-mails are switched off in this preview. Use demo@aushadhbox.in / demo1234."); },
    bootstrap: function () {
      var u = need(), h = hid(u);
      return { user: pubUser(u), today: TODAY, members: S.members.filter(function (m) { return m.hid === h; }).map(function (m) { return { id: m.id, name: m.name, relation: m.relation, allergies: m.allergies, self: m.self, bloodGroup: m.bloodGroup || null, conditions: m.conditions || null, emergencyContact: m.emergencyContact || null }; }),
        items: S.items.filter(function (i) { return i.hid === h; }).map(pubItem), taken: S.taken.filter(function (t) { return t.day === TODAY && t.hid === h && t.status !== "skipped"; }).map(function (t) { return t.item + ":" + t.slot; }),
        doseLog: S.taken.filter(function (t) { return t.day === TODAY && t.hid === h; }).reduce(function (o, t) { o[t.item + ":" + t.slot] = { status: t.status || "taken", reason: t.reason || null, source: t.source || "app", at: t.at || "" }; return o; }, {}),
        reminders: S.reminders[u.id] || { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" }, kit: S.kit, billing: billing(u), alerts: safety(h),
        followups: S.followups, appointments: S.appointments, pharmacy: S.pharmacy };
    },
    member_save: function (b) {
      var u = need(), h = hid(u);
      if (b.id) { var m = S.members.find(function (x) { return x.id === b.id && x.hid === h; }); if (!m) fail(404, "not_found", "That record was not found."); m.name = b.name; m.relation = b.relation; m.allergies = b.allergies;
        if ("blood_group" in b) m.bloodGroup = b.blood_group || null; if ("conditions" in b) m.conditions = b.conditions || null; if ("emergency_contact" in b) m.emergencyContact = b.emergency_contact || null; return { ok: true, id: m.id }; }
      if (S.members.filter(function (m) { return m.hid === h; }).length >= limits(u).members) fail(402, "limit_members", "The Free plan covers 4 family members. Upgrade to Family Premium to add up to 15.");
      var id = ++S.nextId; S.members.push({ id: id, hid: h, name: b.name, relation: b.relation || "", allergies: b.allergies || "", self: false }); return { ok: true, id: id };
    },
    member_delete: function (b) { var h = hid(need()); S.members = S.members.filter(function (m) { return !(m.id === b.id && m.hid === h && !m.self); }); S.items.forEach(function (i) { if (i.member_id === b.id) i.member_id = null; }); return { ok: true }; },
    item_save: function (b) {
      var u = need(), h = hid(u);
      if (b.exp && !/^\d{4}-\d{2}$/.test(b.exp)) fail(400, "bad_exp", "Enter the expiry month and year printed on the pack.");
      if (!String(b.name || "").trim()) fail(400, "bad_name", "Enter the medicine name.");
      var rec = { hid: h, member_id: b.member_id || null, name: String(b.name).trim().slice(0, 160), exp: b.exp || null, mfg: b.mfg || null, batch: b.batch || null, quantity: b.quantity || "", notes: b.notes || "",
        times: Array.isArray(b.times) ? b.times : [], stock: b.stock === null || b.stock === undefined || b.stock === "" ? null : Math.max(0, Number(b.stock)), perDose: Math.max(1, Number(b.per_dose) || 1), courseEnd: b.course_end || null, catalog_id: b.catalog_id || null };
      if (b.catalog_id && DATA) { var c = DATA.catalog.find(function (x) { return x.id === b.catalog_id; }); if (c) rec.info_id = c.i; }
      if (!rec.info_id) { var ing = ingredientsIn(b.name + " " + (b.generic || "")); if (ing.length) rec.info_id = ing[0]; }
      var existing = b.id ? S.items.find(function (i) { return i.id === b.id && i.hid === h; }) : null;
      if (existing) Object.assign(existing, rec);
      else {
        if (S.items.filter(function (i) { return i.hid === h; }).length >= limits(u).items) fail(402, "limit_items", "The Free plan holds 30 medicines. Remove expired ones, or upgrade to Family Premium for unlimited medicines.");
        rec.id = ++S.nextId; S.items.push(rec); existing = rec;
      }
      return { ok: true, item: pubItem(existing) };
    },
    items_bulk_save: function (b) {
      var u = need(), h = hid(u), list = (b.items || []).slice(0, 40), have = S.items.filter(function (i) { return i.hid === h; }).length;
      if (!list.length) fail(400, "empty", "Nothing to save.");
      if (have + list.length > limits(u).items) fail(402, "limit_items", "The Free plan holds 30 medicines. You have " + have + ". Save fewer, or upgrade to Family Premium for unlimited medicines.");
      return { ok: true, items: list.map(function (one) { var c = Object.assign({}, one); delete c.id; return actions.item_save(c).item; }) };
    },
    dose_slot_taken: function (b) {
      var h = hid(need()), n = 0;
      S.items.filter(function (i) { return i.hid === h && (i.times || []).indexOf(b.slot) >= 0 && (!i.courseEnd || i.courseEnd >= TODAY); }).forEach(function (i) {
        if (S.taken.some(function (t) { return t.item === i.id && t.slot === b.slot && t.day === TODAY; })) return;   // already taken, or deliberately skipped
        S.taken.push({ hid: h, item: i.id, slot: b.slot, day: TODAY, status: "taken", source: b.source === "notif" ? "notif" : "app", at: nowHM() }); if (i.stock !== null) i.stock = Math.max(0, i.stock - (i.perDose || 1)); n++;
      });
      return { ok: true, ticked: n };
    },
    dose_skip: function (b) {
      var h = hid(need()); if (["fine", "side", "out", "doctor", "other"].indexOf(b.reason) < 0) fail(400, "bad_reason", "Choose why the dose was skipped.");
      var list = b.item_id ? S.items.filter(function (i) { return i.id === b.item_id && i.hid === h; }) : dueNow(h, b.slot).filter(function (i) { return !S.taken.some(function (t) { return t.item === i.id && t.slot === b.slot && t.day === TODAY && t.status !== "skipped"; }); });
      if (b.item_id && !list.length) fail(404, "not_found", "That record was not found.");
      list.forEach(function (i) {
        var ex = S.taken.find(function (t) { return t.item === i.id && t.slot === b.slot && t.day === TODAY; });
        if (ex) { if (ex.status !== "skipped" && i.stock !== null) i.stock += (i.perDose || 1); ex.status = "skipped"; ex.reason = b.reason; }
        else S.taken.push({ hid: h, item: i.id, slot: b.slot, day: TODAY, status: "skipped", reason: b.reason, source: "app" });
        if (b.reason === "out") i.stock = 0;
        if (b.reason === "doctor" && (!i.courseEnd || i.courseEnd > TODAY)) i.courseEnd = TODAY;
      });
      return { ok: true, skipped: list.length, items: list.map(pubItem) };
    },
    adherence: function (b, q) {
      var h = hid(need()), days = Number(q.get("days")) || 7, people = {};
      for (var d = days; d >= 1; d--) { var day = dayAdd(-d);
        S.items.filter(function (i) { return i.hid === h && i.times.length && (!i.courseEnd || i.courseEnd >= day); }).forEach(function (i) {
          var pid = i.member_id || 0, mem = S.members.find(function (m) { return m.id === pid; });
          var p = people[pid] = people[pid] || { name: mem ? mem.name : "Whole family", expected: 0, taken: 0, skipped: 0, missed: 0, box: 0, bySlot: { m: { expected: 0, taken: 0 }, a: { expected: 0, taken: 0 }, e: { expected: 0, taken: 0 }, b: { expected: 0, taken: 0 } }, reasons: {}, meds: {}, memberId: pid || null };
          p.meds[i.name] = p.meds[i.name] || { expected: 0, taken: 0 };
          i.times.forEach(function (sl) {
            p.expected++; p.bySlot[sl].expected++; p.meds[i.name].expected++;
            var l = S.taken.find(function (t) { return t.item === i.id && t.slot === sl && t.day === day; });
            if (l && l.status !== "skipped") { p.taken++; p.bySlot[sl].taken++; p.meds[i.name].taken++; if (l.source === "box") p.box++; }
            else if (l) { p.skipped++; p.reasons[l.reason || "other"] = (p.reasons[l.reason || "other"] || 0) + 1; } else p.missed++;
          });
        });
      }
      var out = Object.keys(people).map(function (k) { var p = people[k]; p.percent = Math.round(100 * p.taken / p.expected);
        var worst = null; Object.keys(p.bySlot).forEach(function (sl) { var v = p.bySlot[sl]; if (v.expected >= 2 && (worst === null || v.taken / v.expected < p.bySlot[worst].taken / p.bySlot[worst].expected)) worst = sl; });
        p.worstSlot = worst && p.bySlot[worst].taken < p.bySlot[worst].expected ? worst : null;
        p.medicines = Object.keys(p.meds).map(function (n) { return { name: n, expected: p.meds[n].expected, taken: p.meds[n].taken }; }); delete p.meds; return p; });
      out.sort(function (a, c) { return a.percent - c.percent; });
      return { from: dayAdd(-days), to: dayAdd(-1), people: out };
    },
    device_list: function () { var h = hid(need()); var ds = S.devices.filter(function (d) { return d.hid === h; });
      return { devices: ds.map(pubDevice), events: S.devEvents.filter(function (e) { return ds.some(function (d) { return d.id === e.deviceId; }); }).sort(function (x, y) { return x.at < y.at ? 1 : x.at > y.at ? -1 : 0; }).slice(0, 12) }; },
    device_add: function (b) { var h = hid(need()); if (!String(b.name || "").trim()) fail(400, "bad_name", "Enter a name.");
      var d = { id: ++S.nextId, hid: h, name: String(b.name).trim().slice(0, 40), memberId: b.member_id || null, compartments: Number(b.compartments) === 1 ? 1 : 4, paired: false, pairCode: String(Math.floor(100000 + Math.random() * 899999)), battery: null, firmware: null, lastSeen: null };
      S.devices.push(d); return { ok: true, device: pubDevice(d) }; },
    device_code: function (b) { var d = myDevice(b.id); d.paired = false; d.pairCode = String(Math.floor(100000 + Math.random() * 899999)); return { ok: true, device: pubDevice(d) }; },
    device_delete: function (b) { myDevice(b.id); S.devices = S.devices.filter(function (d) { return d.id !== b.id; }); return { ok: true }; },
    device_test: function (b) {
      var d = myDevice(b.id), h = d.hid, rem = S.reminders[h] || { m: "08:00", a: "13:30", e: "19:30", b: "22:00" }, now = new Date(), min = now.getHours() * 60 + now.getMinutes();
      var mins = function (hm) { var x = hm.split(":"); return Number(x[0]) * 60 + Number(x[1]); }, inWin = function (sl) { return min >= mins(rem[sl]) - 60 && min <= mins(rem[sl]) + 180; };
      var slot = null, lid = Number(b.lid) || 1;
      if (d.compartments >= 4) { slot = ["m", "a", "e", "b"][lid - 1]; if (!inWin(slot)) slot = null; }
      else ["m", "a", "e", "b"].forEach(function (sl) { if (inWin(sl) && (slot === null || Math.abs(min - mins(rem[sl])) < Math.abs(min - mins(rem[slot])))) slot = sl; });
      var outcome = "outside";
      if (slot) {
        var due = dueNow(h, slot).filter(function (i) { return !d.memberId || i.member_id === d.memberId; });
        if (!due.length) outcome = "nodose";
        else { var n = 0; due.forEach(function (i) { if (!S.taken.some(function (t) { return t.item === i.id && t.slot === slot && t.day === TODAY; })) { S.taken.push({ hid: h, item: i.id, slot: slot, day: TODAY, status: "taken", source: "box", at: nowHM() }); if (i.stock !== null) i.stock = Math.max(0, i.stock - (i.perDose || 1)); n++; } });
          outcome = n ? "taken" : (S.devEvents.some(function (e) { return e.deviceId === d.id && e.slot === slot && e.at.slice(0, 10) === TODAY && e.outcome === "taken"; }) ? "again" : "already"); }
      }
      S.devEvents.unshift({ deviceId: d.id, kind: "open", lid: lid, at: TODAY + " " + nowHM(), slot: slot, outcome: outcome }); d.lastSeen = TODAY + " " + nowHM();
      return { ok: true, outcome: outcome };
    },
    item_delete: function (b) { var h = hid(need()); S.items = S.items.filter(function (i) { return !(i.id === b.id && i.hid === h); }); return { ok: true }; },
    dose_toggle: function (b) {
      var h = hid(need()), i = S.items.find(function (x) { return x.id === b.item_id && x.hid === h; }); if (!i) fail(404, "not_found", "That record was not found.");
      var has = S.taken.some(function (t) { return t.item === i.id && t.slot === b.slot && t.day === TODAY; });
      if (b.taken && has) { var ex = S.taken.find(function (t) { return t.item === i.id && t.slot === b.slot && t.day === TODAY; }); if (ex.status === "skipped") { ex.status = "taken"; ex.reason = null; if (i.stock !== null) i.stock = Math.max(0, i.stock - (i.perDose || 1)); } }
      if (b.taken && !has) { S.taken.push({ hid: h, item: i.id, slot: b.slot, day: TODAY, status: "taken", source: "app", at: nowHM() }); if (i.stock !== null) i.stock = Math.max(0, i.stock - (i.perDose || 1)); }
      if (!b.taken && has) { var was = S.taken.find(function (t) { return t.item === i.id && t.slot === b.slot && t.day === TODAY; }); S.taken = S.taken.filter(function (t) { return !(t.item === i.id && t.slot === b.slot && t.day === TODAY); }); if (i.stock !== null && was.status !== "skipped") i.stock += (i.perDose || 1); }
      return { ok: true, stock: i.stock };
    },
    dose_history: function () { return { days: [] }; },
    reminders_save: function (b) { var u = need(); S.reminders[u.id] = { enabled: !!b.enabled, m: b.m, a: b.a, e: b.e, b: b.b }; return { ok: true }; },
    kit_toggle: function (b) { need(); S.kit = S.kit.filter(function (k) { return k !== b.key; }); if (b.checked) S.kit.push(b.key); return { ok: true }; },
    settings_save: function (b) { var u = need(); u.name = b.name; u.phone = b.phone || ""; if (b.language) u.language = b.language; if ("weekly_email" in b) u.weekly = !!b.weekly_email; return { ok: true }; },
    language_save: function (b) { need().language = b.language; return { ok: true }; },
    consent_renew: function () { return { ok: true }; },
    password_change: function (b) { var u = need(); if (b.current !== u.password) fail(403, "bad_password", "Your current password is not correct."); u.password = b.new; return { ok: true }; },
    account_delete: function () { fail(403, "demo", "Account deletion is switched off in this preview so the demo family stays available."); },
    onboarded: function () { need().onboarded = true; return { ok: true }; },
    catalog_search: function (b, q) { var words = norm(q.get("q") || "").split(" ").filter(Boolean); if (!words.length || !DATA) return { results: [] };
      return { results: DATA.catalog.filter(function (c) { return words.every(function (w) { return c.s.indexOf(w) >= 0; }); }).sort(function (a, b2) { return a.n.length - b2.n.length; }).slice(0, 20).map(cat) }; },
    catalog_match: function (b) {
      var ing = ingredientsIn(b.text || ""), txt = " " + norm(b.text) + " ";
      var cands = DATA.catalog.filter(function (c) { return ing.indexOf(c.i) >= 0; }).map(function (c) { var s = 10; (c.n.match(/\d+(\.\d+)?\s*(mg|ml|g|mcg|iu)/gi) || []).forEach(function (x) { s += txt.indexOf(" " + norm(x)) >= 0 ? 6 : -1; }); return { c: c, s: s }; });
      cands.sort(function (a, b2) { return b2.s - a.s; });
      var out = cands.slice(0, 5).map(function (x) { var r = cat(x.c); r.score = x.s; return r; });
      return { matches: out, info: out[0] ? out[0].info : (ing.length ? infoById(ing[0]) : null) };
    },
    catalog_match_many: function (b) {
      return { results: (b.names || []).slice(0, 40).map(function (n) { var r = actions.catalog_match({ text: n }); return { query: n, match: r.matches[0] || null, info: r.info }; }) };
    },
    info_list: function () { return { info: DATA.info.map(pubInfo) }; },
    push_key: function () { return { key: null }; },
    tts: function () { fail(501, "tts_off", "Server voice is not set up."); },
    export: function () { var u = need(), h = hid(u); return { exported_at: new Date().toISOString(), note: "Preview data", account: pubUser(u), members: S.members.filter(function (m) { return m.hid === h; }), medicines: S.items.filter(function (i) { return i.hid === h; }) }; },
    billing_status: function () { var u = need(), h = hid(u); var b = billing(u); b.accounts = S.users.filter(function (x) { return x.id === h || x.household === h; }).map(function (x) { return { id: x.id, name: x.name, email: x.email.replace(/^(..)[^@]*/, "$1••••"), owner: !x.household, you: x.id === u.id }; }); return b; },
    billing_order: function (b) { var u = need(); if (u.household) fail(403, "not_owner", "Only the person who owns the family cabinet can pay for Premium."); var p = b.period === "year" ? "year" : "month";
      return { order_id: "demo_" + Date.now(), amount: p === "year" ? 39900 : 4900, currency: "INR", period: p, demo: true, key: null, name: "AushadhBox", description: "Family Premium", prefill: {} }; },
    billing_verify: function (b) { var u = need(), base = new Date(u.planUntil && u.planUntil > TODAY ? u.planUntil + "T00:00:00" : today); base.setFullYear(base.getFullYear() + 1);
      u.plan = "premium"; u.planUntil = iso(base); S.payments.unshift({ created_at: TODAY, period: "year", amount: 399, status: "paid", order_id: b.order_id, name: u.name }); return { ok: true, billing: billing(u) }; },
    invite_create: function () { var u = need(); if (!premium(u)) fail(402, "premium_only", "Family sharing is part of Family Premium."); var code = Math.random().toString(36).slice(2, 10).toUpperCase().replace(/[01OI]/g, "7").padEnd(8, "K").slice(0, 8);
      S.invites[code] = hid(u); return { code: code, expires_in_days: 7, link: location.href.split("#")[0] + "#join=" + code }; },
    invite_join: function (b) { var u = need(), code = String(b.code || "").toUpperCase(), h = S.invites[code]; if (!h) fail(404, "bad_code", "This invite code is wrong or has expired. Ask for a new one."); if (h === u.id) fail(400, "own_code", "This is your own invite code. Send it to a family member.");
      u.household = h; delete S.invites[code]; return { ok: true, owner: S.users.find(function (x) { return x.id === h; }).name }; },
    household_leave: function () { need().household = null; return { ok: true }; },
    household_remove: function (b) { var u = need(); S.users.forEach(function (x) { if (x.id === b.user_id && x.household === u.id) x.household = null; }); return { ok: true }; },
    automation_get: function () { need(); return S.auto; },
    automation_save: function (b) { need(); S.auto = { refill: !!b.refill_on, refillDays: Number(b.refill_days) || 5, missed: !!b.missed_on, missedAfter: Number(b.missed_after) || 60, course: !!b.course_on, cleanup: !!b.cleanup_on, timing: b.timing_on !== false, watchHours: b.watch_hours === undefined ? 24 : Number(b.watch_hours), calendar: true }; return S.auto; },
    calendar_link: function () { need(); return { url: "https://aushadhbox.in/calendar/preview-only.ics" }; },
    consent_log: function () { return { ok: true }; },
    track: function () { return { ok: true }; },
    contact_send: function (b) { if (b.website) return { ok: true }; var m = triage({ id: ++S.nextId, name: b.name, email: b.email, topic: b.topic || "other", message: b.message, handled: 0, created_at: TODAY + " " + nowHM() }); S.messages.unshift(m); return { ok: true, ticket: m.ticket }; },
    vital_save: function (b) {
      need(); var k = b.kind, v1 = Number(b.v1), v2 = b.v2 === null || b.v2 === undefined || b.v2 === "" ? null : Number(b.v2);
      if (["bp", "sugar", "weight", "temp", "spo2", "pulse"].indexOf(k) < 0) fail(400, "bad_kind", "Choose what you measured.");
      if (k === "temp" && v1 > 45) v1 = Math.round((v1 - 32) * 50 / 9) / 10;
      if (k === "bp" && (!v2 || v2 >= v1)) fail(400, "bad_value", "Enter both blood pressure numbers, for example 130 / 85.");
      var f = vflag(k, v1, v2, b.context), rec = { id: ++S.nextId, memberId: b.member_id || null, kind: k, v1: v1, v2: k === "bp" ? v2 : null, context: k === "sugar" ? b.context : null, flag: f, note: null, at: TODAY + " " + nowHM() };
      S.vitals.push(rec); return { ok: true, id: rec.id, flag: f, v1: rec.v1, v2: rec.v2 };
    },
    vitals: function (b, q) { need(); var mid = Number(q.get("member_id")) || null; return { vitals: S.vitals.filter(function (v) { return !mid || v.memberId === mid; }).slice().sort(function (x, y) { return x.at < y.at ? 1 : -1; }) }; },
    vital_delete: function (b) { need(); S.vitals = S.vitals.filter(function (v) { return v.id !== b.id; }); return { ok: true }; },
    followup_answer: function (b) { need(); var f = S.followups.find(function (x) { return x.id === b.id; }); if (!f) fail(404, "not_found", "That question was not found.");
      S.followups = S.followups.filter(function (x) { return x.id !== b.id; }); S.fuAnswers.unshift({ name: f.name, answer: b.answer, note: b.note || null, d: TODAY, memberId: f.memberId }); return { ok: true, answer: b.answer }; },
    appointment_save: function (b) { need(); if (!b.doctor || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(b.at || "")) fail(400, "bad_time", "Choose the date and time of the visit.");
      S.appointments.push({ id: ++S.nextId, memberId: b.member_id || null, doctor: b.doctor, place: b.place || null, at: b.at.replace("T", " ") + ":00", questions: b.questions || null });
      S.appointments.sort(function (x, y) { return x.at < y.at ? -1 : 1; }); return { ok: true, appointments: S.appointments }; },
    appointment_delete: function (b) { need(); S.appointments = S.appointments.filter(function (a) { return a.id !== b.id; }); return { ok: true, appointments: S.appointments }; },
    pharmacy_save: function (b) { need(); var ph = String(b.phone || "").replace(/\D/g, "").replace(/^91(?=\d{10}$)/, ""); if (ph && !/^[6-9]\d{9}$/.test(ph)) fail(400, "bad_phone", "Enter the pharmacy's 10-digit WhatsApp number.");
      S.pharmacy = { name: b.name || null, phone: ph || null }; return { ok: true, pharmacy: S.pharmacy }; },
    visit_sheet: function (b, q) {
      var h = hid(need()), mid = Number(q.get("member_id")) || null, m = S.members.find(function (x) { return x.id === mid; });
      var meds = S.items.filter(function (i) { return i.hid === h && i.times.length && (!mid || i.member_id === mid || !i.member_id); }).map(function (i) { var inf = pubItem(i).info, perDay = i.times.length * (i.perDose || 1);
        return { name: i.name, ingredient: inf ? inf.ingredient : null, times: i.times, perDose: i.perDose || 1, left: i.stock, daysLeft: i.stock === null ? null : Math.floor(i.stock / perDay), courseEnd: i.courseEnd, notes: i.notes }; });
      var adh = actions.adherence({}, new URLSearchParams("days=30")).people.filter(function (p) { return !mid || p.memberId === mid; });
      var vit = []; ["bp", "sugar", "weight", "temp", "spo2", "pulse"].forEach(function (k) { var rows = S.vitals.filter(function (v) { return v.kind === k && (!mid || v.memberId === mid) && v.at >= dayAdd(-30); }).sort(function (x, y) { return x.at < y.at ? 1 : -1; });
        if (!rows.length) return; var a = rows.map(function (r) { return r.v1; }), c = rows.map(function (r) { return r.v2; }).filter(function (x) { return x !== null; });
        vit.push({ kind: k, count: rows.length, latest: { v1: rows[0].v1, v2: rows[0].v2, at: rows[0].at }, avg1: Math.round(10 * a.reduce(function (x, y) { return x + y; }, 0) / a.length) / 10, avg2: c.length ? Math.round(10 * c.reduce(function (x, y) { return x + y; }, 0) / c.length) / 10 : null,
          min1: Math.min.apply(null, a), max1: Math.max.apply(null, a), outOfRange: rows.filter(function (r) { return r.flag !== "ok"; }).length }); });
      var next = S.appointments.filter(function (a) { return a.at >= TODAY && (!mid || !a.memberId || a.memberId === mid); })[0] || null;
      return { person: m ? { name: m.name, relation: m.relation, allergies: m.allergies, bloodGroup: m.bloodGroup || null, conditions: m.conditions || null } : null, date: TODAY, medicines: meds, adherence: adh,
        followups: S.fuAnswers.filter(function (f) { return !mid || f.memberId === mid; }), skips: [], vitals: vit, next: next, alerts: safety(h).filter(function (a) { return !mid || a.memberId === mid || a.memberId === null; }) };
    },
    safety_check: function () { return { alerts: safety(hid(need())) }; },
    ai_opt_in: function (b) { var u = need(); u.aiOptIn = !!b.on; return { ok: true, aiOptIn: u.aiOptIn, available: false }; },
    assistant_ask: function (b) {
      var h = hid(need()), q = " " + String(b.q || "").toLowerCase() + " ", hit = null;
      S.items.filter(function (i) { return i.hid === h; }).some(function (i) { var w = i.name.toLowerCase().split(/[\s(]+/)[0]; var inf = pubItem(i).info; if (w.length >= 4 && inf && q.indexOf(w) >= 0) { hit = i.name + " contains " + inf.ingredient + ". It is used for: " + inf.use + "." + (inf.storage ? " Storage: " + inf.storage + "." : ""); return true; } return false; });
      if (!hit) (DATA.info || []).some(function (r) { return (r.keywords + "," + r.ingredient.toLowerCase()).split(",").some(function (w) { w = w.trim(); if (w.length >= 4 && q.indexOf(" " + w) >= 0) { hit = r.ingredient + " is used for: " + r.use + "."; return true; } return false; }); });
      return hit ? { answer: hit + " Ask your doctor or pharmacist before changing how you take it.", source: "info" } : { answer: "", source: "none" };
    },
    admin_autopilot: function () { admin();
      return { insights: S.insights, ai: { configured: false, on: true, provider: "", model: "", callsThisMonth: 0, okThisMonth: 0, limit: 3000 }, settings: S.settings, log: S.log, audit: S.audit, lastRun: TODAY + " 06:00", indexnow: dayAdd(-2) + " 06:05",
        health: [{ key: "db", label: "Database", status: "ok", detail: "Connected." }, { key: "cron", label: "Scheduled jobs (cron.php)", status: "ok", detail: "Last run 3 minutes ago." }, { key: "cron_key", label: "Cron password", status: "ok", detail: "Long and random." },
          { key: "https", label: "HTTPS address", status: "ok", detail: "Uses HTTPS." }, { key: "mail", label: "E-mail (SMTP)", status: "ok", detail: "Configured." }, { key: "push", label: "Phone notifications", status: "ok", detail: "61 devices subscribed." },
          { key: "payments", label: "Payments", status: "warn", detail: "1 checkout(s) not completed in the last 2 days. The autopilot asks Razorpay about each one every hour." },
          { key: "support", label: "Support replies", status: "warn", detail: "1 message(s) past their reply target." }, { key: "pillbox", label: "Smart pillboxes", status: "ok", detail: "All online." }],
        growth: { scored: 137, counts: { likely_upgrade: 4, at_risk: 2, not_activated: 6, dormant: 11, champions: 9 }, segments: {
          likely_upgrade: [{ name: "Suresh R", email: "su••••@gmail.com", why: "28 of 30 medicines", score: 85 }, { name: "Fatima", email: "fa••••@yahoo.in", why: "4 of 4 people", score: 75 }, { name: "Arun", email: "ar••••@gmail.com", why: "26 of 30 medicines", score: 70 }, { name: "Kavya", email: "ka••••@outlook.com", why: "4 of 4 people", score: 65 }],
          at_risk: [{ name: "Joseph", email: "jo••••@gmail.com", why: "Premium ends " + dayAdd(4), score: 60 }, { name: "Divya", email: "di••••@gmail.com", why: "not seen for 19 days", score: 45 }],
          not_activated: [{ name: "Rahul", email: "ra••••@gmail.com", why: "joined 3 days ago, no medicines", score: 10 }, { name: "Sneha", email: "sn••••@gmail.com", why: "joined 2 days ago, no medicines", score: 5 }],
          dormant: [{ name: "Mohan", email: "mo••••@gmail.com", why: "not seen for 33 days", score: 40 }], champions: [{ name: "Lakshmi", email: "de••••@aushadhbox.in", why: "score 100", score: 100 }] } } }; },
    admin_ask: function (b) { admin(); return demoAsk(String(b.q || "")); },
    admin_settings: function (b) { admin(); Object.keys(b).forEach(function (k) { if (k in S.settings) S.settings[k] = !!b[k]; }); S.audit.unshift({ action: "autopilot_settings", detail: Object.keys(b).map(function (k) { return k + "=" + (b[k] ? "on" : "off"); }).join(", "), created_at: TODAY + " " + nowHM(), name: "Lakshmi" }); return { ok: true }; },
    admin_run: function () { admin(); S.log.unshift({ kind: "heal", title: "Checked everything: nothing needed fixing", created_at: TODAY + " " + nowHM() }); return { ok: true, fixed: [], anomalies: 0, tips: 0 }; },
    admin_insight_dismiss: function (b) { admin(); S.insights = S.insights.filter(function (i) { return i.id !== b.id; }); return { ok: true }; },
    admin_digest: function () { admin(); return { ai: false, text: "Yesterday (" + dayAdd(-1) + ")\nSign-ups                       23   (7-day average 6.4)\nFamilies ticking doses         61   (7-day average 58.1)\nDoses taken                   742   (7-day average 701.3)\nRevenue (₹)                  ₹847   (7-day average ₹341)\nPremium purchases               3   (7-day average 1.1)\nFailed payments                 0   (7-day average 0.3)\nWebsite visitors              212   (7-day average 160.0)\nSupport messages                9   (7-day average 2.0)\n\nNow: 9 Premium families · 130 users · conversion 7.2% · 3 open messages\n\nWhat the autopilot noticed:\n• [high] Support messages jumped to 9\n• [good] Sign-ups jumped to 23\n\nNeeds attention:\n• Payments: 1 checkout not completed\n\n(Sample digest in the preview)" }; },
    admin_indexnow: function () { admin(); return { ok: true, skipped: "preview: the live site sends its pages to Bing and other search engines once a week" }; },
    admin_message_reply: function (b) { admin(); var m = S.messages.find(function (x) { return x.id === b.id; }); if (!m) fail(404, "not_found", "Message not found."); m.reply = b.reply; m.replied_at = TODAY + " " + nowHM(); m.handled = 1; S.audit.unshift({ action: "support_reply", detail: m.ticket + " (preview: not e-mailed)", created_at: m.replied_at, name: "Lakshmi" }); return { ok: true, emailed: true }; },
    admin_message_retriage: function (b) { admin(); var m = S.messages.find(function (x) { return x.id === b.id; }); if (m) triage(m); return { ok: true }; },
    admin_stats: function () { var u = need(); if (u.role !== "admin") fail(403, "forbidden", "Only administrators can do this.");
      var d = function (n) { var x = new Date(today); x.setDate(x.getDate() - n); return iso(x); };
      return { users: 128 + S.users.length, new_users_7d: 19, items: 940 + S.items.length, catalog: [{ source: "janaushadhi-2025-12", n: DATA.catalog.length }], info: DATA.info.length, push_subscriptions: 61,
        features: { mail: true, push: true, bhashini: false, payments: "demo" }, premium_users: 9, revenue_30d: 2391, revenue_total: 6183, payments_recent: S.payments.slice(0, 10).map(function (p) { return { created_at: p.created_at, period: p.period, amount: p.amount, status: p.status, order_id: p.order_id, name: p.name }; }),
        signups_14d: [3, 1, 4, 2, 0, 5, 3, 2, 6, 4, 3, 7, 5, 8].map(function (n, i) { return { d: d(13 - i), n: n }; }), visitors_30d: 1840, pageviews_30d: 4620,
        visits_14d: [60, 72, 58, 90, 84, 66, 110, 98, 120, 105, 130, 142, 128, 160].map(function (n, i) { return { d: d(13 - i), n: n }; }),
        top_pages: [{ path: "/", n: 1210 }, { path: "/medicines/paracetamol-tablets-ip-500-mg-23", n: 402 }, { path: "/guides/how-to-read-expiry-date-on-medicine", n: 388 }, { path: "/ta/", n: 301 }, { path: "/pricing", n: 244 }],
        top_referrers: [{ src: "google.com", n: 812 }, { src: "whatsapp", n: 420 }, { src: "(direct)", n: 390 }, { src: "instagram", n: 118 }],
        devices: [{ device: "mobile", n: 1460 }, { device: "desktop", n: 330 }, { device: "tablet", n: 50 }], cta_clicks_30d: [{ event: "cta_hero", n: 210 }, { event: "cta_medicine", n: 96 }, { event: "plan_premium", n: 31 }],
        consent: { accepted: 1105, rejected: 735 }, messages_open: S.messages.filter(function (m) { return !m.handled; }).length, note: "Sample numbers for the preview" }; },
    admin_messages: function () { admin(); var now = TODAY + " " + nowHM(); S.messages.forEach(function (m) { m.overdue = !m.handled && m.due_at < now; }); return { messages: S.messages, mail: true }; },
    admin_message_done: function (b) { S.messages.forEach(function (m) { if (m.id === b.id) m.handled = 1; }); return { ok: true }; },
    admin_import: function () { fail(400, "demo", "Importing is switched off in this preview.") ; }
  };

  var realFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    var url = typeof input === "string" ? input : (input && input.url) || "";
    if (url.indexOf("api.php") < 0) return realFetch(input, init);
    var q = new URL(url, location.href).searchParams, action = q.get("action");
    var body = {}; try { body = init && init.body ? JSON.parse(init.body) : {}; } catch (e) { body = {}; }
    return loadData().then(function () {
      var status = 200, data;
      try { if (!actions[action]) fail(404, "unknown_action", "This feature needs the full server."); data = actions[action](body, q); save(); }
      catch (e) { status = e.status || 500; data = { error: e.code || "error", message: e.message }; }
      return new Response(JSON.stringify(data), { status: status, headers: { "Content-Type": "application/json" } });
    });
  };
  if (navigator.serviceWorker) try { navigator.serviceWorker.register = function () { return Promise.reject(new Error("preview")); }; } catch (e) { /* */ }
})();
