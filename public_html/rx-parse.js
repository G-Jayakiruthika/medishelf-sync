/* AushadhBox prescription and pharmacy-bill reader.
   Turns text (from OCR of a photo, a pasted e-prescription, or a typed/spoken sentence) into medicines with
   dose times, course length, tablet count, batch and expiry, so people don't have to fill forms.
   Understands Indian prescription shorthand (1-0-1, OD, BD, TDS, QID, HS, SOS, x 5 days, AF/BF),
   plain English, and time-of-day words in Hindi, Tamil, Telugu, Kannada, Malayalam, Bengali, Marathi, Gujarati, Punjabi.
   Works in the browser (window.RxParse) and in Node (module.exports) for tests. */
(function (root) {
  "use strict";

  var FORMS = [
    ["tablet", /\b(tab(let)?s?|tb)\b\.?/i], ["capsule", /\b(cap(sule)?s?)\b\.?/i], ["syrup", /\b(syp|syr(up)?|susp(ension)?|liquid)\b\.?/i],
    ["drops", /\b(drops?|gtt)\b\.?/i], ["injection", /\b(inj(ection)?)\b\.?/i], ["cream", /\b(oint(ment)?|cream|gel|lotion)\b\.?/i],
    ["inhaler", /\b(inhaler|rotacaps?|respules?|puffs?)\b/i], ["sachet", /\b(sachet|powder)\b/i]
  ];
  // Words for times of day: English and the 9 Indian languages the app supports.
  var WORDS = {
    m: /\b(morning|breakfast|subah|subha|savere|sakal|bhor)\b|सुबह|सकाळ|সকাল|સવાર|ਸਵੇਰ|காலை|ఉదయం|ಬೆಳಿಗ್ಗೆ|ಬೆಳಗ್ಗೆ|രാവിലെ/i,
    a: /\b(afternoon|noon|lunch|dopahar|dupahar|dupur)\b|दोपहर|दुपार|দুপুর|બપોર|ਦੁਪਹਿਰ|மதியம்|మధ్యాహ్నం|ಮಧ್ಯಾಹ್ನ|ഉച്ച/i,
    e: /\b(evening|night|dinner|shaam|sham|raat|rat|sandhya)\b|शाम|रात|संध्याकाळ|সন্ধ্যা|রাত|સાંજ|રાત|ਸ਼ਾਮ|ਰਾਤ|மாலை|இரவு|సాయంత్రం|రాత్రి|ಸಂಜೆ|ರಾತ್ರಿ|വൈകുന്നേരം|രാത്രി/i,
    b: /\b(bed\s*time|before\s+sleep(ing)?|at\s+sleep)\b/i
  };
  var FREQ = [
    [/\b(q\.?i\.?d|q\.?d\.?s|four\s+times(\s+a\s+day|\s+daily)?|4\s*times)\b/i, ["m", "a", "e", "b"]],
    [/\b(t\.?d\.?s|t\.?i\.?d|thrice(\s+a\s+day|\s+daily)?|three\s+times(\s+a\s+day|\s+daily)?|3\s*times)\b/i, ["m", "a", "e"]],
    [/\b(b\.?d|b\.?i\.?d|twice(\s+a\s+day|\s+daily)?|two\s+times(\s+a\s+day)?|2\s*times(\s+a\s+day)?)\b/i, ["m", "e"]],
    [/\b(h\.?s|at\s+night\s+only)\b/i, ["b"]],
    [/\b(o\.?d|once(\s+a\s+day|\s+daily)?|daily|1\s*time(\s+a\s+day)?|every\s+day)\b/i, ["m"]]
  ];
  var SOS = /\b(s\.?o\.?s|p\.?r\.?n|as\s+(and\s+when\s+)?(needed|required)|if\s+(needed|required)|when\s+(needed|required)|only\s+if)\b/i;
  var WEEKLY = /\b(once\s+(a|per|every)\s+week|weekly|every\s+week)\b/i;
  var AFTER = /\b(a\.?f|after\s+(food|meals?|eating|breakfast|lunch|dinner)|p\.?c)\b|खाने\s*के\s*बाद|சாப்பிட்ட\s*பின்/i;
  var BEFORE = /\b(b\.?f|before\s+(food|meals?|eating|breakfast)|empty\s+stomach|a\.?c)\b|खाने\s*से\s*पहले|சாப்பிடும்\s*முன்/i;
  var CHRONIC = /\b(continue|cont\.?|long\s*term|life\s*long|regular(ly)?|ongoing|till\s+(further\s+)?advised)\b/i;
  var DOSE_PAT = /(^|[\s(])((?:\d|½|¼|1\/2)\s*[-–]\s*(?:\d|½|¼|1\/2)\s*[-–]\s*(?:\d|½|¼|1\/2)(?:\s*[-–]\s*(?:\d|½|¼|1\/2))?)(?=$|[\s),.;x×])/;
  var DURATION = /(?:x|×|for|\*)?\s*(\d{1,3})\s*(days?|d|dys?|weeks?|wks?|w|months?|mths?|mon)\b\.?/i;
  var EXP = /\b(?:exp(?:iry)?\.?\s*(?:date|dt)?\s*[:.]?\s*)?(0?[1-9]|1[0-2])\s*[/\-.]\s*(20\d{2}|\d{2})\b/i;
  var EXP_WORD = /\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*[\s\-/.']*(20\d{2}|\d{2})\b/i;
  var MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, sept: 9, oct: 10, nov: 11, dec: 12 };
  var NOISE = /^(patient|name|age|sex|gender|date|dr\.?|doctor|reg(\.|istration)?|address|ph(one)?|mob(ile)?|diagnosis|dx|c\/o|complaints?|bp|pulse|temp|weight|wt|spo2|advice|follow\s*up|review|signature|sign|invoice|bill|gstin?|hsn|total|amount|net|grand|discount|cgst|sgst|igst|round|paid|cash|upi|thank|dl\.?\s*no|d\.?l\.?\s*no|pharmacist|counter|time|page|clinic|hospital|consultation|investigations?|lab|tests?)\b/i;

  function fracToNum(s) { s = String(s).trim(); if (s === "½" || s === "1/2") return 0.5; if (s === "¼") return 0.25; return parseFloat(s) || 0; }
  function ym(m, y) { y = Number(y); if (y < 100) y += 2000; m = Number(m); if (m < 1 || m > 12 || y < 2000 || y > 2100) return null; return y + "-" + (m < 10 ? "0" : "") + m; }

  function findExpiry(line) {
    var m = line.match(EXP_WORD); if (m) return { exp: ym(MONTHS[m[1].toLowerCase().slice(0, 4)] || MONTHS[m[1].toLowerCase().slice(0, 3)], m[2]), hit: m[0] };
    m = line.match(EXP); if (m) return { exp: ym(m[1], m[2]), hit: m[0] };
    return null;
  }

  /* Times, dose size, course length and notes from one line of text. */
  function schedule(text) {
    var out = { times: [], perDose: 1, half: false, days: null, sos: false, weekly: false, food: "", chronic: false, matched: [] };
    var d = text.match(DOSE_PAT);
    if (d) {
      var parts = d[2].split(/\s*[-–]\s*/).map(fracToNum), slots = parts.length === 4 ? ["m", "a", "e", "b"] : ["m", "a", "e"];
      parts.forEach(function (v, i) { if (v > 0) out.times.push(slots[i]); });
      var max = Math.max.apply(null, parts.concat([0]));
      out.perDose = max >= 1 ? Math.round(max) : 1; out.half = max > 0 && max < 1;
      out.matched.push(d[2]);
    } else {
      for (var i = 0; i < FREQ.length; i++) { var f = text.match(FREQ[i][0]); if (f) { out.times = FREQ[i][1].slice(); out.matched.push(f[0]); break; } }
      var words = [];
      ["m", "a", "e", "b"].forEach(function (k) { var w = text.match(WORDS[k]); if (w) { words.push(k); out.matched.push(w[0]); } });
      if (words.length && (!out.times.length || out.times.length === words.length || /\b(o\.?d|once|daily)\b/i.test(text))) out.times = words;
    }
    var n = text.match(/\b(\d|½|1\/2)\s*(tab(let)?s?|caps?(ule)?s?|puffs?|drops?|tsp|ml)\s*(each\s+time|per\s+dose|at\s+a\s+time)?\b/i);
    if (n && !d) { var v = fracToNum(n[1]); out.perDose = v >= 1 ? Math.round(v) : 1; out.half = v > 0 && v < 1; }
    if (SOS.test(text)) { out.sos = true; out.times = []; }
    if (WEEKLY.test(text)) { out.weekly = true; out.times = []; }
    var du = text.match(DURATION);
    if (du) {
      var k = du[2].toLowerCase(), num = Number(du[1]);
      out.days = /^w/.test(k) ? num * 7 : /^m/.test(k) ? num * 30 : num;
      out.matched.push(du[0]);
    }
    if (CHRONIC.test(text)) { out.chronic = true; out.days = null; }
    if (AFTER.test(text)) out.food = "after food"; else if (BEFORE.test(text)) out.food = "before food";
    return out;
  }

  function formOf(text) { for (var i = 0; i < FORMS.length; i++) if (FORMS[i][1].test(text)) return FORMS[i][0]; return null; }

  /* The medicine name: the line minus numbering, dose patterns, durations, food words, quantities and bill columns. */
  function cleanName(text, sched, extra) {
    var s = " " + text + " ";
    (sched ? sched.matched : []).concat(extra || []).forEach(function (m) { if (m) s = s.split(m).join(" "); });
    s = s.replace(/^\s*(\d{1,2}[.)]|[-•*]|rx\.?)\s*/i, " ")
      .replace(/\b(s\.?o\.?s|p\.?r\.?n|as\s+(and\s+when\s+)?(needed|required)|if\s+(needed|required)|when\s+required)\b/ig, " ")
      .replace(/\b(once\s+(a|per|every)\s+week|weekly)\b/ig, " ")
      .replace(/\b(after|before)\s+(food|meals?|eating|breakfast|lunch|dinner)|empty\s+stomach|\ba\.?f\.?\b|\bb\.?f\.?\b|\bp\.?c\.?\b/ig, " ")
      .replace(/\b(continue|cont\.?|long\s*term|life\s*long|regular(ly)?|ongoing|till\s+(further\s+)?advised)\b/ig, " ")
      .replace(/\b(daily|every\s+day|a\s+day|per\s+day|each\s+time|at\s+a\s+time|then|and|for)\b/ig, " ")
      .replace(/\b(qty|quantity|no\.?s?)\s*[:.]?\s*\d+\b/ig, " ")
      .replace(/\b\d+\s*(?:tab(?:let)?s?|caps?(?:ule)?s?|nos?)\b(?!\s*\d)/ig, function (m) { var a = arguments, off = a[a.length - 2], all = a[a.length - 1]; return /^\s*$/.test(all.slice(0, off)) ? m : " "; })
      .replace(/\b\d+(\.\d+)?\s*(tsp|tbsp|teaspoons?|puffs?|drops?|sachets?)\b/ig, " ")
      .replace(/\b\d{8}\b/g, " ")                  // HSN codes on bills
      .replace(/\b\d+\.\d{2}\b/g, " ")            // prices
      .replace(/[₹|:;,]+/g, " ").replace(/\s{2,}/g, " ").trim();
    var form = formOf(s);
    s = s.replace(/^\s*(tab(let)?s?|caps?(ule)?s?|syp|syr(up)?|susp(ension)?|inj(ection)?|drops?|oint(ment)?|cream|gel)\b\.?\s*/i, "").trim();
    s = s.replace(/\s+(x|×)\s*$/i, "").trim();
    if (s.length > 80) s = s.slice(0, 80);
    return { name: s, form: form };
  }

  function looksLikeMedicine(line, sched) {
    if (line.length < 3 || NOISE.test(line.trim())) return false;
    if (formOf(line)) return true;
    if (sched.matched.length || sched.sos || sched.weekly) return /[a-z]{3,}/i.test(line);
    return /\b\d+(\.\d+)?\s*(mg|mcg|g|ml|iu|%)\b/i.test(line);
  }

  /* One line of a pharmacy bill: name, batch, expiry, quantity. */
  function billLine(line) {
    var ex = findExpiry(line); if (!ex || !ex.exp) return null;
    if (NOISE.test(line.trim())) return null;
    var batch = null, qty = null, rest = line.replace(ex.hit, " | ");
    var tokens = rest.split(/\s+/);
    for (var i = 0; i < tokens.length; i++) {
      var t = tokens[i].replace(/[^A-Za-z0-9\-]/g, "");
      if (t.length >= 4 && t.length <= 14 && /[A-Za-z]/.test(t) && /\d/.test(t) && !/^\d+(mg|ml|mcg|g|iu)$/i.test(t) && !/^\d+'?s$/i.test(t)) { batch = t.toUpperCase(); break; }
    }
    var after = rest.split("|").slice(1).join(" ").replace(batch || "##", " ");
    var q = after.match(/(?:^|\s)(\d{1,3})(?=\s|$)(?!\.\d)/);
    if (q) qty = Number(q[1]);
    var before = rest.split("|")[0];
    if (batch) before = before.replace(new RegExp("\\b" + batch.replace(/-/g, "\\-") + "\\b", "i"), " ");
    var pack = before.match(/\b(\d{1,3})\s*'?\s*s\b/i);
    var form = formOf(before);
    before = before.replace(/^\s*\d{1,3}\s+/, " ").replace(/\b\d{1,3}\s*'?\s*s\b/i, " ")
      .replace(/\b(tab(let)?s?|caps?(ule)?s?|syp|syr(up)?|susp(ension)?|inj(ection)?|drops?|oint(ment)?|cream|gel)\b\.?/ig, " ");
    var nm = cleanName(before, null); nm.form = nm.form || form;
    if (!nm.name || nm.name.length < 3 || !/[a-z]{3,}/i.test(nm.name)) return null;
    return { name: nm.name, form: nm.form, batch: batch, exp: ex.exp, stock: qty || (pack ? Number(pack[1]) : null) };
  }

  /* Whole documents. kind: "bill" (rows with expiry), "prescription" (rows with dose instructions) or "label" (a single pack). */
  function parse(text, today) {
    var lines = String(text || "").replace(/\r/g, "").split("\n").map(function (l) { return l.replace(/\s+/g, " ").trim(); }).filter(Boolean);
    // Join continuation lines like "Tab Metformin 500" + "1-0-1 x 30 days".
    var joined = [];
    lines.forEach(function (l) {
      var prev = joined[joined.length - 1], s = schedule(l);
      var onlyInstr = s.matched.length && !/[a-z]{4,}/i.test(cleanName(l, s).name);
      if (prev && (onlyInstr || /^(after|before|x\s*\d|for\s+\d)/i.test(l))) joined[joined.length - 1] = prev + " " + l; else joined.push(l);
    });
    var billRows = joined.map(billLine).filter(Boolean);
    var items = [], kind;
    if (billRows.length >= 2 || (billRows.length === 1 && /invoice|bill|gst|qty|batch/i.test(text))) {
      kind = "bill";
      items = billRows.map(function (r) { return { name: r.name, form: r.form, batch: r.batch, exp: r.exp, stock: r.stock, times: [], perDose: 1, days: null, food: "", sos: false, raw: "" }; });
    } else {
      joined.forEach(function (l) {
        var s = schedule(l);
        if (!looksLikeMedicine(l, s)) return;
        var nm = cleanName(l, s);
        if (!nm.name || !/[a-z]{3,}/i.test(nm.name)) return;
        var stock = null;
        if (s.days && s.times.length) stock = Math.ceil(s.days * s.times.length * s.perDose);
        var ex = findExpiry(l);
        items.push({ name: nm.name, form: nm.form, times: s.times, perDose: s.perDose, half: s.half, days: s.days, sos: s.sos, weekly: s.weekly,
          food: s.food, chronic: s.chronic, stock: stock, exp: ex ? ex.exp : null, batch: null, raw: l,
          courseEnd: s.days ? addDays(today, s.days - 1) : null });
      });
      kind = items.some(function (i) { return i.times.length || i.sos || i.days; }) || items.length >= 2 ? "prescription" : "label";
    }
    items.forEach(function (i) {
      var n = [];
      if (i.half) n.push("½ tablet each time"); if (i.food) n.push(i.food); if (i.sos) n.push("only when needed"); if (i.weekly) n.push("once a week");
      i.notes = n.join(", ");
    });
    return { kind: kind, items: items };
  }

  function addDays(today, n) {
    var d = today ? new Date(today + "T00:00:00") : new Date(); d.setDate(d.getDate() + n);
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }

  /* A spoken or typed sentence: "Dolo 650 twice a day for 3 days for Amma". Returns one item plus the family member, if named. */
  function sentence(text, members, today) {
    var t = String(text || "").trim().replace(/^(please\s+)?(add|remind\s+me\s+(to\s+take|about)|i\s+(take|have)|new\s+medicine)\s*/i, "");
    var member = null;
    (members || []).slice().sort(function (a, b) { return b.name.length - a.name.length; }).some(function (m) {
      var re = new RegExp("\\b(for|to)?\\s*" + m.name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "('s)?\\b", "i");
      if (re.test(t)) { member = m; t = t.replace(re, " "); return true; }
      return false;
    });
    var s = schedule(t), nm = cleanName(t, s);
    if (!nm.name) return null;
    nm.name = nm.name.replace(/(\s+\b(every|each|daily|regularly|always|in the|at|on|and|also|before|after|with|of))+\s*$/i, "").trim();
    return { name: nm.name, form: nm.form, times: s.times, perDose: s.perDose, days: s.days, sos: s.sos, food: s.food, member: member,
      stock: s.days && s.times.length ? Math.ceil(s.days * s.times.length * s.perDose) : null, courseEnd: s.days ? addDays(today, s.days - 1) : null,
      notes: [s.half ? "½ tablet each time" : "", s.food, s.sos ? "only when needed" : ""].filter(Boolean).join(", ") };
  }

  /* Tablets in a pack from the medicine list's pack size: "10's", "15 Tablets", "1x10". */
  function packCount(unit) {
    if (!unit) return null;
    var m = String(unit).match(/(\d+)\s*x\s*(\d+)/i); if (m) return Number(m[1]) * Number(m[2]);
    m = String(unit).match(/^\s*(\d{1,3})\s*('?s|tabs?|tablets?|caps?|capsules?|nos?)\b/i); if (m) return Number(m[1]);
    return null;
  }

  var api = { parse: parse, schedule: schedule, sentence: sentence, packCount: packCount, billLine: billLine };
  if (typeof module !== "undefined" && module.exports) module.exports = api; else root.RxParse = api;
})(typeof window !== "undefined" ? window : this);
