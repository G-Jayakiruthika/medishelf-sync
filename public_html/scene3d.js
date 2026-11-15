/* AushadhBox 3D: a tiny WebGL viewer for the .glb models in /assets/3d (made by tools/models3d).
   <canvas class="scene3d" data-model="/assets/3d/doses.glb" aria-hidden="true"></canvas>
   - No libraries (the site's CSP allows only its own scripts); about 9 KB.
   - Soft studio light, a contact shadow, a slow sway; drag to turn it.
   - Draws only while on screen; holds still for "reduce motion"; not loaded at all in low-data mode
     (body.lite) or when the browser has no WebGL; the page looks complete without it. */
(function () {
  "use strict";
  const cache = {};
  const reduce = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;

  function parseGlb(buf) {
    const dv = new DataView(buf);
    if (dv.getUint32(0, true) !== 0x46546C67) throw new Error("not a glb");
    const jsonLen = dv.getUint32(12, true);
    const gl = JSON.parse(new TextDecoder().decode(new Uint8Array(buf, 20, jsonLen)));
    const binStart = 20 + jsonLen + 8;
    const acc = (i) => {
      const a = gl.accessors[i], v = gl.bufferViews[a.bufferView], off = binStart + (v.byteOffset || 0) + (a.byteOffset || 0);
      const n = a.count * ({ SCALAR: 1, VEC3: 3, VEC4: 4 }[a.type]);
      return a.componentType === 5126 ? new Float32Array(buf, off, n) : a.componentType === 5125 ? new Uint32Array(buf, off, n) : new Uint16Array(buf, off, n);
    };
    const prims = [], lo = [1e9, 1e9, 1e9], hi = [-1e9, -1e9, -1e9];
    gl.meshes[0].primitives.forEach(p => {
      const mat = gl.materials[p.material].pbrMetallicRoughness, a = gl.accessors[p.attributes.POSITION];
      for (let k = 0; k < 3; k++) { lo[k] = Math.min(lo[k], a.min[k]); hi[k] = Math.max(hi[k], a.max[k]); }
      prims.push({ pos: acc(p.attributes.POSITION), nor: acc(p.attributes.NORMAL), idx: acc(p.indices), color: mat.baseColorFactor, rough: mat.roughnessFactor });
    });
    prims.sort((a, b) => a.color[3] - b.color[3]).reverse();   // opaque first, glass last
    return { prims, lo, hi };
  }

  const VS = "attribute vec3 p;attribute vec3 n;uniform mat4 mvp;uniform mat4 m;varying vec3 vn;varying vec3 vp;void main(){vn=mat3(m)*n;vp=(m*vec4(p,1.)).xyz;gl_Position=mvp*vec4(p,1.);}";
  const FS = "precision mediump float;varying vec3 vn;varying vec3 vp;uniform vec4 col;uniform float rough;uniform vec3 eye;" +
    "void main(){vec3 N=normalize(vn);vec3 V=normalize(eye-vp);vec3 L=normalize(vec3(.55,.9,.6));vec3 L2=normalize(vec3(-.7,.3,-.4));" +
    "float d=max(dot(N,L),0.);float d2=max(dot(N,L2),0.)*.35;float hemi=.5+.5*N.y;vec3 H=normalize(L+V);" +
    "float sp=pow(max(dot(N,H),0.),mix(90.,8.,rough))*(1.-rough)*.9;float rim=pow(1.-max(dot(N,V),0.),3.)*.25;" +
    "vec3 c=col.rgb*(d*.95+d2+mix(vec3(.16,.15,.13),vec3(.32,.34,.38),hemi))+vec3(sp+rim);" +
    "gl_FragColor=vec4(pow(c,vec3(1./2.2)),col.a);}";
  const SVS = "attribute vec2 q;uniform mat4 mvp;uniform vec3 c;uniform float r;varying vec2 uv;void main(){uv=q;gl_Position=mvp*vec4(c.x+q.x*r,c.y,c.z+q.y*r*.8,1.);}";
  const SFS = "precision mediump float;varying vec2 uv;void main(){float d=length(uv);gl_FragColor=vec4(.05,.08,.12,.32*smoothstep(1.,.15,d));}";

  function program(g, vs, fs) {
    const pr = g.createProgram();
    [[g.VERTEX_SHADER, vs], [g.FRAGMENT_SHADER, fs]].forEach(([t, src]) => { const s = g.createShader(t); g.shaderSource(s, src); g.compileShader(s); g.attachShader(pr, s); });
    g.linkProgram(pr);
    if (!g.getProgramParameter(pr, g.LINK_STATUS)) throw new Error("shader");
    return pr;
  }

  // 4x4 matrices, column-major
  const persp = (f, a, n, fr) => { const t = 1 / Math.tan(f / 2); return [t / a, 0, 0, 0, 0, t, 0, 0, 0, 0, (fr + n) / (n - fr), -1, 0, 0, 2 * fr * n / (n - fr), 0]; };
  const mul = (a, b) => { const o = new Array(16); for (let i = 0; i < 4; i++) for (let j = 0; j < 4; j++) { let s = 0; for (let k = 0; k < 4; k++) s += a[k * 4 + j] * b[i * 4 + k]; o[i * 4 + j] = s; } return o; };
  const rotY = (a) => [Math.cos(a), 0, -Math.sin(a), 0, 0, 1, 0, 0, Math.sin(a), 0, Math.cos(a), 0, 0, 0, 0, 1];
  const rotX = (a) => [1, 0, 0, 0, 0, Math.cos(a), Math.sin(a), 0, 0, -Math.sin(a), Math.cos(a), 0, 0, 0, 0, 1];
  const trans = (x, y, z) => [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, x, y, z, 1];

  function mount(cv) {
    if (cv._s3d || document.body.classList.contains("lite")) return;
    cv._s3d = true;
    const g = cv.getContext("webgl", { antialias: true, alpha: true, premultipliedAlpha: false });
    if (!g) { cv.hidden = true; return; }
    const url = cv.dataset.model;
    (cache[url] = cache[url] || fetch(url).then(r => { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); }).then(parseGlb))
      .then(model => start(cv, g, model)).catch(() => { cv.hidden = true; });
  }

  function start(cv, g, model) {
    const pr = program(g, VS, FS), sh = program(g, SVS, SFS);
    const L = (n) => g.getUniformLocation(pr, n);
    const u = { mvp: L("mvp"), m: L("m"), col: L("col"), rough: L("rough"), eye: L("eye") };
    const ap = g.getAttribLocation(pr, "p"), an = g.getAttribLocation(pr, "n");
    const big = !!g.getExtension("OES_element_index_uint");
    const bufs = model.prims.filter(p => big || !(p.idx instanceof Uint32Array)).map(p => {
      const mk = (data, t) => { const b = g.createBuffer(); g.bindBuffer(t, b); g.bufferData(t, data, g.STATIC_DRAW); return b; };
      return { p: mk(p.pos, g.ARRAY_BUFFER), n: mk(p.nor, g.ARRAY_BUFFER), i: mk(p.idx, g.ELEMENT_ARRAY_BUFFER), count: p.idx.length, type: p.idx instanceof Uint32Array ? g.UNSIGNED_INT : g.UNSIGNED_SHORT, color: p.color, rough: p.rough };
    });
    const quad = g.createBuffer(); g.bindBuffer(g.ARRAY_BUFFER, quad); g.bufferData(g.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), g.STATIC_DRAW);
    const c = [(model.lo[0] + model.hi[0]) / 2, (model.lo[1] + model.hi[1]) / 2, (model.lo[2] + model.hi[2]) / 2];
    const size = Math.max(model.hi[0] - model.lo[0], model.hi[1] - model.lo[1], model.hi[2] - model.lo[2]);
    const dist = size * 1.95;
    let yaw = Number(cv.dataset.yaw || -0.5), pitch = 0.32, drag = null, visible = true, t0 = performance.now(), raf = 0, last = 0;

    function draw(now) {
      raf = 0;
      const dpr = Math.min(2, window.devicePixelRatio || 1), w = Math.round(cv.clientWidth * dpr), h = Math.round(cv.clientHeight * dpr);
      if (!w || !h) return;
      if (cv.width !== w || cv.height !== h) { cv.width = w; cv.height = h; }
      g.viewport(0, 0, w, h); g.clearColor(0, 0, 0, 0); g.clear(g.COLOR_BUFFER_BIT | g.DEPTH_BUFFER_BIT);
      const sway = reduce || drag ? 0 : Math.sin((now - t0) / 2600) * 0.45;
      const model_m = mul(rotY(yaw + sway), trans(-c[0], -c[1], -c[2]));
      const view = mul(trans(0, 0, -dist), rotX(pitch));
      const vp = mul(persp(0.62, w / h, dist / 20, dist * 4), view);
      const mvp = mul(vp, model_m);
      const ey = [0, Math.sin(pitch) * dist, Math.cos(pitch) * dist];   // eye in model-rotation space (approx.)
      // contact shadow under the model
      g.disable(g.DEPTH_TEST); g.enable(g.BLEND); g.blendFunc(g.SRC_ALPHA, g.ONE_MINUS_SRC_ALPHA);
      g.useProgram(sh); g.uniformMatrix4fv(g.getUniformLocation(sh, "mvp"), false, vp);
      g.uniform3f(g.getUniformLocation(sh, "c"), 0, model.lo[1] - c[1] - 0.01, 0); g.uniform1f(g.getUniformLocation(sh, "r"), size * 0.62);
      const aq = g.getAttribLocation(sh, "q"); g.bindBuffer(g.ARRAY_BUFFER, quad); g.enableVertexAttribArray(aq); g.vertexAttribPointer(aq, 2, g.FLOAT, false, 0, 0);
      g.drawArrays(g.TRIANGLE_STRIP, 0, 4); g.disableVertexAttribArray(aq);
      // the model
      g.enable(g.DEPTH_TEST); g.useProgram(pr);
      g.uniformMatrix4fv(u.mvp, false, mvp); g.uniformMatrix4fv(u.m, false, model_m); g.uniform3fv(u.eye, ey);
      g.enableVertexAttribArray(ap); g.enableVertexAttribArray(an);
      bufs.forEach(b => {
        g.depthMask(b.color[3] > 0.99);
        g.bindBuffer(g.ARRAY_BUFFER, b.p); g.vertexAttribPointer(ap, 3, g.FLOAT, false, 0, 0);
        g.bindBuffer(g.ARRAY_BUFFER, b.n); g.vertexAttribPointer(an, 3, g.FLOAT, false, 0, 0);
        g.bindBuffer(g.ELEMENT_ARRAY_BUFFER, b.i); g.uniform4fv(u.col, b.color); g.uniform1f(u.rough, b.rough);
        g.drawElements(g.TRIANGLES, b.count, b.type, 0);
      });
      g.depthMask(true);
      last = now;
      if (visible && !reduce && !document.hidden) raf = requestAnimationFrame(draw);
    }
    const kick = () => { if (!raf) raf = requestAnimationFrame(draw); };
    if ("IntersectionObserver" in window) new IntersectionObserver(es => { visible = es[0].isIntersecting; if (visible) kick(); }).observe(cv);
    document.addEventListener("visibilitychange", kick);
    window.addEventListener("resize", kick);
    cv.addEventListener("pointerdown", (e) => { drag = { x: e.clientX, y: e.clientY, yaw, pitch }; try { cv.setPointerCapture(e.pointerId); } catch (er) { /* old browser */ } });
    cv.addEventListener("pointermove", (e) => { if (!drag) return; yaw = drag.yaw + (e.clientX - drag.x) / 90; pitch = Math.max(-0.2, Math.min(1.1, drag.pitch + (e.clientY - drag.y) / 140)); kick(); });
    const up = () => { if (drag) { drag = null; t0 = performance.now(); kick(); } };
    cv.addEventListener("pointerup", up); cv.addEventListener("pointercancel", up);
    cv.classList.add("ready");
    kick();
  }

  function scan(root) { (root || document).querySelectorAll("canvas.scene3d[data-model]").forEach(mount); }
  window.Scene3D = { mount, scan };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => scan()); else scan();
  if ("MutationObserver" in window) new MutationObserver(() => { if (!scan.q) scan.q = requestAnimationFrame(() => { scan.q = 0; scan(); }); }).observe(document.documentElement, { childList: true, subtree: true });
})();
