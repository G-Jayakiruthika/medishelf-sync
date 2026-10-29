// Unit tests for the prescription / bill / sentence reader.   node tests/rx_parse_test.js
const Rx = require("../public_html/rx-parse.js");
let pass = 0, fail = 0;
const eq = (got, want, msg) => { const g = JSON.stringify(got), w = JSON.stringify(want); if (g === w) { pass++; } else { fail++; console.log("  ✗ " + msg + "\n      got  " + g + "\n      want " + w); } };
const T = "2026-10-28";

// Prescription in Indian shorthand
let r = Rx.parse(`Dr. S. Kumar MBBS
Patient: Lakshmi  Age 62
Rx
1. Tab. Dolo 650  1-0-1  x 5 days  after food
2. Tab Pantoprazole 40mg OD before food x 2 weeks
3. Cap Amoxicillin 500 mg TDS for 5 days
4. Tab Atorvastatin 10 HS continue
5. Syp Ascoril 2 tsp SOS
6. Tab Metformin 500 mg
   1-0-1 x 30 days
Review after 1 week`, T);
eq(r.kind, "prescription", "detects a prescription");
eq(r.items.map(i => i.name), ["Dolo 650", "Pantoprazole 40mg", "Amoxicillin 500 mg", "Atorvastatin 10", "Ascoril", "Metformin 500 mg"], "medicine names, numbering and instructions removed");
eq(r.items[0].times, ["m", "e"], "1-0-1 → morning and night");
eq(r.items[0].days, 5, "x 5 days");
eq(r.items[0].stock, 10, "tablets needed = 5 days × 2");
eq(r.items[0].courseEnd, "2026-11-01", "course end date");
eq(r.items[0].notes, "after food", "after food note");
eq(r.items[1].times, ["m"], "OD → morning");
eq(r.items[1].days, 14, "x 2 weeks → 14 days");
eq(r.items[1].notes, "before food", "before food note");
eq(r.items[2].times, ["m", "a", "e"], "TDS → three times");
eq(r.items[3].times, ["b"], "HS → bedtime");
eq(r.items[3].days, null, "continue → no end date");
eq([r.items[4].sos, r.items[4].times], [true, []], "SOS → only when needed, no times");
eq([r.items[5].times, r.items[5].days], [["m", "e"], 30], "instruction on the next line is joined");

// Quarter/half doses and four-slot patterns
eq(Rx.schedule("½-0-½").times, ["m", "e"], "half tablets");
eq(Rx.schedule("½-0-½").half, true, "half flagged");
eq(Rx.schedule("1-1-1-1").times, ["m", "a", "e", "b"], "four times pattern");
eq(Rx.schedule("0-0-1").times, ["e"], "0-0-1 → night");
eq(Rx.schedule("BD").times, ["m", "e"], "BD");
eq(Rx.schedule("twice a day").times, ["m", "e"], "plain English twice");
eq(Rx.schedule("morning and night").times, ["m", "e"], "morning and night words");
eq(Rx.schedule("subah aur raat khane ke baad").times, ["m", "e"], "Hinglish words");
eq(Rx.schedule("सुबह और रात").times, ["m", "e"], "Hindi script");
eq(Rx.schedule("காலை இரவு").times, ["m", "e"], "Tamil");
eq(Rx.schedule("once a week").weekly, true, "weekly");
eq(Rx.schedule("2 tabs at a time BD").perDose, 2, "2 tablets per dose");

// Pharmacy bill
r = Rx.parse(`MEDPLUS PHARMACY  GSTIN 33ABCDE1234F1Z5
Invoice No: 4471   Date: 20/10/2026
Sl Product            Batch     Exp    Qty  MRP
1  DOLO 650 TAB 15'S  DOL4471   05/27  15   33.60
2  TELMA 40 TAB       TLM2210   08/2027 30  245.00
3  CETIRIZINE 10MG    CTZ1180   SEP-26  10  18.50
Total 297.10`, T);
eq(r.kind, "bill", "detects a pharmacy bill");
eq(r.items.map(i => [i.name, i.batch, i.exp, i.stock]), [["DOLO 650", "DOL4471", "2027-05", 15], ["TELMA 40", "TLM2210", "2027-08", 30], ["CETIRIZINE 10MG", "CTZ1180", "2026-09", 10]], "bill rows: name, batch, expiry, quantity");

eq([r.total, r.date], [297.1, "2026-10-20"], "bill total and date (for medical expenses)");
eq(Rx.parse("APOLLO PHARMACY Bill\nDate: 05-11-26\n1 PAN 40 TAB PN123 06/27 15 180.00\n2 TELMA 40 TAB TL22 09/27 30 240.00\nSub Total 420.00\nDiscount 21.00\nNet Amount Rs. 399.00", T).total, 399, "net amount after discount, not the sub-total");

// Sentences typed or spoken
const fam = [{ id: 2, name: "Amma" }, { id: 3, name: "Appa" }];
let s = Rx.sentence("Add Dolo 650 twice a day for 3 days for Amma", fam, T);
eq([s.name, s.times, s.days, s.member && s.member.name, s.stock], ["Dolo 650", ["m", "e"], 3, "Amma", 6], "sentence with member");
s = Rx.sentence("Appa's metformin 500 morning and night after food", fam, T);
eq([s.name, s.times, s.member && s.member.name, s.notes], ["metformin 500", ["m", "e"], "Appa", "after food"], "possessive member and food");

s = Rx.sentence("Appa's Telma 40 every morning", fam, T);
eq([s.name, s.times, s.member && s.member.name], ["Telma 40", ["m"], "Appa"], "every morning");
s = Rx.sentence("I take Thyronorm 50 daily before breakfast", fam, T);
eq(s.name, "Thyronorm 50", "daily before breakfast");

// Pack sizes
eq([Rx.packCount("10's"), Rx.packCount("15 Tablets"), Rx.packCount("1x10"), Rx.packCount("100 ml")], [10, 15, 10, null], "pack counts");

console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
