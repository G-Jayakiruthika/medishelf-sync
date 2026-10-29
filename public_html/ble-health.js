/* Reads home health meters over Bluetooth (Web Bluetooth, Chrome on Android/desktop) using the standard
   Bluetooth health profiles, so most branded meters work without their own app:
   blood pressure (0x1810), thermometer (0x1809), weight scale (0x181D), glucose (0x1808), pulse oximeter (0x1822).
   window.BleHealth = { supported(), connect(onReading, onStatus), parse: {...} }   — the parsers are also used in tests. */
(function (root) {
  "use strict";

  /* IEEE-11073 16-bit SFLOAT: 12-bit signed mantissa, 4-bit signed exponent. */
  function sfloat(raw) {
    if ([0x07FF, 0x0800, 0x07FE, 0x0802, 0x0801].indexOf(raw) >= 0) return null;   // NaN, NRes, +INF, -INF, reserved
    var m = raw & 0x0FFF, e = raw >> 12;
    if (m >= 0x0800) m -= 0x1000; if (e >= 0x8) e -= 0x10;
    return m * Math.pow(10, e);
  }
  /* 32-bit FLOAT: 24-bit signed mantissa, 8-bit signed exponent. */
  function float32(raw) {
    var m = raw & 0xFFFFFF, e = (raw >>> 24) & 0xFF;
    if (m === 0x7FFFFF || m === 0x800000) return null;
    if (m >= 0x800000) m -= 0x1000000; if (e >= 0x80) e -= 0x100;
    return m * Math.pow(10, e);
  }
  const r1 = (x) => Math.round(x * 10) / 10;

  function parseBloodPressure(dv) {
    var f = dv.getUint8(0), kpa = f & 1, i = 1;
    var sys = sfloat(dv.getUint16(i, true)), dia = sfloat(dv.getUint16(i + 2, true)); i += 6;
    if (f & 2) i += 7;                                   // time stamp
    var pulse = f & 4 ? sfloat(dv.getUint16(i, true)) : null;
    if (kpa) { sys = sys * 7.50062; dia = dia * 7.50062; }
    return { kind: "bp", v1: Math.round(sys), v2: Math.round(dia), pulse: pulse === null ? null : Math.round(pulse) };
  }
  function parseTemperature(dv) {
    var f = dv.getUint8(0), v = float32(dv.getUint32(1, true));
    if (v === null) return null;
    if (f & 1) v = (v - 32) * 5 / 9;                    // °F → °C
    return { kind: "temp", v1: r1(v) };
  }
  function parseWeight(dv) {
    var f = dv.getUint8(0), raw = dv.getUint16(1, true);
    if (raw === 0xFFFF) return null;                    // measurement unsuccessful
    return { kind: "weight", v1: r1(f & 1 ? raw * 0.01 * 0.45359237 : raw * 0.005) };
  }
  function parseGlucose(dv) {
    var f = dv.getUint8(0), i = 3 + 7;                  // flags, sequence number, base time
    if (f & 1) i += 2;                                  // time offset
    if (!(f & 2)) return null;                          // no concentration in this record
    var c = sfloat(dv.getUint16(i, true));
    if (c === null) return null;
    var mgdl = f & 4 ? c * 1000 * 18.016 : c * 100000;  // mol/L → mg/dL, or kg/L → mg/dL
    return { kind: "sugar", v1: Math.round(mgdl) };
  }
  function parseOximeter(dv) {
    var spo2 = sfloat(dv.getUint16(1, true)), pr = sfloat(dv.getUint16(3, true));
    return spo2 === null ? null : { kind: "spo2", v1: Math.round(spo2), pulse: pr === null ? null : Math.round(pr) };
  }

  const PROFILES = [
    { service: 0x1810, ch: 0x2A35, parse: parseBloodPressure },
    { service: 0x1809, ch: 0x2A1C, parse: parseTemperature },
    { service: 0x181D, ch: 0x2A9D, parse: parseWeight },
    { service: 0x1808, ch: 0x2A18, parse: parseGlucose, racp: 0x2A52 },
    { service: 0x1822, ch: 0x2A5F, parse: parseOximeter }
  ];

  function supported() { return !!(root.navigator && root.navigator.bluetooth && root.isSecureContext !== false); }

  /* Lets the person pick their meter, then waits for readings. Returns a function that disconnects. */
  async function connect(onReading, onStatus) {
    const device = await root.navigator.bluetooth.requestDevice({ filters: PROFILES.map(p => ({ services: [p.service] })) });
    onStatus && onStatus("connecting", device.name || "");
    const server = await device.gatt.connect();
    let used = 0;
    for (const p of PROFILES) {
      let svc; try { svc = await server.getPrimaryService(p.service); } catch (e) { continue; }
      const ch = await svc.getCharacteristic(p.ch);
      ch.addEventListener("characteristicvaluechanged", (ev) => { try { const r = p.parse(ev.target.value); if (r) onReading(r, device.name || ""); } catch (e) { /* malformed packet */ } });
      await ch.startNotifications();
      if (p.racp) { try { const racp = await svc.getCharacteristic(p.racp); await racp.startNotifications(); await racp.writeValue(new Uint8Array([0x01, 0x06])); } catch (e) { /* meter sends on its own */ } }
      used++;
    }
    if (!used) { device.gatt.disconnect(); throw new Error("This device is not a supported health meter."); }
    onStatus && onStatus("ready", device.name || "");
    return () => { try { device.gatt.disconnect(); } catch (e) { /* already gone */ } };
  }

  const api = { supported: supported, connect: connect, parse: { sfloat: sfloat, float32: float32, bloodPressure: parseBloodPressure, temperature: parseTemperature, weight: parseWeight, glucose: parseGlucose, oximeter: parseOximeter } };
  if (typeof module !== "undefined" && module.exports) module.exports = api; else root.BleHealth = api;
})(typeof window !== "undefined" ? window : this);
