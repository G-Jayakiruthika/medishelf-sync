/* AushadhBox preview: an in-browser stand-in for api.php, so the app runs on a static preview link.
   Data lives only in this browser (localStorage). Loaded before every other script on preview pages. */
(function () {
  "use strict";
  var KEY = "ab-demo-v2", DATA = null, dataPromise = null;
  var today = new Date(), iso = function (d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); };
  var TODAY = iso(today);
  var ym = function (add) { var d = new Date(today.getFullYear(), today.getMonth() + add, 1); return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0"); };
  var dayAdd = function (n) { var d = new Date(today); d.setDate(d.getDate() + n); return iso(d); };

  function seed() {
    var users = [
      { id: 1, name: "Lakshmi", email: "demo@aushadhbox.in", password: "demo1234", phone: "", language: "en", role: "admin", plan: "premium", planUntil: dayAdd(240), household: null, weekly: true, onboarded: true },
      { id: 2, name: "Ravi", email: "ravi@aushadhbox.in", password: "demo1234", phone: "", language: "ta", role: "user", plan: "free", planUntil: null, household: 1, weekly: true, onboarded: true }
    ];
    var members = [{ id: 1, hid: 1, name: "Lakshmi", relation: "Self", allergies: "", self: true }, { id: 2, hid: 1, name: "Amma", relation: "Mother", allergies: "Penicillin", self: false },
      { id: 3, hid: 1, name: "Appa", relation: "Father", allergies: "", self: false }, { id: 4, hid: 1, name: "Anu", relation: "Daughter", allergies: "", self: false }, { id: 5, hid: 1, name: "Ravi", relation: "Husband", allergies: "", self: false }];
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
      it(9, "ORS sachet", 4, ym(-2), [], "ORS1002", null, null, null)
    ];
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
      auto: { refill: true, refillDays: 5, missed: true, missedAfter: 60, course: true, cleanup: true, timing: true, calendar: true },
      reminders: { 1: { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" }, 2: { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" } },
      payments: [{ created_at: TODAY + " 10:12", period: "year", amount: 399, status: "paid", order_id: "demo_order_1", name: "Lakshmi" }],
      messages: [{ id: 1, name: "Priya S", email: "priya@example.in", topic: "help", message: "Can my father use this on his phone in Tamil?", handled: 0, created_at: TODAY + " 09:40" }] };
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
  function pubUser(u) { return { id: u.id, name: u.name, email: u.email, phone: u.phone, language: u.language, admin: u.role === "admin", consentCurrent: true, plan: premium(u) ? "premium" : "free", planUntil: premium(u) ? owner(u).planUntil : null, owner: !u.household, weeklyEmail: u.weekly, onboarded: u.onboarded }; }
  function pubItem(i) {
    var info = i.info_id ? infoById(i.info_id) : (i.ing ? infoBySlug(i.ing) : null);
    return { id: i.id, member_id: i.member_id, name: i.name, catalog_id: i.catalog_id, info: info, batch: i.batch, mfg: i.mfg, exp: i.exp, quantity: i.quantity, times: i.times, notes: i.notes, stock: i.stock, perDose: i.perDose || 1, courseEnd: i.courseEnd };
  }
  function nowHM() { var n = new Date(); return String(n.getHours()).padStart(2, "0") + ":" + String(n.getMinutes()).padStart(2, "0"); }
  function dueNow(h, slot) { return S.items.filter(function (i) { return i.hid === h && (i.times || []).indexOf(slot) >= 0 && (!i.courseEnd || i.courseEnd >= TODAY); }); }
  function pubDevice(d) { return { id: d.id, name: d.name, memberId: d.memberId, compartments: d.compartments, paired: d.paired, pairCode: d.paired ? null : d.pairCode, battery: d.battery, firmware: d.firmware, lastSeen: d.lastSeen, online: d.paired && !!d.lastSeen && d.lastSeen.slice(0, 10) === TODAY }; }
  function myDevice(id) { var h = hid(need()), d = S.devices.find(function (x) { return x.id === id && x.hid === h; }); if (!d) fail(404, "not_found", "That record was not found."); return d; }
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
      return { user: pubUser(u), today: TODAY, members: S.members.filter(function (m) { return m.hid === h; }).map(function (m) { return { id: m.id, name: m.name, relation: m.relation, allergies: m.allergies, self: m.self }; }),
        items: S.items.filter(function (i) { return i.hid === h; }).map(pubItem), taken: S.taken.filter(function (t) { return t.day === TODAY && t.hid === h && t.status !== "skipped"; }).map(function (t) { return t.item + ":" + t.slot; }),
        doseLog: S.taken.filter(function (t) { return t.day === TODAY && t.hid === h; }).reduce(function (o, t) { o[t.item + ":" + t.slot] = { status: t.status || "taken", reason: t.reason || null, source: t.source || "app", at: t.at || "" }; return o; }, {}),
        reminders: S.reminders[u.id] || { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" }, kit: S.kit, billing: billing(u) };
    },
    member_save: function (b) {
      var u = need(), h = hid(u);
      if (b.id) { var m = S.members.find(function (x) { return x.id === b.id && x.hid === h; }); if (!m) fail(404, "not_found", "That record was not found."); m.name = b.name; m.relation = b.relation; m.allergies = b.allergies; return { ok: true, id: m.id }; }
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
    automation_save: function (b) { need(); S.auto = { refill: !!b.refill_on, refillDays: Number(b.refill_days) || 5, missed: !!b.missed_on, missedAfter: Number(b.missed_after) || 60, course: !!b.course_on, cleanup: !!b.cleanup_on, timing: b.timing_on !== false, calendar: true }; return S.auto; },
    calendar_link: function () { need(); return { url: "https://aushadhbox.in/calendar/preview-only.ics" }; },
    consent_log: function () { return { ok: true }; },
    track: function () { return { ok: true }; },
    contact_send: function (b) { if (b.website) return { ok: true }; S.messages.unshift({ id: ++S.nextId, name: b.name, email: b.email, topic: b.topic || "other", message: b.message, handled: 0, created_at: TODAY }); return { ok: true }; },
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
    admin_messages: function () { return { messages: S.messages }; },
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
