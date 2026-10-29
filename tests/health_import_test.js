/* Unit tests for health-import.js: Apple Health xml and zip, Samsung/Google Fit CSV, Fitbit JSON. Run: node tests/health_import_test.js */
const H = require("../public_html/health-import.js");
const { execFileSync } = require("child_process");
const fs = require("fs"), os = require("os"), path = require("path");
let pass = 0, fail = 0;
const ok = (c, msg) => { if (c) pass++; else { fail++; console.log("FAIL", msg); } };
const d = new Date(Date.now() - 3 * 864e5), day = d.toISOString().slice(0, 10);
const xml = `<?xml version="1.0"?><HealthData locale="en_IN">
<Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch" unit="count/min" startDate="${day} 08:01:00 +0530" endDate="x" value="72"/>
<Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch" unit="count/min" startDate="${day} 08:20:00 +0530" endDate="x" value="80"/>
<Record type="HKQuantityTypeIdentifierHeartRate" sourceName="Watch" unit="count/min" startDate="${day} 09:05:00 +0530" endDate="x" value="76"/>
<Record type="HKQuantityTypeIdentifierOxygenSaturation" unit="%" startDate="${day} 08:00:00 +0530" value="0.97"/>
<Record type="HKQuantityTypeIdentifierBodyMass" unit="lb" startDate="${day} 07:00:00 +0530" value="150"/>
<Record type="HKQuantityTypeIdentifierBloodGlucose" unit="mmol&lt;180.1558800000541&gt;/L" startDate="${day} 07:30:00 +0530" value="6"/>
<Record type="HKQuantityTypeIdentifierBloodPressureSystolic" unit="mmHg" startDate="${day} 07:10:00 +0530" value="128"/>
<Record type="HKQuantityTypeIdentifierBloodPressureDiastolic" unit="mmHg" startDate="${day} 07:10:00 +0530" value="82"/>
<Record type="HKQuantityTypeIdentifierStepCount" unit="count" startDate="${day} 07:10:00 +0530" value="900"/>
<Record type="HKQuantityTypeIdentifierHeartRate" unit="count/min" startDate="2015-01-01 08:00:00 +0530" value="70"/>
</HealthData>`;
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "hi-"));
fs.writeFileSync(path.join(tmp, "export.xml"), xml);
execFileSync("python3", ["-c", "import zipfile,sys;z=zipfile.ZipFile(sys.argv[1],'w',zipfile.ZIP_DEFLATED);z.write(sys.argv[2],'apple_health_export/export.xml');z.writeStr=None;z.close()", path.join(tmp, "export.zip"), path.join(tmp, "export.xml")]);
const file = (name, data) => new File([data], name);
function check(rows, label) {
  const by = (k) => rows.filter(r => r.kind === k);
  ok(by("pulse").length === 2, label + " pulse one per hour (" + by("pulse").length + ")");
  ok(by("spo2")[0] && by("spo2")[0].v1 === 97, label + " spo2 as percent");
  ok(by("weight")[0] && Math.abs(by("weight")[0].v1 - 68) < 0.2, label + " pounds to kg");
  ok(by("sugar")[0] && Math.abs(by("sugar")[0].v1 - 108.1) < 0.3, label + " mmol to mg/dL");
  ok(by("bp")[0] && by("bp")[0].v1 === 128 && by("bp")[0].v2 === 82, label + " blood pressure paired");
  ok(!rows.some(r => r.at < "2020"), label + " old readings dropped");
  ok(rows.every(r => /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(r.at)), label + " time format");
}
(async () => {
  check(await H.readFile(file("export.xml", fs.readFileSync(path.join(tmp, "export.xml")))), "xml");
  check(await H.readFile(file("export.zip", fs.readFileSync(path.join(tmp, "export.zip")))), "zip");
  // Samsung Health heart rate CSV: first line is metadata, column names are prefixed
  const sam = "com.samsung.shealth.tracker.heart_rate,6315005,3\ncom.samsung.health.heart_rate.start_time,com.samsung.health.heart_rate.heart_rate,com.samsung.health.heart_rate.max,com.samsung.health.heart_rate.min\n" +
    `${day} 10:00:00.000,74,90,60\n${day} 10:30:00.000,78,90,60\n${day} 11:00:00.000,70,90,60\n`;
  let r = await H.readFile(file("com.samsung.shealth.tracker.heart_rate.csv", sam));
  ok(r.length === 2 && r[0].kind === "pulse" && r[0].v1 === 74, "samsung heart rate " + JSON.stringify(r));
  const bp = "com.samsung.shealth.blood_pressure,1,1\nstart_time,systolic,diastolic,pulse\n" + `${day} 07:00:00.000,135,88,70\n`;
  r = await H.readFile(file("bp.csv", bp));
  ok(r.some(x => x.kind === "bp" && x.v1 === 135 && x.v2 === 88) && r.some(x => x.kind === "pulse"), "samsung blood pressure " + JSON.stringify(r));
  // Google Fit daily summary
  const [y, m, dd] = day.split("-");
  r = await H.readFile(file("Daily activity metrics.csv", "Date,Average heart rate (bpm),Max heart rate (bpm),Average weight (kg),Step count\n" + `${day},71.5,120,64.2,5000\n`));
  ok(r.some(x => x.kind === "pulse" && x.v1 === 71.5) && r.some(x => x.kind === "weight" && x.v1 === 64.2), "google fit " + JSON.stringify(r));
  // Fitbit JSON (month/day/year)
  r = await H.readFile(file("heart_rate-x.json", JSON.stringify([{ dateTime: `${m}/${dd}/${y.slice(2)} 08:00:00`, value: { bpm: 66, confidence: 2 } }, { dateTime: `${m}/${dd}/${y.slice(2)} 08:00:10`, value: { bpm: 67, confidence: 2 } }])));
  ok(r.length === 1 && r[0].v1 === 66 && r[0].at.startsWith(day), "fitbit heart rate " + JSON.stringify(r));
  ok(H.parseTime("2024-05-01T08:00:00Z") !== null && H.parseTime("31/12/2024 10:00", true) === "2024-12-31 10:00:00" && H.parseTime("nonsense") === null, "time parsing");
  r = await H.readFile(file("empty.csv", "hello,world\n1,2\n"));
  ok(r.length === 0, "unknown CSV gives nothing");
  console.log(`health import: ${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
