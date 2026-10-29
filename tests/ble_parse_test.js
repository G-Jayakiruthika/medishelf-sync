// Bluetooth health-meter packets (standard GATT profiles) decoded correctly.   node tests/ble_parse_test.js
const B = require("../public_html/ble-health.js").parse;
let pass = 0, fail = 0;
const eq = (got, want, msg) => { const g = JSON.stringify(got), w = JSON.stringify(want); if (g === w) pass++; else { fail++; console.log("  ✗ " + msg + "\n      got  " + g + "\n      want " + w); } };
const dv = (bytes) => new DataView(Uint8Array.from(bytes).buffer);
const s16 = (v) => [v & 0xFF, (v >> 8) & 0xFF];

eq(B.sfloat(0x0078), 120, "SFLOAT 120");
eq(B.sfloat(0xF4B0), 120, "SFLOAT 1200 × 10^-1");
eq(B.sfloat(0x07FF), null, "SFLOAT NaN");
// Blood pressure: flags = pulse present (0x04), 132/86 mmHg, MAP 101, pulse 72
eq(B.bloodPressure(dv([0x04, ...s16(132), ...s16(86), ...s16(101), ...s16(72)])), { kind: "bp", v1: 132, v2: 86, pulse: 72 }, "blood pressure with pulse");
// Blood pressure with a time stamp before the pulse
eq(B.bloodPressure(dv([0x06, ...s16(145), ...s16(92), ...s16(110), 0xEA, 0x07, 10, 29, 8, 15, 0, ...s16(80)])), { kind: "bp", v1: 145, v2: 92, pulse: 80 }, "time stamp is skipped");
// Thermometer in °C: 36.8 = 368 × 10^-1 → FLOAT bytes
eq(B.temperature(dv([0x00, 0x70, 0x01, 0x00, 0xFF])), { kind: "temp", v1: 36.8 }, "thermometer °C");
// Thermometer in °F: 101.5 = 1015 × 10^-1
eq(B.temperature(dv([0x01, 0xF7, 0x03, 0x00, 0xFF])), { kind: "temp", v1: 38.6 }, "thermometer °F converted");
// Weight scale SI: 62.5 kg = 12500 × 0.005
eq(B.weight(dv([0x00, ...s16(12500)])), { kind: "weight", v1: 62.5 }, "weight in kg");
eq(B.weight(dv([0x01, ...s16(13780)])), { kind: "weight", v1: 62.5 }, "weight in lb converted");
// Glucose: flags = concentration present (0x02), kg/L; 110 mg/dL = 0.00110 kg/L = 110 × 10^-5 → SFLOAT exponent -5 (0xB)
eq(B.glucose(dv([0x02, 1, 0, 0xEA, 0x07, 10, 29, 7, 30, 0, ...s16(0xB06E), 0x11])), { kind: "sugar", v1: 110 }, "glucose in kg/L");
// Glucose in mol/L: 6.1 mmol/L = 61 × 10^-4 mol/L → exponent -4 (0xC)
eq(B.glucose(dv([0x06, 1, 0, 0xEA, 0x07, 10, 29, 7, 30, 0, ...s16(0xC03D), 0x11])), { kind: "sugar", v1: 110 }, "glucose in mmol/L converted");
// Pulse oximeter: SpO2 97, pulse 76
eq(B.oximeter(dv([0x00, ...s16(97), ...s16(76)])), { kind: "spo2", v1: 97, pulse: 76 }, "pulse oximeter");

console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
