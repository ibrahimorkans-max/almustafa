/*
 * The 3D weighbridge on the Live weight screen (three.js, served by the app: public/js/vendor).
 *
 * Two modes:
 *  LIVE        the mill's own weighbridge: the platform display shows the reading the weighbridge PC sends
 *              (the same reading as the dial, GET /admin/gate/live-weight/{scale}), and a vehicle stands on the
 *              platform while there is weight on it. Nothing is invented: no reading, no gate, no camera.
 *  SIMULATION  a training and demonstration model of the whole yard: tractor trolleys, cargo trucks and
 *              container trailers come in through the main gate, queue for the weighbridge (first come, first
 *              served), are weighed, unload (or load), come back for the second weight and leave. Its weights
 *              come from a defined model (each vehicle's empty weight and load, shared out over its axles) and are
 *              always marked SIMULATION; they are never recorded or shown as measured weights.
 *
 * Everything runs in the browser: no request redraws the page, so the weighing form next to it is never
 * interrupted. It draws only while it is on screen and the tab is in front.
 */
import * as THREE from 'three';
import { OrbitControls } from '/js/vendor/three-0.169.0-OrbitControls.js';
import { RoomEnvironment } from '/js/vendor/three-0.169.0-RoomEnvironment.js';

// ------------------------------------------------------------------ the yard (metres; north is -Z)

const Y = {
    roadZ: 64,                       // public road, outside the south wall
    wallN: -70, wallS: 50, wallW: -95, wallE: 95,
    gateIn: { x: -14, width: 10 },   // entry gate (drives north)
    gateOut: { x: 14, width: 10 },   // exit gate (drives south)
    wb: { x: -14, z1: 11, z2: -11, width: 3.8, capacity: 80000 },  // platform: 22 m × 3.8 m
    wbStopZ: 16,                     // stop line before the platform (front bumper)
    shed: { x: 45, z: -26 },         // unloading / loading shed (drive-through, east-west)
};
const PATH_IN = [[-150, Y.roadZ], [-14, Y.roadZ], [-14, -26], [45, -26]];
const PATH_OUT = [[45, -26], [72, -26], [72, 36], [-14, 36], [-14, -26], [14, -26], [14, Y.roadZ], [160, Y.roadZ]];

const KMH = 1 / 3.6;
const LIMITS = { road: 30 * KMH, yard: 15 * KMH, scale: 6 * KMH };
const ACCEL = 1.1;   // m/s²
const BRAKE = 2.2;   // m/s² (comfortable)

// ------------------------------------------------------------------ vehicle types (a defined weight model)

/*
 * Empty weight and load are the model's: each vehicle carries its own (set when it is added, in turn from the
 * list), shared out over its axles. Loaded paddy comes in; rice goes out in containers.
 */
const TYPES = {
    tractor: {
        label: 'TRACTOR TROLLEY', inbound: true, color: 0xc0392b, plates: ['LRA-4521', 'MNA-3318', 'BWP-7720', 'LDN-1904'],
        tare: 5850, loads: [12400, 11850, 13200, 12900], wheelbase: 2.4, front: 0.6, width: 2.3,
        trailer: { hitch: 1.3, wheelbase: 4.6, length: 6.2, rear: 2.9 },
        axles: [{ part: 0, at: 2.4, share: 0.12 }, { part: 0, at: 0, share: 0.22 }, { part: 1, at: 0.55, share: 0.33 }, { part: 1, at: -0.55, share: 0.33 }],
    },
    truck: {
        label: 'CARGO TRUCK', inbound: true, color: 0x1f6fb2, plates: ['LES-2205', 'MUL-6634', 'SGD-1287', 'KHI-5532'],
        tare: 10900, loads: [17560, 16900, 18240, 17100], wheelbase: 5.6, front: 1.4, width: 2.5, rearOverhang: 2.9,
        axles: [{ part: 0, at: 5.6, share: 0.24 }, { part: 0, at: 0.65, share: 0.38 }, { part: 0, at: -0.65, share: 0.38 }],
    },
    container: {
        label: 'CONTAINER TRAILER', inbound: false, color: 0x2e7d32, plates: ['TLF-9021', 'KAR-4410', 'PQA-7765', 'HUB-3089'],
        tare: 15400, loads: [21800, 23100, 22450, 20900], wheelbase: 3.6, front: 1.6, width: 2.5,
        trailer: { hitch: -0.5, wheelbase: 9.4, length: 12.0, rear: 2.9, container: true },
        axles: [{ part: 0, at: 3.6, share: 0.12 }, { part: 0, at: 0, share: 0.28 }, { part: 1, at: 0.6, share: 0.2 }, { part: 1, at: 0, share: 0.2 }, { part: 1, at: -0.6, share: 0.2 }],
    },
};

// ------------------------------------------------------------------ small helpers

const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const fmtKg = (kg) => (kg === null || kg === undefined ? '—' : Math.round(kg).toLocaleString('en-US') + ' KG');
const v2 = (x, z) => new THREE.Vector2(x, z);

/** A polyline with rounded corners, measured by distance. */
class Path {
    constructor(points, radius = 9) {
        const pts = points.map(([x, z]) => v2(x, z));
        const out = [pts[0]];
        for (let i = 1; i < pts.length - 1; i++) {
            const a = pts[i - 1], b = pts[i], c = pts[i + 1];
            const d1 = b.clone().sub(a).normalize(), d2 = c.clone().sub(b).normalize();
            const r = Math.min(radius, b.distanceTo(a) / 2, b.distanceTo(c) / 2);
            const p1 = b.clone().sub(d1.clone().multiplyScalar(r)), p2 = b.clone().add(d2.clone().multiplyScalar(r));
            for (let k = 0; k <= 8; k++) {   // quadratic Bézier through the corner
                const t = k / 8, u = 1 - t;
                out.push(v2(u * u * p1.x + 2 * u * t * b.x + t * t * p2.x, u * u * p1.y + 2 * u * t * b.y + t * t * p2.y));
            }
        }
        out.push(pts[pts.length - 1]);
        this.pts = out;
        this.cum = [0];
        for (let i = 1; i < out.length; i++) this.cum.push(this.cum[i - 1] + out[i].distanceTo(out[i - 1]));
        this.length = this.cum[this.cum.length - 1];
    }

    /** The point and direction at distance s (beyond the ends: straight on). */
    at(s) {
        const n = this.pts.length;
        if (s <= 0) {
            const d = this.pts[1].clone().sub(this.pts[0]).normalize();
            return { p: this.pts[0].clone().add(d.clone().multiplyScalar(s)), d };
        }
        if (s >= this.length) {
            const d = this.pts[n - 1].clone().sub(this.pts[n - 2]).normalize();
            return { p: this.pts[n - 1].clone().add(d.clone().multiplyScalar(s - this.length)), d };
        }
        let lo = 0, hi = n - 1;
        while (hi - lo > 1) { const mid = (lo + hi) >> 1; if (this.cum[mid] <= s) lo = mid; else hi = mid; }
        const t = (s - this.cum[lo]) / (this.cum[hi] - this.cum[lo] || 1);
        const d = this.pts[hi].clone().sub(this.pts[lo]).normalize();
        return { p: this.pts[lo].clone().lerp(this.pts[hi], t), d };
    }

    /** The distance along the path of the point nearest to (x, z), searched from `from` on. */
    sOf(x, z, from = 0) {
        const q = v2(x, z);
        let best = Infinity, bestS = 0;
        for (let i = 1; i < this.pts.length; i++) {
            if (this.cum[i] < from) continue;
            const a = this.pts[i - 1], b = this.pts[i], ab = b.clone().sub(a);
            const t = clamp(q.clone().sub(a).dot(ab) / (ab.lengthSq() || 1), 0, 1);
            const p = a.clone().add(ab.multiplyScalar(t));
            const dist = p.distanceTo(q);
            if (dist < best - 1e-6) { best = dist; bestS = this.cum[i - 1] + t * (this.cum[i] - this.cum[i - 1]); }
        }
        return bestS;
    }
}

/** A canvas texture with lines of text (signs, the platform display). */
function textCanvas(w, h) {
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;
    return { canvas, ctx: canvas.getContext('2d'), tex };
}

const mat = (color, opts = {}) => new THREE.MeshStandardMaterial({ color, roughness: 0.8, metalness: 0.05, ...opts });
function box(w, h, d, material, x = 0, y = 0, z = 0) {
    const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material);
    m.position.set(x, y, z);
    m.castShadow = true; m.receiveShadow = true;
    return m;
}
function cyl(rt, rb, h, material, seg = 16) {
    const m = new THREE.Mesh(new THREE.CylinderGeometry(rt, rb, h, seg), material);
    m.castShadow = true; m.receiveShadow = true;
    return m;
}

// ------------------------------------------------------------------ the scene

class WeighbridgeScene {
    constructor(root) {
        this.root = root;
        this.mill = (root.dataset.mill || 'ALMUSTAFA RICE MILL').toUpperCase();
        this.liveUrl = root.dataset.wb3dLive || null;
        this.scaleCode = root.dataset.scaleCode || 'WB';
        this.capacity = parseFloat(root.dataset.capacity) || Y.wb.capacity;
        this.mode = this.liveUrl ? 'live' : 'sim';
        this.auto = true;
        this.running = false;
        this.simTime = 0;
        this.vehicles = [];
        this.nextId = 1;
        this.typeTurn = { tractor: 0, truck: 0, container: 0 };
        this.history = [];
        this.completed = 0;
        this.live = { kg: null, live: false, stable: false, plate: '' };
        this.form = { typed: '', changedAt: 0, plate: '', kind: 'truck', recorded: null };   // the weighing form beside the view
        this.night = false;
        this.cameraName = 'aerial';
        this.followId = null;
        this.demo = null;
        this.visible = false;
        this.build();
    }

    build() {
        const wrap = this.root.querySelector('[data-wb3d="canvas"]');
        this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 1.05;
        wrap.textContent = '';
        wrap.appendChild(this.renderer.domElement);

        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(45, 16 / 9, 0.5, 900);
        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.maxPolarAngle = Math.PI * 0.48;
        this.controls.minDistance = 8;
        this.controls.maxDistance = 360;
        this.camMove = null;

        this.hemi = new THREE.HemisphereLight(0xdfefff, 0x6b5b3e, 0.9);
        this.scene.add(this.hemi);
        this.sun = new THREE.DirectionalLight(0xfff1d6, 2.4);
        this.sun.position.set(-80, 140, 60);
        this.sun.castShadow = true;
        this.sun.shadow.mapSize.set(2048, 2048);
        Object.assign(this.sun.shadow.camera, { left: -95, right: 95, top: 95, bottom: -95, near: 10, far: 400 });
        this.sun.shadow.normalBias = 0.02;
        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        this.scene.environmentIntensity = 0.35;
        this.buildSky();
        this.sun.shadow.bias = -0.0005;
        this.scene.add(this.sun);

        this.buildGround();
        this.buildWalls();
        this.buildGates();
        this.buildBuildings();
        this.buildWeighbridge();
        this.buildDust();
        this.buildSmoke();
        this.pathIn = new Path(PATH_IN);
        this.pathOut = new Path(PATH_OUT);
        this.setNight(false);
        this.setCamera(this.liveUrl ? 'side' : 'aerial', true);
        this.resize();
        new ResizeObserver(() => this.resize()).observe(wrap);
        this.clock = new THREE.Clock();
        this.loop = this.loop.bind(this);
        requestAnimationFrame(this.loop);
    }

    // ------------------------------------------------------------------ environment

    buildGround() {
        const grass = new THREE.Mesh(new THREE.PlaneGeometry(700, 700), mat(0x7d9a52, { roughness: 1 }));
        grass.rotation.x = -Math.PI / 2; grass.receiveShadow = true;
        this.scene.add(grass);
        const yard = new THREE.Mesh(new THREE.PlaneGeometry(Y.wallE - Y.wallW, Y.wallS - Y.wallN), mat(0xb9a98a, { roughness: 1 }));
        yard.rotation.x = -Math.PI / 2; yard.position.set(0, 0.01, (Y.wallS + Y.wallN) / 2); yard.receiveShadow = true;
        this.scene.add(yard);

        const asphalt = mat(0x3d3f42, { roughness: 0.95 });
        const concrete = mat(0x9c9a94, { roughness: 0.9 });
        const strip = (x1, z1, x2, z2, w, m) => {
            const len = Math.hypot(x2 - x1, z2 - z1);
            const r = new THREE.Mesh(new THREE.PlaneGeometry(len + w, w), m);
            r.rotation.x = -Math.PI / 2;
            r.rotation.z = -Math.atan2(z2 - z1, x2 - x1);
            r.position.set((x1 + x2) / 2, 0.03, (z1 + z2) / 2);
            r.receiveShadow = true;
            this.scene.add(r);
        };
        strip(-260, Y.roadZ, 260, Y.roadZ, 12, asphalt);
        strip(-14, Y.roadZ, -14, -26, 7, concrete);
        strip(-14, -26, 72, -26, 7, concrete);
        strip(72, -26, 72, 36, 7, concrete);
        strip(72, 36, -14, 36, 7, concrete);
        strip(14, -26, 14, Y.roadZ, 7, concrete);

        // Road markings: centre dashes on the public road, arrows and stop lines inside.
        const white = new THREE.MeshBasicMaterial({ color: 0xf2f2f2 });
        for (let x = -250; x < 250; x += 8) {
            const d = new THREE.Mesh(new THREE.PlaneGeometry(4, 0.2), white);
            d.rotation.x = -Math.PI / 2; d.position.set(x, 0.05, Y.roadZ);
            this.scene.add(d);
        }
        const stopLine = (x, z, w) => {
            const s = new THREE.Mesh(new THREE.PlaneGeometry(w, 0.45), white);
            s.rotation.x = -Math.PI / 2; s.position.set(x, 0.05, z);
            this.scene.add(s);
        };
        stopLine(Y.gateIn.x, 57, 6);
        stopLine(Y.gateOut.x, 43, 6);
        stopLine(Y.wb.x, Y.wbStopZ, 4.5);
        const arrowShape = new THREE.Shape();
        arrowShape.moveTo(0, 2); arrowShape.lineTo(1, 0.6); arrowShape.lineTo(0.35, 0.6); arrowShape.lineTo(0.35, -1.6);
        arrowShape.lineTo(-0.35, -1.6); arrowShape.lineTo(-0.35, 0.6); arrowShape.lineTo(-1, 0.6); arrowShape.closePath();
        const arrowGeo = new THREE.ShapeGeometry(arrowShape);
        const arrow = (x, z, angle) => {
            const a = new THREE.Mesh(arrowGeo, white);
            a.rotation.x = -Math.PI / 2; a.rotation.z = angle; a.position.set(x, 0.05, z);
            this.scene.add(a);
        };
        arrow(-14, 30, 0); arrow(-14, -20, 0); arrow(30, -26, -Math.PI / 2); arrow(72, 10, Math.PI); arrow(30, 36, Math.PI / 2); arrow(14, 20, Math.PI);
    }

    buildWalls() {
        const wallMat = mat(0xd8d0c0);
        const capMat = mat(0x8a2d1f);
        const seg = (x1, z1, x2, z2) => {
            const len = Math.hypot(x2 - x1, z2 - z1);
            const w = box(len, 3, 0.4, wallMat, (x1 + x2) / 2, 1.5, (z1 + z2) / 2);
            w.rotation.y = -Math.atan2(z2 - z1, x2 - x1);
            this.scene.add(w);
            const c = box(len, 0.25, 0.6, capMat, (x1 + x2) / 2, 3.1, (z1 + z2) / 2);
            c.rotation.y = w.rotation.y;
            this.scene.add(c);
        };
        seg(Y.wallW, Y.wallN, Y.wallE, Y.wallN);
        seg(Y.wallW, Y.wallN, Y.wallW, Y.wallS);
        seg(Y.wallE, Y.wallN, Y.wallE, Y.wallS);
        // South wall with the two gate openings (entry at x = -14, exit at x = 14).
        seg(Y.wallW, Y.wallS, -21, Y.wallS);
        seg(-7, Y.wallS, 7, Y.wallS);
        seg(21, Y.wallS, Y.wallE, Y.wallS);

        // Trees along the wall.
        const trunk = mat(0x6b4a2b), leaves = mat(0x3f6b2a);
        const tree = (x, z, s = 1) => {
            const t = cyl(0.25 * s, 0.35 * s, 3 * s, trunk, 8); t.position.set(x, 1.5 * s, z); this.scene.add(t);
            const c = new THREE.Mesh(new THREE.IcosahedronGeometry(2.4 * s, 1), leaves);
            c.position.set(x, 4.2 * s, z); c.castShadow = true; this.scene.add(c);
        };
        for (let x = -88; x <= 88; x += 14) { if (Math.abs(x) > 26) tree(x, Y.wallS - 4, 1 + (Math.abs(x) % 3) * 0.1); }
        for (let z = -60; z <= 30; z += 15) { tree(Y.wallW + 5, z); tree(Y.wallE - 5, z, 1.1); }
        for (let x = -140; x <= 140; x += 22) { tree(x, Y.roadZ + 12, 1.2); }

        // Light poles.
        this.lampHeads = [];
        const pole = mat(0x8c9399, { metalness: 0.6, roughness: 0.4 });
        this.lampMat = new THREE.MeshStandardMaterial({ color: 0xffffff, emissive: 0xffe2a8, emissiveIntensity: 0 });
        const lightPole = (x, z) => {
            const p = cyl(0.12, 0.18, 9, pole, 8); p.position.set(x, 4.5, z); this.scene.add(p);
            const head = box(1.4, 0.25, 0.5, this.lampMat, x, 9, z); this.scene.add(head);
        };
        [[-24, 40], [-24, 0], [-24, -36], [24, 40], [24, 0], [60, -36], [82, 10], [60, 46], [-60, 20], [-60, -40]].forEach(([x, z]) => lightPole(x, z));
        this.nightLights = [];
        [[-14, 0], [45, -26], [-14, 50]].forEach(([x, z]) => {
            const l = new THREE.PointLight(0xffd9a0, 0, 60, 1.6);
            l.position.set(x, 10, z);
            this.scene.add(l);
            this.nightLights.push(l);
        });
    }

    buildGates() {
        // The entrance structure: two pillars and a lit sign above both gates.
        const steel = mat(0x5f6b73, { metalness: 0.7, roughness: 0.35 });
        const pillarMat = mat(0x8a2d1f);
        [-22, -6, 6, 22].forEach((x) => this.scene.add(box(1.6, 9, 1.6, pillarMat, x, 4.5, Y.wallS)));
        this.scene.add(box(46, 2.6, 1.2, mat(0x1d2a33), 0, 10.2, Y.wallS));
        const sign = textCanvas(2048, 128);
        this.signTex = sign;
        this.drawSign();
        const signMesh = new THREE.Mesh(new THREE.PlaneGeometry(44, 2.4), new THREE.MeshStandardMaterial({ map: sign.tex, emissive: 0xffffff, emissiveMap: sign.tex, emissiveIntensity: 0.6 }));
        signMesh.position.set(0, 10.2, Y.wallS + 0.62);
        this.scene.add(signMesh);
        const signBack = signMesh.clone(); signBack.rotation.y = Math.PI; signBack.position.z = Y.wallS - 0.62;
        this.scene.add(signBack);

        // Guard cabin.
        const cabin = new THREE.Group();
        cabin.add(box(4, 3, 3.5, mat(0xe9e4d8), 0, 1.5, 0));
        cabin.add(box(4.6, 0.3, 4.1, mat(0x8a2d1f), 0, 3.15, 0));
        cabin.add(box(2.6, 1.1, 0.05, new THREE.MeshStandardMaterial({ color: 0x9fc4d8, metalness: 0.4, roughness: 0.1 }), 0, 1.9, 1.78));
        cabin.position.set(0, 0, Y.wallS + 5);
        this.scene.add(cabin);

        this.gates = {
            in: this.makeGate('in', Y.gateIn.x, +1, steel),
            out: this.makeGate('out', Y.gateOut.x, -1, steel),
        };
    }

    /** A sliding gate: a steel leaf on a rail, slides sideways behind the wall; with its signal and ANPR camera. */
    makeGate(name, x, side, steel) {
        const g = { name, x, pos: 0, state: 'CLOSED', vel: 0, request: null, scanUntil: 0, clearSince: 0, manualOpen: false, blockedMsg: null };
        const leaf = new THREE.Group();
        const w = 10.4;
        leaf.add(box(w, 0.18, 0.18, steel, 0, 0.35, 0));
        leaf.add(box(w, 0.18, 0.18, steel, 0, 2.4, 0));
        for (let i = 0; i <= 13; i++) leaf.add(box(0.08, 2.1, 0.08, steel, -w / 2 + i * (w / 13), 1.35, 0));
        leaf.add(box(0.2, 2.5, 0.2, mat(0xf2c200), -w / 2, 1.3, 0));
        // Wheels on the rail.
        [-3.5, 3.5].forEach((dx) => { const wh = cyl(0.18, 0.18, 0.12, mat(0x222222), 12); wh.rotation.x = Math.PI / 2; wh.position.set(dx, 0.2, 0); leaf.add(wh); });
        const zLeaf = Y.wallS + (name === 'in' ? 0.9 : -0.9);
        leaf.position.set(x, 0, zLeaf);
        this.scene.add(leaf);
        this.scene.add(box(24, 0.06, 0.25, mat(0x55595c, { metalness: 0.8 }), x + side * 7, 0.04, zLeaf));
        g.leaf = leaf;
        g.closedX = x;
        g.openX = x + side * 11;   // slides fully clear of the 10 m opening

        // Traffic signal: red, yellow, green.
        const sig = new THREE.Group();
        sig.add(box(0.15, 4.2, 0.15, steel, 0, 2.1, 0));
        sig.add(box(0.7, 1.9, 0.5, mat(0x1b1b1b), 0, 4.8, 0));
        g.lamps = {};
        [['red', 0xff2020, 5.4], ['yellow', 0xffb000, 4.8], ['green', 0x20e060, 4.2]].forEach(([k, c, y]) => {
            const m = new THREE.MeshStandardMaterial({ color: 0x222222, emissive: c, emissiveIntensity: 0 });
            const lamp = new THREE.Mesh(new THREE.SphereGeometry(0.22, 12, 8), m);
            lamp.position.set(0, y, name === 'in' ? 0.28 : -0.28);
            sig.add(lamp);
            g.lamps[k] = m;
        });
        sig.position.set(x - side * 6.5, 0, name === 'in' ? Y.wallS + 3 : Y.wallS - 3);
        this.scene.add(sig);

        // ANPR camera on a bracket, and its flash.
        const cam = new THREE.Group();
        cam.add(box(0.12, 6, 0.12, steel, 0, 3, 0));
        cam.add(box(0.5, 0.35, 0.9, mat(0xeeeeee), 0, 6, name === 'in' ? 0.4 : -0.4));
        g.flash = new THREE.PointLight(0xbfe6ff, 0, 25, 2);
        g.flash.position.set(0, 6, name === 'in' ? 1.5 : -1.5);
        cam.add(g.flash);
        cam.position.set(x + side * 6.5, 0, name === 'in' ? Y.wallS + 2 : Y.wallS - 2);
        this.scene.add(cam);

        // Sensor zones: where a vehicle waits, and the passage the gate must never close on.
        g.stopZ = name === 'in' ? 57 : 43;
        g.passage = { x1: x - 5.5, x2: x + 5.5, z1: Y.wallS - 6, z2: Y.wallS + 6 };
        g.loop = { x1: x - 5, x2: x + 5, z1: name === 'in' ? Y.wallS + 6 : Y.wallS - 14, z2: name === 'in' ? Y.wallS + 14 : Y.wallS - 6 };
        this.setSignal(g, 'red');
        return g;
    }

    buildBuildings() {
        const wall = mat(0xe3ddd0), roof = mat(0x7b8790, { metalness: 0.5, roughness: 0.5 }), dark = mat(0x3a4148);
        this.windowMat = new THREE.MeshStandardMaterial({ color: 0x2f3a44, emissive: 0xffd27a, emissiveIntensity: 0 });
        const building = (x, z, w, h, d, label) => {
            const g = new THREE.Group();
            g.add(box(w, h, d, wall, 0, h / 2, 0));
            const r = box(w + 0.8, 0.4, d + 0.8, roof, 0, h + 0.2, 0); g.add(r);
            for (let i = -w / 2 + 3; i < w / 2 - 2; i += 4) {
                g.add(box(1.8, 1.2, 0.05, this.windowMat, i, h * 0.65, d / 2 + 0.03));
            }
            if (label) {
                const t = textCanvas(512, 96);
                t.ctx.fillStyle = '#1d2a33'; t.ctx.fillRect(0, 0, 512, 96);
                t.ctx.fillStyle = '#f5c518'; t.ctx.font = 'bold 54px Arial'; t.ctx.textAlign = 'center'; t.ctx.textBaseline = 'middle';
                t.ctx.fillText(label, 256, 50); t.tex.needsUpdate = true;
                const p = new THREE.Mesh(new THREE.PlaneGeometry(Math.min(12, w * 0.7), 2.2), new THREE.MeshBasicMaterial({ map: t.tex }));
                p.position.set(0, h - 1.4, d / 2 + 0.05);
                g.add(p);
            }
            g.position.set(x, 0, z);
            this.scene.add(g);
            return g;
        };
        building(-55, -45, 34, 16, 26, 'RICE PROCESSING');
        building(-62, 10, 26, 9, 22, 'PADDY GODOWN');
        const office = building(40, 18, 22, 8, 12, 'MILL OFFICE');
        office.rotation.y = Math.PI;

        // The unloading / loading shed: an open drive-through roof over the road at x 34 to 56.
        const shed = new THREE.Group();
        [[-11, -5], [-11, 5], [11, -5], [11, 5], [0, -5], [0, 5]].forEach(([dx, dz]) => shed.add(box(0.45, 8, 0.45, dark, dx, 4, dz)));
        shed.add(box(24, 0.35, 12, roof, 0, 8.2, 0));
        const t = textCanvas(512, 80);
        t.ctx.fillStyle = '#1d2a33'; t.ctx.fillRect(0, 0, 512, 80);
        t.ctx.fillStyle = '#ffffff'; t.ctx.font = 'bold 40px Arial'; t.ctx.textAlign = 'center'; t.ctx.textBaseline = 'middle';
        t.ctx.fillText('UNLOADING / LOADING BAY', 256, 42); t.tex.needsUpdate = true;
        const lab = new THREE.Mesh(new THREE.PlaneGeometry(12, 1.9), new THREE.MeshBasicMaterial({ map: t.tex }));
        lab.position.set(0, 7.2, 6.05);
        shed.add(lab);
        // A paddy heap beside the bay that grows as trolleys and trucks unload.
        this.heap = new THREE.Mesh(new THREE.ConeGeometry(4, 2.5, 24), mat(0xd9b45a, { roughness: 1 }));
        this.heap.position.set(4, 1.25, -9.5);
        this.heap.scale.set(0.6, 0.6, 0.6);
        shed.add(this.heap);
        shed.position.set(Y.shed.x, 0, Y.shed.z);
        this.scene.add(shed);

        // Silos.
        const siloMat = mat(0xc9ced2, { metalness: 0.75, roughness: 0.3 });
        [[-30, -60], [-18, -60], [-6, -60], [6, -60]].forEach(([x, z]) => {
            const s = cyl(4.2, 4.2, 18, siloMat, 24); s.position.set(x, 9, z); this.scene.add(s);
            const c = new THREE.Mesh(new THREE.ConeGeometry(4.4, 3.5, 24), siloMat); c.position.set(x, 19.7, z); c.castShadow = true; this.scene.add(c);
        });
        this.scene.add(box(40, 0.6, 1, dark, -12, 21, -60));

        // Parked vehicles (static) to show a working yard.
        const parked = mat(0x8e5a2b);
        [[-75, 40], [-70, 40], [80, -55]].forEach(([x, z]) => {
            const p = new THREE.Group();
            p.add(box(2.4, 2.4, 2.2, parked, 0, 1.5, -3));
            p.add(box(2.4, 1.6, 5.5, mat(0x6b6f72), 0, 1.4, 1.2));
            p.position.set(x, 0, z);
            this.scene.add(p);
        });
    }

    buildWeighbridge() {
        const wb = Y.wb, len = wb.z1 - wb.z2;
        const g = new THREE.Group();
        // Pit frame, the steel deck with its chequer lines, and the yellow-black safety edges.
        g.add(box(wb.width + 1.2, 0.3, len + 1.2, mat(0x6f6f6a), 0, 0.1, 0));
        this.deckMat = mat(0x6d7479, { metalness: 0.75, roughness: 0.45 });
        g.add(box(wb.width, 0.32, len, this.deckMat, 0, 0.2, 0));
        for (let z = -len / 2 + 1; z < len / 2; z += 2.2) g.add(box(wb.width - 0.2, 0.02, 0.06, mat(0x50565a), 0, 0.37, z));
        const stripe = textCanvas(256, 32);
        for (let i = 0; i < 16; i++) { stripe.ctx.fillStyle = i % 2 ? '#111' : '#f5c518'; stripe.ctx.fillRect(i * 16, 0, 16, 32); }
        stripe.tex.wrapS = THREE.RepeatWrapping; stripe.tex.repeat.set(len / 2, 1); stripe.tex.needsUpdate = true;
        [-1, 1].forEach((s) => g.add(box(0.25, 0.34, len, new THREE.MeshStandardMaterial({ map: stripe.tex }), s * (wb.width / 2 + 0.15), 0.2, 0)));
        // Load cells (eight) with their indicator lamps.
        this.cellLamps = [];
        for (let i = 0; i < 4; i++) {
            [-1, 1].forEach((s) => {
                const m = new THREE.MeshStandardMaterial({ color: 0x113311, emissive: 0x22ff66, emissiveIntensity: 0.3 });
                const lamp = box(0.18, 0.12, 0.18, m, s * (wb.width / 2 + 0.55), 0.3, -len / 2 + 2.5 + i * ((len - 5) / 3));
                g.add(lamp);
                this.cellLamps.push(m);
            });
        }
        g.position.set(wb.x, 0, (wb.z1 + wb.z2) / 2);
        this.scene.add(g);
        this.deck = g;   // sinks a little under the weight on it
        // Approach markings and yellow guard posts along both sides.
        const paint = textCanvas(512, 96);
        paint.ctx.clearRect(0, 0, 512, 96); paint.ctx.fillStyle = '#f5f5f5'; paint.ctx.font = 'bold 72px Arial'; paint.ctx.textAlign = 'center'; paint.ctx.textBaseline = 'middle';
        paint.ctx.fillText('WEIGHBRIDGE', 256, 50); paint.tex.needsUpdate = true;
        const word = new THREE.Mesh(new THREE.PlaneGeometry(4.2, 0.8), new THREE.MeshBasicMaterial({ map: paint.tex, transparent: true }));
        word.rotation.x = -Math.PI / 2; word.position.set(wb.x, 0.06, wb.z1 + 9);
        this.scene.add(word);
        for (let z = wb.z2 - 2; z <= wb.z1 + 2; z += 3) {
            [-1, 1].forEach((sd) => { const post = cyl(0.09, 0.09, 1.0, mat(0xf5c518), 10); post.position.set(wb.x + sd * (wb.width / 2 + 1.0), 0.5, z); this.scene.add(post); });
        }

        // Ramps at both ends.
        const rampMat = mat(0x8f8d86);
        [wb.z1 + 1.9, wb.z2 - 1.9].forEach((z, i) => {
            const r = box(wb.width, 0.1, 2.6, rampMat, wb.x, 0.18, z);
            r.rotation.x = i === 0 ? 0.09 : -0.09;
            this.scene.add(r);
        });

        // Barrier arms at the entry (south) and exit (north).
        const barrier = (z, flip) => {
            const b = { angle: 0, target: 0 };
            const post = new THREE.Group();
            post.add(box(0.6, 1.2, 0.6, mat(0xf2c200), 0, 0.6, 0));
            const pivot = new THREE.Group();
            pivot.position.set(0, 1.05, 0);
            const arm = new THREE.Group();
            for (let i = 0; i < 6; i++) arm.add(box(0.75, 0.12, 0.12, mat(i % 2 ? 0xd82020 : 0xffffff), 0.45 + i * 0.75, 0, 0));
            pivot.add(arm);
            post.add(pivot);
            post.position.set(wb.x + (flip ? 2.9 : -2.9), 0, z);
            post.rotation.y = flip ? Math.PI : 0;
            this.scene.add(post);
            b.pivot = pivot;
            return b;
        };
        this.barriers = { entry: barrier(wb.z1 + 3.4, false), exit: barrier(wb.z2 - 3.4, true) };

        // Signals at the weighbridge entry and exit.
        const steel = mat(0x5f6b73, { metalness: 0.7, roughness: 0.35 });
        const signal = (x, z) => {
            const s = { lamps: {} };
            const grp = new THREE.Group();
            grp.add(box(0.12, 3.4, 0.12, steel, 0, 1.7, 0));
            grp.add(box(0.5, 1.2, 0.4, mat(0x1b1b1b), 0, 3.8, 0));
            [['red', 0xff2020, 4.15], ['green', 0x20e060, 3.45]].forEach(([k, c, y]) => {
                const m = new THREE.MeshStandardMaterial({ color: 0x222222, emissive: c, emissiveIntensity: 0 });
                const lamp = new THREE.Mesh(new THREE.SphereGeometry(0.18, 12, 8), m);
                lamp.position.set(0, y, 0.22);
                grp.add(lamp);
                s.lamps[k] = m;
            });
            grp.position.set(x, 0, z);
            this.scene.add(grp);
            return s;
        };
        this.wbSignals = { entry: signal(wb.x + 3.2, wb.z1 + 5.5), exit: signal(wb.x - 3.2, wb.z2 - 5.5) };
        this.wbSignals.exit.lamps.red.emissiveIntensity = 2;

        // Control room with a window to the deck, and the big LED display facing the drivers.
        const room = new THREE.Group();
        room.add(box(6, 3.4, 4.5, mat(0xeae6dc), 0, 1.7, 0));
        room.add(box(6.6, 0.3, 5.1, mat(0x8a2d1f), 0, 3.55, 0));
        room.add(box(0.05, 1.3, 3, new THREE.MeshStandardMaterial({ color: 0x9fc4d8, metalness: 0.4, roughness: 0.1 }), -3.03, 2, 0));
        room.position.set(wb.x - 9, 0, 0);
        this.scene.add(room);
        this.led = textCanvas(1024, 512);
        const ledFrame = new THREE.Group();
        ledFrame.add(box(0.2, 5.2, 0.2, steel, 0, 2.6, 0));
        ledFrame.add(box(4.7, 2.5, 0.3, mat(0x111111), 0, 5.5, 0));
        const screen = new THREE.Mesh(new THREE.PlaneGeometry(4.5, 2.25), new THREE.MeshBasicMaterial({ map: this.led.tex, toneMapped: false }));
        screen.position.set(0, 5.5, 0.16);
        ledFrame.add(screen);
        ledFrame.position.set(wb.x + 5.2, 0, wb.z1 + 1);
        ledFrame.rotation.y = -0.35;
        this.scene.add(ledFrame);
        // CCTV on poles at both ends.
        [[wb.x + 3.5, wb.z1 + 8], [wb.x - 3.5, wb.z2 - 8]].forEach(([x, z]) => {
            this.scene.add(box(0.12, 6.5, 0.12, steel, x, 3.25, z));
            this.scene.add(box(0.35, 0.3, 0.8, mat(0xeeeeee), x, 6.5, z));
        });

        this.wbState = 'EMPTY';
        this.wbVehicle = null;
        this.wbQueue = [];
        this.wbReading = 0;
        this.wbStableSince = null;
        this.wbStateAt = 0;
        this.wbMessage = '';
        this.lastLed = '';
    }

    buildSky() {
        const c = textCanvas(16, 256);
        const g = c.ctx.createLinearGradient(0, 0, 0, 256);
        g.addColorStop(0, '#5b9bd5'); g.addColorStop(0.55, '#bfdcf2'); g.addColorStop(1, '#eef5f9');
        c.ctx.fillStyle = g; c.ctx.fillRect(0, 0, 16, 256); c.tex.needsUpdate = true;
        this.sky = new THREE.Mesh(new THREE.SphereGeometry(620, 32, 16), new THREE.MeshBasicMaterial({ map: c.tex, side: THREE.BackSide, fog: false, depthWrite: false }));
        this.scene.add(this.sky);
    }

    buildSmoke() {
        const N = 220;
        const geo = new THREE.BufferGeometry();
        this.smokePos = new Float32Array(N * 3);
        this.smokeLife = new Float32Array(N);
        this.smokeVel = new Float32Array(N * 3);
        for (let i = 0; i < N; i++) this.smokePos[i * 3 + 1] = -50;
        geo.setAttribute('position', new THREE.BufferAttribute(this.smokePos, 3));
        const puff = textCanvas(64, 64);
        const grad = puff.ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
        grad.addColorStop(0, 'rgba(255,255,255,1)'); grad.addColorStop(1, 'rgba(255,255,255,0)');
        puff.ctx.fillStyle = grad; puff.ctx.fillRect(0, 0, 64, 64); puff.tex.needsUpdate = true;
        this.smoke = new THREE.Points(geo, new THREE.PointsMaterial({ color: 0x6b6b6b, size: 0.9, map: puff.tex, transparent: true, opacity: 0.3, depthWrite: false }));
        this.smoke.frustumCulled = false;
        this.scene.add(this.smoke);
        this.smokeNext = 0;
    }

    /** A puff from the exhaust now and then: more while moving off, a little while idling. */
    exhaust(v, dt) {
        if (!v.exhaustAt || !v.parts[0]) return;
        v.puff = (v.puff || 0) + dt * (v.v > 0.2 ? 14 : 4);
        while (v.puff >= 1) {
            v.puff -= 1;
            const p = v.parts[0].localToWorld(v.exhaustAt.clone());
            const i = this.smokeNext;
            this.smokeNext = (i + 1) % this.smokeLife.length;
            this.smokePos.set([p.x, p.y, p.z], i * 3);
            this.smokeVel.set([Math.sin(i * 2.1) * 0.25, 0.9 + (i % 5) * 0.08, Math.cos(i * 1.3) * 0.25], i * 3);
            this.smokeLife[i] = 1.8;
        }
    }

    updateSmoke(dt) {
        for (let i = 0; i < this.smokeLife.length; i++) {
            if (this.smokeLife[i] <= 0) continue;
            this.smokeLife[i] -= dt;
            for (let k = 0; k < 3; k++) this.smokePos[i * 3 + k] += this.smokeVel[i * 3 + k] * dt;
            if (this.smokeLife[i] <= 0) this.smokePos[i * 3 + 1] = -50;
        }
        this.smoke.geometry.attributes.position.needsUpdate = true;
    }

    /** Mirrors, mudguards, fuel tank, side windows and marker lights: what makes a vehicle read as real. */
    details(v, unit) {
        const T = v.T;
        const dark = mat(0x222428, { roughness: 0.6 });
        const chrome = mat(0xd5d9dc, { metalness: 0.95, roughness: 0.15 });
        const amber = new THREE.MeshStandardMaterial({ color: 0x664400, emissive: 0xffa000, emissiveIntensity: 0.6 });
        if (v.type === 'tractor') {
            [-1, 1].forEach((s) => {
                // Half a ring over the big rear wheel (the cylinder lies along the axle).
                const guard = new THREE.Mesh(new THREE.CylinderGeometry(1.0, 1.0, 0.55, 18, 1, true, -Math.PI / 2, Math.PI), mat(T.color, { metalness: 0.3, roughness: 0.5, side: THREE.DoubleSide }));
                guard.rotation.z = Math.PI / 2; guard.position.set(s * 0.95, 0.85, 0);
                unit.add(guard);
            });
            unit.add(box(0.5, 0.12, 0.5, dark, 0, 1.75, -0.15));                       // seat (the wheel comes with the driver)
            v.exhaustAt = new THREE.Vector3(0.35, 2.75, 2.3);
            return;
        }
        const cabZ = v.type === 'truck' ? T.wheelbase + 0.2 : T.wheelbase + 0.4;
        const cabFront = v.type === 'truck' ? T.wheelbase + 1.25 : T.wheelbase + 1.55;
        [-1, 1].forEach((s) => {
            unit.add(box(0.5, 0.05, 0.05, chrome, s * 1.45, 2.6, cabFront - 0.2));          // mirror arm
            unit.add(box(0.08, 0.5, 0.22, dark, s * 1.68, 2.45, cabFront - 0.2));           // mirror
            unit.add(box(0.5, 0.06, 1.3, dark, s * 1.0, 1.12, 0));                          // mudguard
            unit.add(box(0.25, 0.4, 0.06, dark, s * 1.0, 0.8, -1.3));                       // mud flap
        });
        const tank = cyl(0.32, 0.32, 1.3, chrome, 16); tank.rotation.x = Math.PI / 2; tank.position.set(-0.85, 0.85, T.wheelbase / 2); unit.add(tank);
        for (let i = -2; i <= 2; i++) unit.add(box(0.14, 0.08, 0.08, amber, i * 0.42, v.type === 'truck' ? 3.32 : 3.62, cabZ + 0.6));
        unit.add(box(1.1, 0.5, 0.06, mat(0x333333, { metalness: 0.6, roughness: 0.3 }), 0, 1.6, cabFront + 0.04));   // grille
        v.exhaustAt = v.type === 'truck' ? new THREE.Vector3(1.15, 3.55, T.wheelbase - 0.95) : new THREE.Vector3(1.2, 4.65, T.wheelbase - 0.85);
        if (v.type === 'truck') {
            const stack = cyl(0.08, 0.08, 2.2, chrome, 8); stack.position.set(1.15, 2.45, T.wheelbase - 0.95); unit.add(stack);
        }
    }

    buildDust() {
        const N = 260;
        const geo = new THREE.BufferGeometry();
        this.dustPos = new Float32Array(N * 3);
        this.dustLife = new Float32Array(N);
        this.dustVel = new Float32Array(N * 3);
        geo.setAttribute('position', new THREE.BufferAttribute(this.dustPos, 3));
        const puff = textCanvas(64, 64);
        const grad = puff.ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
        grad.addColorStop(0, 'rgba(255,255,255,1)'); grad.addColorStop(1, 'rgba(255,255,255,0)');
        puff.ctx.fillStyle = grad; puff.ctx.fillRect(0, 0, 64, 64); puff.tex.needsUpdate = true;
        this.dust = new THREE.Points(geo, new THREE.PointsMaterial({ color: 0xc8b48a, size: 1.6, map: puff.tex, transparent: true, opacity: 0.45, depthWrite: false }));
        this.dust.frustumCulled = false;
        this.scene.add(this.dust);
        this.dustNext = 0;
        for (let i = 0; i < N; i++) this.dustPos[i * 3 + 1] = -50;
    }

    emitDust(x, z, speed) {
        const i = this.dustNext;
        this.dustNext = (i + 1) % this.dustLife.length;
        this.dustPos[i * 3] = x + (Math.sin(this.simTime * 13 + i) * 0.6);
        this.dustPos[i * 3 + 1] = 0.3;
        this.dustPos[i * 3 + 2] = z + (Math.cos(this.simTime * 11 + i) * 0.6);
        this.dustVel[i * 3] = Math.sin(i * 1.7) * 0.4;
        this.dustVel[i * 3 + 1] = 0.6 + speed * 0.05;
        this.dustVel[i * 3 + 2] = Math.cos(i * 2.3) * 0.4;
        this.dustLife[i] = 1.6;
    }

    drawSign() {
        const { ctx, canvas, tex } = this.signTex;
        ctx.fillStyle = '#0f1a22'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ffd34d'; ctx.font = 'bold 92px Arial'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.shadowColor = '#ffb000'; ctx.shadowBlur = 18;
        ctx.fillText(this.mill, canvas.width / 2, canvas.height / 2 + 4);
        tex.needsUpdate = true;
    }

    // ------------------------------------------------------------------ vehicles

    /** Builds a vehicle's model and its place at the start of the road. */
    addVehicle(type, opts = {}) {
        const T = TYPES[type];
        const turn = this.typeTurn[type]++ % T.plates.length;
        const v = {
            id: this.nextId++, type, T, plate: opts.plate || T.plates[turn], inbound: T.inbound,
            tare: T.tare, load: T.inbound ? T.loads[turn] : 0, cargo: T.inbound ? T.loads[turn] : T.loads[turn],
            state: 'APPROACHING', path: this.pathIn, s: opts.s ?? this.spawnS(T), v: 0,
            weighings: 0, gross: null, tare_kg: null, net: null, gateCleared: { in: false, out: false }, granted: false,
            unloadT: 0, wheelAngle: 0, steer: 0, brake: false, blink: 0, blockedBy: null, history: [], since: this.simTime,
        };
        v.parts = this.vehicleModel(v);
        // Place the axles along the road behind the front one.
        const front = v.path.at(v.s);
        v.fa = front.p.clone();
        v.ra = v.path.at(v.s - T.wheelbase).p;
        if (T.trailer) {
            const dir = v.fa.clone().sub(v.ra).normalize();
            v.hitch = v.ra.clone().sub(dir.clone().multiplyScalar(T.trailer.hitch));
            v.ta = v.path.at(v.s - T.wheelbase - T.trailer.hitch - T.trailer.wheelbase).p;
        }
        this.vehicles.push(v);
        this.log(`${T.label} ${v.plate} on the road to the mill`);
        if (this.followId === null) this.followId = v.id;
        this.kinematics(v, 0);
        return v;
    }

    /** Where a new vehicle starts: on the road west of the mill, behind the last one still on it. */
    spawnS(T) {
        let s = -10;
        for (const o of this.vehicles) {
            if (o.path === this.pathIn && o.s - this.totalLength(o) - 10 < s) s = o.s - this.totalLength(o) - 10;
        }
        return s;
    }

    /**
     * A cab you can see into: solid below the windows and the roof above them, corner pillars, and tinted glass all
     * round (front and sides), so the people inside show. Its local frame is the vehicle's: +Z forward.
     */
    cabin(unit, body, c) {
        const glass = new THREE.MeshStandardMaterial({ color: 0x9cc3d8, metalness: 0.2, roughness: 0.05, transparent: true, opacity: 0.28, depthWrite: false });
        const z0 = c.z - c.d / 2, z1 = c.z + c.d / 2, mid = (c.lo + c.hi) / 2, wh = c.hi - c.lo;
        unit.add(box(c.w, c.lo - c.y0, c.d, body, 0, (c.y0 + c.lo) / 2, c.z));          // below the windows
        unit.add(box(c.w, c.y1 - c.hi, c.d, body, 0, (c.hi + c.y1) / 2, c.z));          // roof
        unit.add(box(c.w, wh, 0.08, body, 0, mid, z0 + 0.04));                           // back wall
        [-1, 1].forEach((s) => {
            unit.add(box(0.12, wh, 0.12, body, s * (c.w / 2 - 0.06), mid, z1 - 0.06));   // front pillars
            unit.add(box(0.1, wh, 0.1, body, s * (c.w / 2 - 0.05), mid, c.z - 0.15));    // door pillars
            unit.add(box(0.03, wh - 0.06, c.d - 0.3, glass, s * (c.w / 2 - 0.02), mid, c.z + 0.05));
        });
        unit.add(box(c.w - 0.22, wh - 0.06, 0.03, glass, 0, mid, z1 - 0.02));             // windscreen
        unit.add(box(c.w - 0.1, 0.35, 0.5, mat(0x2b2b2b, { roughness: 0.7 }), 0, c.lo - 0.12, z1 - 0.35));   // dashboard
    }

    /**
     * A person sitting at (x, hipY, z): shalwar kameez, a cap or turban, and if `wheel` is given, hands on a steering
     * wheel that far ahead. Returns the parts the animation moves (head, the wheel and the arms).
     */
    person(unit, x, hipY, z, o = {}) {
        const shirts = [0xeeeeee, 0x3d6e9e, 0x7a5c3e, 0x5b7f4a, 0xcfc2a4, 0x8a8f96, 0x2f4858];
        const skins = [0xc68e5c, 0xb07848, 0xd7a274, 0x9c6a3f];
        const n = Math.abs(o.shirt || 0);
        const shirt = mat(shirts[n % shirts.length], { roughness: 0.95 });
        const skin = mat(skins[n % skins.length], { roughness: 0.75 });
        const g = new THREE.Group();
        g.position.set(x, hipY, z);
        g.add(box(0.44, 0.12, 0.42, shirt, 0, 0.02, 0.12));                    // lap
        [-1, 1].forEach((s) => g.add(box(0.15, 0.5, 0.15, shirt, s * 0.11, -0.22, 0.36)));   // shins
        const torso = box(0.44, 0.58, 0.26, shirt, 0, 0.36, -0.04);
        torso.rotation.x = -0.08;
        g.add(torso);
        g.add(box(0.12, 0.1, 0.12, skin, 0, 0.7, -0.02));                      // neck
        const head = new THREE.Group();
        head.position.set(0, 0.86, -0.02);
        head.add(new THREE.Mesh(new THREE.SphereGeometry(0.13, 16, 12), skin));
        const beard = new THREE.Mesh(new THREE.SphereGeometry(0.11, 12, 8, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2), mat(0x1d1a17, { roughness: 1 }));
        beard.position.set(0, -0.02, 0.03);
        if (n % 2 === 0) head.add(beard);
        const hat = n % 3 === 0
            ? cyl(0.15, 0.15, 0.12, mat(0xf2efe6, { roughness: 1 }), 14)       // turban
            : cyl(0.135, 0.14, 0.07, mat(n % 3 === 1 ? 0xffffff : 0x40342a, { roughness: 1 }), 14);   // prayer cap
        hat.position.y = 0.11;
        head.add(hat);
        [-1, 1].forEach((s) => { const eye = box(0.03, 0.02, 0.01, mat(0x111111), s * 0.045, 0.02, 0.125); head.add(eye); });
        g.add(head);
        const parts = { group: g, head, arms: [], baseY: hipY };
        [-1, 1].forEach((s) => {
            const shoulder = new THREE.Group();
            shoulder.position.set(s * 0.27, 0.58, -0.04);
            const arm = box(0.11, 0.11, 0.48, shirt, 0, 0, 0.22);
            const hand = box(0.09, 0.09, 0.09, skin, 0, 0, 0.48);
            shoulder.add(arm); shoulder.add(hand);
            // Hands forward on the wheel, or resting on the lap.
            shoulder.rotation.x = o.wheel ? 0.55 : 1.25;
            shoulder.rotation.y = o.wheel ? -s * 0.28 : -s * 0.1;
            g.add(shoulder);
            parts.arms.push({ shoulder, s });
        });
        if (o.wheel) {
            const wheel = new THREE.Group();
            wheel.position.set(0, 0.42, o.wheel);
            const rim = new THREE.Mesh(new THREE.TorusGeometry(0.2, 0.025, 8, 20), mat(0x1b1b1b, { roughness: 0.6 }));
            wheel.add(rim);
            wheel.add(box(0.36, 0.03, 0.02, mat(0x1b1b1b)));
            wheel.rotation.x = -0.75;
            const col = box(0.05, 0.05, 0.45, mat(0x1b1b1b), 0, 0.32, o.wheel + 0.22);
            col.rotation.x = 0.8;
            g.add(col);
            g.add(wheel);
            parts.wheel = wheel;
        }
        unit.add(g);
        return parts;
    }

    vehicleModel(v) {
        const T = v.T;
        const body = mat(T.color, { metalness: 0.35, roughness: 0.45 });
        const black = mat(0x1a1a1a, { roughness: 0.9 });
        const chrome = mat(0xc9ced2, { metalness: 0.9, roughness: 0.2 });
        v.brakeMat = new THREE.MeshStandardMaterial({ color: 0x440000, emissive: 0xff1010, emissiveIntensity: 0 });
        v.blinkMat = new THREE.MeshStandardMaterial({ color: 0x553300, emissive: 0xffa000, emissiveIntensity: 0 });
        v.headMat = new THREE.MeshStandardMaterial({ color: 0xfafafa, emissive: 0xfff2c8, emissiveIntensity: 0.2 });
        v.wheels = [];
        v.steerWheels = [];
        const wheel = (r, w, group, x, y, z, steer = false) => {
            const holder = new THREE.Group();
            holder.position.set(x, y, z);
            const tyre = cyl(r, r, w, black, 18);
            tyre.rotation.z = Math.PI / 2;
            const hub = cyl(r * 0.45, r * 0.45, w + 0.02, chrome, 10);
            hub.rotation.z = Math.PI / 2;
            const spin = new THREE.Group();
            spin.add(tyre); spin.add(hub);
            // Spokes so the turning shows.
            for (let k = 0; k < 3; k++) { const sp = box(w + 0.04, r * 1.5, 0.08, chrome); sp.rotation.x = k * Math.PI / 3; spin.add(sp); }
            holder.add(spin);
            group.add(holder);
            v.wheels.push({ spin, r });
            if (steer) v.steerWheels.push(holder);
        };
        const parts = [];
        const unit = new THREE.Group();     // origin: rear axle of the driving unit, facing +Z (local)
        this.scene.add(unit);
        parts.push(unit);
        const lights = (group, zFront, zRear, w, y) => {
            [-1, 1].forEach((s) => {
                group.add(box(0.3, 0.2, 0.08, v.headMat, s * (w / 2 - 0.3), y, zFront));
                group.add(box(0.3, 0.18, 0.08, v.brakeMat, s * (w / 2 - 0.3), y, zRear));
                group.add(box(0.12, 0.12, 0.1, v.blinkMat, s * (w / 2 - 0.05), y + 0.25, zFront));
            });
        };
        if (v.type === 'tractor') {
            // Tractor: big rear wheels, small steering front wheels, bonnet, cab with roof, exhaust.
            unit.add(box(1.0, 0.9, 3.4, body, 0, 1.25, 1.2));
            unit.add(box(1.5, 0.25, 1.3, body, 0, 1.05, -0.2));
            unit.add(box(0.12, 1.7, 0.12, chrome, -0.6, 2.4, -0.6)); unit.add(box(0.12, 1.7, 0.12, chrome, 0.6, 2.4, -0.6));
            unit.add(box(0.12, 1.7, 0.12, chrome, -0.6, 2.4, 0.5)); unit.add(box(0.12, 1.7, 0.12, chrome, 0.6, 2.4, 0.5));
            unit.add(box(1.6, 0.1, 1.6, body, 0, 3.25, 0));
            unit.add(box(0.5, 0.5, 0.4, black, 0, 1.6, -0.1));
            const ex = cyl(0.07, 0.07, 1.2, chrome, 8); ex.position.set(0.35, 2.1, 2.3); unit.add(ex);
            unit.add(box(0.9, 0.5, 0.1, chrome, 0, 1.15, 2.95));
            wheel(0.85, 0.5, unit, -0.95, 0.85, 0); wheel(0.85, 0.5, unit, 0.95, 0.85, 0);
            wheel(0.48, 0.3, unit, -0.75, 0.48, T.wheelbase, true); wheel(0.48, 0.3, unit, 0.75, 0.48, T.wheelbase, true);
            lights(unit, 2.97, -0.75, 1.6, 1.3);
            // The trolley: drawbar, tipping bed with high sides, its paddy load.
            const trolley = new THREE.Group();
            this.scene.add(trolley);
            parts.push(trolley);
            const bar = box(0.12, 0.12, T.trailer.wheelbase - 2.2, chrome, 0, 0.7, T.trailer.wheelbase / 2 + 1.0);
            trolley.add(bar);
            trolley.add(box(2.1, 0.25, T.trailer.length, mat(0x333333), 0, 0.95, 0.2));
            const bedPivot = new THREE.Group();          // tips about the rear edge
            bedPivot.position.set(0, 1.1, -T.trailer.length / 2 + 0.2);
            const bed = new THREE.Group();
            bed.position.set(0, 0, T.trailer.length / 2);
            const trolleyMat = mat(0x2e6fa8, { roughness: 0.6 });
            bed.add(box(2.4, 0.12, T.trailer.length, trolleyMat, 0, 0.06, 0));
            [-1, 1].forEach((s) => bed.add(box(0.1, 1.3, T.trailer.length, trolleyMat, s * 1.2, 0.71, 0)));
            bed.add(box(2.4, 1.3, 0.1, trolleyMat, 0, 0.71, T.trailer.length / 2));
            v.tailgate = box(2.4, 1.3, 0.1, trolleyMat, 0, 0.71, -T.trailer.length / 2);
            bed.add(v.tailgate);
            v.cargoMesh = box(2.2, 1.0, T.trailer.length - 0.3, mat(0xd9b45a, { roughness: 1 }), 0, 0.62, 0);
            bed.add(v.cargoMesh);
            bedPivot.add(bed);
            trolley.add(bedPivot);
            v.bedPivot = bedPivot;
            wheel(0.55, 0.35, trolley, -1.15, 0.55, -0.55); wheel(0.55, 0.35, trolley, 1.15, 0.55, -0.55);
            wheel(0.55, 0.35, trolley, -1.15, 0.55, 0.55); wheel(0.55, 0.35, trolley, 1.15, 0.55, 0.55);
            [-1, 1].forEach((s) => trolley.add(box(0.3, 0.18, 0.08, v.brakeMat, s * 1.0, 0.95, -T.trailer.length / 2 + 0.15)));
            // The driver on the open seat, hands on the wheel.
            v.crew = [this.person(unit, 0, 1.82, -0.15, { wheel: 0.6, shirt: v.id })];
        } else if (v.type === 'truck') {
            // Cab-over truck (Hino / Isuzu style), long cargo body with high sides and a paddy load.
            this.cabin(unit, body, { w: 2.4, y0: 0.8, y1: 3.3, z: T.wheelbase + 0.2, d: 2.1, lo: 2.05, hi: 2.95 });
            // Right-hand drive (Pakistan): the driver sits on the right, the helper (cleaner) beside him.
            v.crew = [
                this.person(unit, -0.55, 1.75, T.wheelbase + 0.15, { wheel: 0.55, shirt: v.id }),
                this.person(unit, 0.6, 1.75, T.wheelbase + 0.1, { shirt: v.id + 3 }),
            ];
            unit.add(box(2.5, 0.35, 0.3, chrome, 0, 0.95, T.wheelbase + 1.35));
            unit.add(box(1.0, 0.3, T.wheelbase + 3.4, mat(0x2a2a2a), 0, 0.9, T.wheelbase / 2 - 0.9));
            const cargoBody = mat(0xd4a017, { roughness: 0.7 });
            unit.add(box(2.5, 0.2, 7.6, cargoBody, 0, 1.25, 1.0));
            [-1, 1].forEach((s) => unit.add(box(0.1, 1.6, 7.6, cargoBody, s * 1.25, 2.05, 1.0)));
            unit.add(box(2.5, 1.6, 0.1, cargoBody, 0, 2.05, -2.8));
            unit.add(box(2.5, 1.9, 0.1, cargoBody, 0, 2.2, 4.8));
            v.cargoMesh = box(2.3, 1.3, 7.3, mat(0xd9b45a, { roughness: 1 }), 0, 2.0, 1.0);
            unit.add(v.cargoMesh);
            wheel(0.52, 0.35, unit, -1.05, 0.52, T.wheelbase, true); wheel(0.52, 0.35, unit, 1.05, 0.52, T.wheelbase, true);
            [0.65, -0.65].forEach((dz) => { wheel(0.52, 0.5, unit, -1.0, 0.52, dz); wheel(0.52, 0.5, unit, 1.0, 0.52, dz); });
            lights(unit, T.wheelbase + 1.3, -2.86, 2.4, 1.2);
        } else {
            // Prime mover with a sleeper cab, fifth wheel; skeletal trailer with a 40-ft container.
            this.cabin(unit, body, { w: 2.5, y0: 0.8, y1: 3.6, z: T.wheelbase + 0.4, d: 2.3, lo: 2.2, hi: 3.2 });
            v.crew = [this.person(unit, -0.6, 1.95, T.wheelbase + 0.55, { wheel: 0.55, shirt: v.id })];
            unit.add(box(2.5, 0.35, 0.3, chrome, 0, 1.0, T.wheelbase + 1.62));
            unit.add(box(1.0, 0.3, T.wheelbase + 2.6, mat(0x2a2a2a), 0, 0.95, T.wheelbase / 2 - 0.2));
            const stack = cyl(0.1, 0.1, 2.2, chrome, 8); stack.position.set(1.2, 3.5, T.wheelbase - 0.85); unit.add(stack);
            unit.add(cyl(0.6, 0.6, 0.12, mat(0x222222), 16).translateY(1.15).translateZ(-T.trailer.hitch));
            wheel(0.52, 0.35, unit, -1.05, 0.52, T.wheelbase, true); wheel(0.52, 0.35, unit, 1.05, 0.52, T.wheelbase, true);
            wheel(0.52, 0.5, unit, -1.0, 0.52, 0); wheel(0.52, 0.5, unit, 1.0, 0.52, 0);
            lights(unit, T.wheelbase + 1.6, -0.9, 2.4, 1.25);
            const trailer = new THREE.Group();
            this.scene.add(trailer);
            parts.push(trailer);
            trailer.add(box(1.0, 0.35, T.trailer.length, mat(0x2a2a2a), 0, 1.25, T.trailer.wheelbase / 2 - 1.6));
            const can = new THREE.Group();
            can.position.set(0, 1.45, T.trailer.wheelbase / 2 - 1.6);
            const canMat = mat(0x1b5e8f, { metalness: 0.4, roughness: 0.5 });
            can.add(box(2.44, 0.12, 12.0, canMat, 0, 0.06, 0));
            can.add(box(2.44, 2.59, 0.1, canMat, 0, 1.3, 6.0));
            [-1, 1].forEach((s) => {
                const side = box(0.1, 2.59, 12.0, canMat, s * 1.17, 1.3, 0);
                can.add(side);
                for (let z = -5.6; z < 6; z += 0.5) can.add(box(0.04, 2.5, 0.06, mat(0x154a70), s * 1.24, 1.3, z));
            });
            can.add(box(2.44, 0.1, 12.0, canMat, 0, 2.57, 0));
            const label = textCanvas(512, 64);
            label.ctx.fillStyle = '#ffffff'; label.ctx.font = 'bold 40px Arial'; label.ctx.textAlign = 'center'; label.ctx.textBaseline = 'middle';
            label.ctx.fillText(this.mill, 256, 34); label.tex.needsUpdate = true;
            [-1, 1].forEach((s) => {
                const l = new THREE.Mesh(new THREE.PlaneGeometry(7, 0.9), new THREE.MeshBasicMaterial({ map: label.tex, transparent: true }));
                l.position.set(s * 1.26, 1.6, 0); l.rotation.y = s * Math.PI / 2;
                can.add(l);
            });
            // Two rear doors that open in the loading bay.
            v.doors = [];
            [-1, 1].forEach((s) => {
                const hinge = new THREE.Group();
                hinge.position.set(s * 1.2, 1.3, -6.0);
                const door = box(1.2, 2.55, 0.08, canMat, -s * 0.6, 0, 0);
                hinge.add(door);
                can.add(hinge);
                v.doors.push({ hinge, s });
            });
            v.cargoMesh = box(2.2, 2.2, 11.4, mat(0xe8dcc0), 0, 1.25, 0.2);
            v.cargoMesh.scale.set(1, 1, 0.001);
            v.cargoMesh.visible = false;
            can.add(v.cargoMesh);
            trailer.add(can);
            [0.6, 0, -0.6].forEach((dz) => { wheel(0.52, 0.5, trailer, -1.0, 0.52, dz); wheel(0.52, 0.5, trailer, 1.0, 0.52, dz); });
            [-1, 1].forEach((s) => trailer.add(box(0.3, 0.18, 0.08, v.brakeMat, s * 1.0, 1.1, -T.trailer.rear - 0.1)));
        }
        // Number plate on the front.
        const plate = textCanvas(256, 64);
        plate.ctx.fillStyle = '#f7f7f2'; plate.ctx.fillRect(0, 0, 256, 64);
        plate.ctx.fillStyle = '#111'; plate.ctx.font = 'bold 40px Arial'; plate.ctx.textAlign = 'center'; plate.ctx.textBaseline = 'middle';
        plate.ctx.fillText(v.plate, 128, 34); plate.tex.needsUpdate = true;
        const pm = new THREE.Mesh(new THREE.PlaneGeometry(0.9, 0.22), new THREE.MeshBasicMaterial({ map: plate.tex }));
        const zFront = v.type === 'tractor' ? 3.0 : (v.type === 'truck' ? T.wheelbase + 1.52 : T.wheelbase + 1.79);
        pm.position.set(0, v.type === 'tractor' ? 0.95 : 0.7, zFront);
        unit.add(pm);
        if (v.type === 'tractor') {
            v.cargoMesh.userData.full = 1;
        }
        this.details(v, unit);
        return parts;
    }

    /** Where the axles are: the front axle on the road, the others following it (no sliding). */
    kinematics(v, ds) {
        const T = v.T;
        const front = v.path.at(v.s);
        const prevF = v.fa.clone();
        v.fa = front.p.clone();
        let dir = v.fa.clone().sub(v.ra);
        if (dir.lengthSq() < 1e-6) dir = front.d.clone();
        dir.normalize();
        v.ra = v.fa.clone().sub(dir.clone().multiplyScalar(T.wheelbase));
        v.dir = dir;
        // Steering: the angle between the road ahead and the body.
        const ahead = v.path.at(v.s + 2).d;
        const steer = Math.atan2(dir.x * ahead.y - dir.y * ahead.x, dir.dot(ahead));
        v.steer += (clamp(steer * 1.8, -0.6, 0.6) - v.steer) * 0.2;
        if (T.trailer) {
            v.hitch = v.ra.clone().sub(dir.clone().multiplyScalar(T.trailer.hitch));
            let tdir = v.hitch.clone().sub(v.ta);
            if (tdir.lengthSq() < 1e-6) tdir = dir.clone();
            tdir.normalize();
            v.ta = v.hitch.clone().sub(tdir.clone().multiplyScalar(T.trailer.wheelbase));
            v.tdir = tdir;
        }
        // Wheels turn by the distance travelled.
        v.wheelAngle += ds;
        v.travelled = (v.travelled || 0) + ds;
        return prevF.distanceTo(v.fa);
    }

    /** The bumper at the front, and every point of the body (for the sensors and the gap to the next vehicle). */
    footprint(v) {
        const T = v.T, pts = [];
        const add = (center, dir, len, width, back) => {
            const n = v2(-dir.y, dir.x);
            for (const a of [-1, 0, 1]) for (const b of [-1, 1]) {
                const along = (a + 1) / 2 * len - back;
                pts.push(center.clone().add(dir.clone().multiplyScalar(along)).add(n.clone().multiplyScalar(b * width / 2)));
            }
        };
        if (v.type === 'tractor') add(v.ra, v.dir, T.wheelbase + T.front + 0.8, T.width, 0.8);
        else if (v.type === 'truck') add(v.ra, v.dir, T.wheelbase + T.front + (T.rearOverhang || 2), T.width, T.rearOverhang || 2);
        else add(v.ra, v.dir, T.wheelbase + T.front + 1, T.width, 1);
        if (T.trailer) add(v.ta, v.tdir, T.trailer.length, 2.5, T.trailer.rear);
        return pts;
    }

    bumper(v) {
        return v.fa.clone().add(v.dir.clone().multiplyScalar(v.T.front));
    }

    totalLength(v) {
        const T = v.T;
        if (!T.trailer) return T.front + T.wheelbase + (T.rearOverhang || 2);
        return T.front + T.wheelbase + T.trailer.hitch + T.trailer.wheelbase + T.trailer.rear;
    }

    axlePoints(v) {
        return v.T.axles.map((a) => {
            const base = a.part === 0 ? v.ra : v.ta, dir = a.part === 0 ? v.dir : v.tdir;
            return { p: base.clone().add(dir.clone().multiplyScalar(a.at)), share: a.share };
        });
    }

    onPlatform(p, margin = 0) {
        const wb = Y.wb;
        return Math.abs(p.x - wb.x) <= wb.width / 2 + margin && p.y <= wb.z1 + margin && p.y >= wb.z2 - margin;
    }

    weightOf(v) {
        return v.tare + v.load;
    }

    // ------------------------------------------------------------------ the gates

    setSignal(g, color) {
        if (g.signal === color) return;
        g.signal = color;
        for (const k of ['red', 'yellow', 'green']) g.lamps[k].emissiveIntensity = k === color ? 2.6 : 0;
    }

    inRect(p, r) {
        return p.x >= r.x1 && p.x <= r.x2 && p.y >= r.z1 && p.y <= r.z2;
    }

    zoneOccupied(rect) {
        return this.vehicles.some((v) => this.footprint(v).some((p) => this.inRect(p, rect)));
    }

    /** Asked from the buttons: open, or close (never onto a vehicle). */
    commandGate(name, open) {
        const g = this.gates[name];
        if (open) {
            g.manualOpen = true;
            if (g.state !== 'OPEN') g.state = 'OPENING';
            this.log(`${name === 'in' ? 'Entry' : 'Exit'} gate: opened by the operator`);
            return;
        }
        if (this.zoneOccupied(g.passage)) {
            this.toast(`The ${name === 'in' ? 'entry' : 'exit'} gate cannot close: a vehicle is in the gate.`);
            this.log(`${name === 'in' ? 'Entry' : 'Exit'} gate: close refused, vehicle in the safety zone`);
            return;
        }
        g.manualOpen = false;
        if (g.state !== 'CLOSED') g.state = 'CLOSING';
        this.log(`${name === 'in' ? 'Entry' : 'Exit'} gate: closed by the operator`);
    }

    updateGate(g, dt) {
        const occupied = this.zoneOccupied(g.passage);
        // A vehicle waiting at the line is identified first (ANPR), then let in (automatic mode).
        if (g.request && (g.request.gateCleared[g.name] || !this.vehicles.includes(g.request))) g.request = null;
        const waiting = this.vehicles.find((v) => v.gateWait === g.name);
        if (waiting && !g.request) {
            g.request = waiting;
            g.scanUntil = this.simTime + 2.2;
            this.log(`${g.name === 'in' ? 'Entry' : 'Exit'} camera: scanning ${waiting.plate}`);
        }
        if (g.request && this.simTime >= g.scanUntil && !g.request.authorised) {
            g.request.authorised = true;
            this.log(`${g.name === 'in' ? 'Entry' : 'Exit'} camera: ${g.request.plate} · ${g.request.T.label} · ${g.name === 'in' ? 'authorised' : 'slip checked'}`);
            if (this.auto && g.state !== 'OPEN') g.state = 'OPENING';
        }
        g.flash.intensity = g.request && this.simTime < g.scanUntil ? (Math.sin(this.simTime * 30) > 0.6 ? 6 : 0) : 0;

        // Safety: never close onto a vehicle; stop and open again.
        if (g.state === 'CLOSING' && occupied) {
            g.state = 'OPENING';
            this.log(`${g.name === 'in' ? 'Entry' : 'Exit'} gate: SAFETY STOP — vehicle in the gate, opening again`);
        }
        // Motion with acceleration and deceleration (smooth start and stop).
        const target = g.state === 'OPENING' || g.state === 'OPEN' ? 1 : 0;
        const dist = target - g.pos;
        const maxV = 0.22;
        const want = clamp(dist * 1.6, -maxV, maxV);
        g.vel += clamp(want - g.vel, -0.35 * dt, 0.35 * dt);
        g.pos = clamp(g.pos + g.vel * dt, 0, 1);
        if (g.state === 'OPENING' && g.pos > 0.995) { g.state = 'OPEN'; g.pos = 1; g.vel = 0; }
        if (g.state === 'CLOSING' && g.pos < 0.005) { g.state = 'CLOSED'; g.pos = 0; g.vel = 0; this.log(`${g.name === 'in' ? 'Entry' : 'Exit'} gate: closed and locked`); }
        g.leaf.position.x = g.closedX + (g.openX - g.closedX) * g.pos;

        // Close once the whole vehicle (trolley or trailer too) has cleared the passage.
        if (g.state === 'OPEN' && !g.manualOpen) {
            const passing = g.request && g.request.gatePassing === g.name;
            if (!occupied && !waiting && (!g.request || g.request.gateCleared[g.name])) {
                if (!g.clearSince) g.clearSince = this.simTime;
                if (this.auto && this.simTime - g.clearSince > 1.2) { g.state = 'CLOSING'; g.request = null; }
            } else g.clearSince = 0;
            if (passing) g.clearSince = 0;
        }
        if (g.request && g.request.gateCleared[g.name] && !this.auto && g.state === 'OPEN') g.request = null;
        this.setSignal(g, g.state === 'OPEN' ? 'green' : (g.state === 'OPENING' || g.state === 'CLOSING' ? 'yellow' : 'red'));
    }

    // ------------------------------------------------------------------ the weighbridge

    setWb(state) {
        if (this.wbState !== state) { this.wbState = state; this.wbStateAt = this.simTime; }
    }

    updateWeighbridge(dt) {
        const wb = Y.wb;
        const v = this.wbVehicle;
        // The model's reading: each axle's share of the vehicle's weight, for the axles on the deck; while it settles,
        // a damped swing that dies away; while it moves, a small bounce.
        let reading = 0;
        for (const other of this.vehicles) {
            for (const a of this.axlePoints(other)) if (this.onPlatform(a.p)) reading += this.weightOf(other) * a.share;
        }
        if (v && ['STABILIZING', 'STABLE', 'RECORDING'].includes(this.wbState)) {
            const t = this.simTime - this.wbStateAt;
            if (this.wbState === 'STABILIZING') reading += this.weightOf(v) * 0.006 * Math.exp(-t / 1.1) * Math.sin(t * 8.2);
        } else if (v && v.v > 0.05) {
            reading += this.weightOf(v) * 0.004 * Math.sin(this.simTime * 11) * Math.min(1, v.v);
        }
        this.wbReading = Math.max(0, Math.round(reading / 10) * 10);   // the indicator's 10 kg step

        if (!v) {
            // The first in the queue goes on when the deck is empty.
            const next = this.wbQueue[0];
            const deckClear = !this.vehicles.some((o) => this.footprint(o).some((p) => this.onPlatform(p)));
            if (next && deckClear && this.wbState === 'EMPTY') {
                this.wbVehicle = next;
                next.granted = true;
                next.state = 'ON_SCALE';
                this.barriers.entry.target = 1;
                this.setWb('VEHICLE_ENTERING');
                this.log(`Weighbridge: ${next.plate} called on`);
            }
        } else {
            const axles = this.axlePoints(v);
            const allOn = axles.every((a) => this.onPlatform(a.p));
            const bodyOn = this.footprint(v).every((p) => this.onPlatform(p, 0.6));
            const others = this.vehicles.some((o) => o !== v && this.footprint(o).some((p) => this.onPlatform(p)));
            if (this.wbState === 'VEHICLE_ENTERING' && allOn) this.setWb('POSITIONING');
            // The entry barrier comes down once the vehicle has cleared it.
            if (this.barriers.entry.target === 1 && this.footprint(v).every((p) => p.y < wb.z1 + 2.6)) this.barriers.entry.target = 0;
            if (this.wbState === 'POSITIONING' && v.v < 0.01 && v.atStop === 'scale') { this.setWb('STABILIZING'); v.state = 'WEIGHING'; }
            if (this.wbState === 'STABILIZING') {
                const t = this.simTime - this.wbStateAt;
                const settled = this.weightOf(v) * 0.006 * Math.exp(-t / 1.1) < 10;
                const ok = v.v < 0.01 && allOn && bodyOn && !others && this.wbReading <= this.capacity;
                if (ok && settled) { if (!this.wbStableSince) this.wbStableSince = this.simTime; } else this.wbStableSince = null;
                if (this.wbStableSince && this.simTime - this.wbStableSince > 1.4) this.setWb('STABLE');
            }
            if (this.wbState === 'STABLE' && this.simTime - this.wbStateAt > 1.2) {
                this.setWb('RECORDING');
                this.record(v);
            }
            if (this.wbState === 'RECORDING' && this.simTime - this.wbStateAt > 1.8) {
                this.setWb('VEHICLE_EXITING');
                this.barriers.exit.target = 1;
                v.state = 'WEIGHED';
                v.released = true;
            }
            if (this.wbState === 'VEHICLE_EXITING') {
                const gone = this.footprint(v).every((p) => p.y < wb.z2 - 4.2 || Math.abs(p.x - wb.x) > 4);
                if (gone) {
                    this.barriers.exit.target = 0;
                    this.wbQueue.shift();
                    this.wbVehicle = null;
                    v.granted = false;
                    v.released = false;
                    this.setWb('EMPTY');
                    this.log(`Weighbridge: free for the next vehicle`);
                }
            }
        }
        // Barriers swing up and down.
        for (const b of Object.values(this.barriers)) {
            b.angle += clamp(b.target - b.angle, -dt * 1.4, dt * 1.4);
            b.pivot.rotation.z = b.angle * (Math.PI / 2 - 0.05);
        }
        const entryGreen = this.wbState === 'VEHICLE_ENTERING' && this.barriers.entry.angle > 0.98;
        this.wbSignals.entry.lamps.green.emissiveIntensity = entryGreen ? 2.6 : 0;
        this.wbSignals.entry.lamps.red.emissiveIntensity = entryGreen ? 0 : 2.6;
        const exitGreen = this.wbState === 'VEHICLE_EXITING' && this.barriers.exit.angle > 0.98;
        this.wbSignals.exit.lamps.green.emissiveIntensity = exitGreen ? 2.6 : 0;
        this.wbSignals.exit.lamps.red.emissiveIntensity = exitGreen ? 0 : 2.6;
        // Load-cell lamps: amber while it settles, green when steady.
        const tone = ['STABILIZING', 'VEHICLE_ENTERING', 'POSITIONING'].includes(this.wbState) ? 0xffb000 : 0x22ff66;
        for (const m of this.cellLamps) { m.emissive.setHex(tone); m.emissiveIntensity = this.wbReading > 0 ? 1.4 : 0.3; }
    }

    /** First weight or second weight, kept with the vehicle; the second gives the net weight and the slip. */
    record(v) {
        const kg = this.wbReading;
        v.weighings++;
        const first = v.weighings === 1;
        // Inbound: loaded first (gross), empty after unloading (tare). Outbound: empty first, loaded after.
        if (v.inbound === first) v.gross = kg; else v.tare_kg = kg;
        const kind = v.inbound === first ? 'GROSS' : 'TARE';
        this.wbMessage = `${kind} WEIGHT: ${fmtKg(kg)}`;
        this.log(`Weighbridge: ${v.plate} ${kind.toLowerCase()} ${fmtKg(kg)} recorded (simulation)`);
        if (v.gross !== null && v.tare_kg !== null) {
            v.net = v.gross - v.tare_kg;
            v.slipNo = 'SIM-' + String(this.completed + 1).padStart(4, '0');
            this.completed++;
            this.history.unshift({ plate: v.plate, type: v.T.label, gross: v.gross, tare: v.tare_kg, net: v.net, slip: v.slipNo, inbound: v.inbound, at: this.clockText() });
            this.history = this.history.slice(0, 12);
            this.lastSlip = this.history[0];
            this.log(`Weighbridge: ${v.plate} net ${fmtKg(v.net)} · slip ${v.slipNo}`);
        }
    }

    // ------------------------------------------------------------------ moving the vehicles

    stopsAhead(v) {
        const stops = [];
        const T = v.T;
        const len = this.totalLength(v);
        const sAt = (x, z) => v.path.sOf(x, z, Math.max(0, v.s - 30)) - T.front;
        if (v.path === this.pathIn) {
            if (!v.gateCleared.in) stops.push({ s: sAt(Y.gateIn.x, Y.gateIn.x === -14 ? 57 : 57), kind: 'gate-in', hold: this.gates.in.state !== 'OPEN' || !v.authorised });
            if (v.weighings === 0) {
                stops.push({ s: sAt(Y.wb.x, Y.wbStopZ), kind: 'wb-line', hold: !v.granted || this.barriers.entry.angle < 0.98 });
                stops.push({ s: sAt(Y.wb.x, (Y.wb.z1 + Y.wb.z2) / 2 - len / 2 + 0.2), kind: 'scale', hold: !v.released });
            }
            stops.push({ s: sAt(Y.shed.x + len / 2 - 2, Y.shed.z), kind: 'shed', hold: v.state !== 'DONE_BAY' });
        } else {
            if (v.weighings === 1) {
                stops.push({ s: sAt(Y.wb.x, Y.wbStopZ), kind: 'wb-line', hold: !v.granted || this.barriers.entry.angle < 0.98 });
                stops.push({ s: sAt(Y.wb.x, (Y.wb.z1 + Y.wb.z2) / 2 - len / 2 + 0.2), kind: 'scale', hold: !v.released });
            }
            if (!v.gateCleared.out) stops.push({ s: sAt(Y.gateOut.x, 43), kind: 'gate-out', hold: this.gates.out.state !== 'OPEN' || !v.authorised });
        }
        return stops.filter((st) => st.hold && st.s > v.s - 0.5).sort((a, b) => a.s - b.s);
    }

    /** The nearest vehicle ahead in the lane: the gap to keep. Ties (each sees the other) go to the older vehicle. */
    gapAhead(v) {
        const front = this.bumper(v);
        let best = Infinity, by = null;
        for (const o of this.vehicles) {
            if (o === v) continue;
            if (o.blockedBy === v.id && o.id > v.id) continue;
            for (const p of this.footprint(o)) {
                const rel = p.clone().sub(front);
                const fwd = rel.dot(v.dir);
                const lat = Math.abs(rel.x * v.dir.y - rel.y * v.dir.x);
                if (fwd > 0 && fwd < 40 && lat < 2.0 && fwd < best) { best = fwd; by = o; }
            }
        }
        return { dist: best, by };
    }

    updateVehicle(v, dt) {
        const T = v.T;
        // Speed limit where it is: the public road, the yard, or on and near the weighbridge.
        const fp = this.bumper(v);
        let limit = fp.y > Y.wallS + 6 ? LIMITS.road : LIMITS.yard;
        if (Math.abs(fp.x - Y.wb.x) < 3 && fp.y < Y.wbStopZ + 8 && fp.y > Y.wb.z2 - 6) limit = LIMITS.scale;

        // Stop where it must: the gate line, the weighbridge line, the middle of the deck, the bay; and the gap ahead.
        const stops = this.stopsAhead(v);
        let room = Infinity;
        v.atStop = null;
        if (stops.length) {
            room = stops[0].s - v.s;
            if (room < 0.25) v.atStop = stops[0].kind === 'scale' ? 'scale' : stops[0].kind;
        }
        const gap = this.gapAhead(v);
        v.blockedBy = gap.by && gap.dist < 12 ? gap.by.id : null;
        room = Math.min(room, gap.dist - 4.5);
        const want = Math.min(limit, Math.sqrt(Math.max(0, 2 * BRAKE * Math.max(0, room - 0.15))));
        const accel = want > v.v ? ACCEL : -BRAKE * 1.5;
        const prevV = v.v;
        v.v = clamp(v.v + clamp(want - v.v, -BRAKE * 1.6 * dt, ACCEL * dt), 0, limit);
        if (room <= 0.02) v.v = 0;
        const ds = v.v * dt;
        v.s += ds;
        this.kinematics(v, ds);
        v.brake = v.v < prevV - 0.01 * dt || (v.v < 0.05 && stops.length > 0);
        if (v.v > 2.5 && Math.sin(this.simTime * 37 + v.id) > 0.3) {
            const back = v.T.trailer ? v.ta : v.ra;
            this.emitDust(back.x, back.y, v.v);
        }

        // Gate lines.
        const atGate = v.atStop === 'gate-in' || v.atStop === 'gate-out';
        if (atGate) { v.gateWait = v.atStop === 'gate-in' ? 'in' : 'out'; v.state = 'WAITING'; }
        else if (v.gateWait && v.v > 0.1) { v.gatePassing = v.gateWait; v.gateWait = null; v.state = v.gatePassing === 'in' ? 'ENTERING' : 'EXITING'; }
        if (v.gatePassing) {
            const g = this.gates[v.gatePassing];
            const pts = this.footprint(v);
            const beyond = v.gatePassing === 'in' ? pts.every((p) => p.y < g.passage.z1) : pts.every((p) => p.y > g.passage.z2);
            if (beyond) {
                v.gateCleared[v.gatePassing] = true;
                if (v.gatePassing === 'in') v.state = 'QUEUED';
                v.gatePassing = null;
                v.authorised = false;
            }
        }
        // Joining the weighbridge queue (first come, first served).
        const needs = (v.path === this.pathIn && v.weighings === 0) || (v.path === this.pathOut && v.weighings === 1);
        if (needs && !this.wbQueue.includes(v) && fp.y < 32 && Math.abs(fp.x - Y.wb.x) < 4 && fp.y > Y.wb.z1) {
            this.wbQueue.push(v);
            if (v.state !== 'WAITING') v.state = 'QUEUED';
        }
        if (v.state === 'WEIGHED' && v.v > 0.3) v.state = v.path === this.pathIn ? 'DEPARTING' : 'EXITING';

        // The bay: unload paddy (tipping trolley / labour on the truck) or load the container.
        if (v.atStop === 'shed' && v.state !== 'UNLOADING' && v.state !== 'LOADING' && v.state !== 'DONE_BAY') {
            v.state = v.inbound ? 'UNLOADING' : 'LOADING';
            v.unloadT = 0;
            this.log(`Bay: ${v.plate} ${v.inbound ? 'unloading paddy' : 'loading rice'}`);
        }
        if (v.state === 'UNLOADING' || v.state === 'LOADING') this.bayWork(v, dt);

        // Lights: brake while slowing or standing at a line; the indicator in a turn.
        v.brakeMat.emissiveIntensity = v.brake ? 2.2 : (this.night ? 0.5 : 0);
        v.blink += dt;
        v.blinkMat.emissiveIntensity = Math.abs(v.steer) > 0.15 && Math.sin(v.blink * 9) > 0 ? 2.4 : 0;
        v.headMat.emissiveIntensity = this.night ? 2.5 : 0.2;

        // Leaving: off the road beyond the mill.
        if (v.path === this.pathOut && v.s > v.path.length + 25) {
            v.state = 'COMPLETED';
            this.removeVehicle(v);
        }
        this.place(v);
    }

    bayWork(v, dt) {
        const dur = 12;
        v.unloadT += dt;
        const k = clamp(v.unloadT / dur, 0, 1);
        if (v.type === 'tractor') {
            // The bed tips up, the paddy runs out, the bed comes down.
            const tip = k < 0.25 ? k / 0.25 : (k > 0.8 ? (1 - k) / 0.2 : 1);
            v.bedPivot.rotation.x = -tip * 0.6;
            v.tailgate.rotation.x = tip * 0.9;
            const left = 1 - clamp((k - 0.2) / 0.55, 0, 1);
            v.cargoMesh.scale.set(1, Math.max(0.001, left), 1);
            v.cargoMesh.visible = left > 0.01;
            v.load = Math.round(v.cargo * left);
        } else if (v.type === 'truck') {
            const left = 1 - k;
            v.cargoMesh.scale.set(1, Math.max(0.001, left), 1);
            v.cargoMesh.position.y = 1.35 + 0.65 * left;
            v.cargoMesh.visible = left > 0.01;
            v.load = Math.round(v.cargo * left);
        } else {
            // Container: doors open, cartons go in, doors close.
            const open = k < 0.15 ? k / 0.15 : (k > 0.85 ? (1 - k) / 0.15 : 1);
            for (const d of v.doors) d.hinge.rotation.y = d.s * open * 2.6;
            const filled = clamp((k - 0.15) / 0.7, 0, 1);
            v.cargoMesh.visible = filled > 0.01;
            v.cargoMesh.scale.set(1, 1, Math.max(0.001, filled));
            v.cargoMesh.position.z = 0.2 + 5.7 * (1 - filled);
            v.load = Math.round(v.cargo * filled);
        }
        if (v.inbound) this.heap.scale.setScalar(Math.min(1.8, this.heap.scale.x + dt * 0.012));
        if (k >= 1) {
            v.state = 'DONE_BAY';
            this.log(`Bay: ${v.plate} ${v.inbound ? 'unloaded' : 'loaded'}, back to the weighbridge`);
            // Continue on the way out: round the yard, the second weight, the exit gate.
            const len = this.pathOut.sOf(v.fa.x, v.fa.y);
            v.path = this.pathOut;
            v.s = len;
            v.state = 'RETURNING';
        }
    }

    place(v) {
        const T = v.T;
        const [unit, trailer] = v.parts;
        const heading = Math.atan2(v.dir.x, v.dir.y);
        const idle = v.v < 0.05 ? Math.sin(this.simTime * 42 + v.id) * 0.012 : Math.sin(v.travelled * 1.7) * 0.01;   // engine shake, road
        unit.position.set(v.ra.x, idle, v.ra.y);
        unit.rotation.set(0, heading, 0);
        for (const w of v.wheels) w.spin.rotation.x = v.wheelAngle / w.r;
        for (const s of v.steerWheels) s.rotation.y = v.steer;
        // The crew: the driver turns the wheel and looks where he steers; stopped, they glance about (at the cabin).
        for (const [i, c] of (v.crew || []).entries()) {
            const glance = v.v < 0.05 ? Math.sin(this.simTime * 0.5 + v.id * 1.3 + i * 2) * 0.7 : 0;
            c.head.rotation.y += ((c.wheel ? v.steer * 0.9 : v.steer * 0.4) + glance - c.head.rotation.y) * 0.08;
            c.head.rotation.x = Math.sin(this.simTime * 1.1 + i + v.id) * 0.04;
            c.group.position.y = c.baseY + idle * 0.6 + (v.v > 0.05 ? Math.abs(Math.sin(v.travelled * 2.3 + i)) * 0.012 : 0);
            if (c.wheel) {
                c.wheel.rotation.z = -v.steer * 4;
                for (const a of c.arms) a.shoulder.rotation.x = 0.55 + a.s * v.steer * 0.35;
            }
        }
        if (trailer) {
            trailer.position.set(v.ta.x, 0, v.ta.y);
            trailer.rotation.set(0, Math.atan2(v.tdir.x, v.tdir.y), 0);
        }
    }

    removeVehicle(v) {
        for (const p of v.parts) {
            this.scene.remove(p);
            p.traverse((o) => { if (o.geometry) o.geometry.dispose(); });
        }
        this.vehicles = this.vehicles.filter((o) => o !== v);
        this.wbQueue = this.wbQueue.filter((o) => o !== v);
        if (this.followId === v.id) this.followId = this.vehicles[0]?.id ?? null;
        this.log(`${v.T.label} ${v.plate} left the mill`);
    }

    // ------------------------------------------------------------------ live mode (the mill's weighbridge)

    /**
     * Live: while there is weight on the weighbridge a vehicle stands on the deck. It drives on from the stop line when
     * the weight comes, and drives off when it goes. Its kind follows the form (a tractor trolley, a container, else a
     * truck) and only illustrates: the figure shown is always the weighbridge's own reading.
     */
    liveKind() {
        const board = this.root.closest('.fi-wi-widget, .fi-section, body') || document;
        const item = (board.querySelector('input[wire\\:model\\.blur^="item."]')?.value || '').toLowerCase();
        const party = (board.querySelector('input[wire\\:model\\.blur^="party."]')?.value || '').toLowerCase();
        const kind = (board.querySelector('[aria-label="Inbound or outbound"] [aria-checked="true"]')?.textContent || '').toLowerCase();
        if (/tractor|trolley|ٹرالی|ٹریکٹر/.test(item + ' ' + party)) return 'tractor';
        if (/container|export/.test(item) || (kind.includes('outbound') && /rice|چاول/.test(item))) return 'container';
        return 'truck';
    }

    /**
     * The weighing form beside the view, read a few times a second: the vehicle number once it is finished (the box
     * left, or not changed for a moment), and its kind from the details. A number already weighed and recorded no
     * longer calls a vehicle up.
     */
    readForm() {
        const board = this.root.closest('.fi-wi-widget, .fi-section, body') || document;
        const input = board.querySelector('input[wire\\:model\\.blur^="plate."]');
        const plate = input ? input.value.trim().toUpperCase() : '';
        if (plate !== this.form.typed) { this.form.typed = plate; this.form.changedAt = this.simTime; }
        const settled = !input || document.activeElement !== input || this.simTime - this.form.changedAt > 1.5;
        if (plate !== this.form.recorded) this.form.recorded = null;
        this.form.plate = settled && plate.length >= 3 && plate !== this.form.recorded ? plate : '';
        this.form.kind = this.liveKind();
    }

    /** A live vehicle at the start of the approach road (from the form) or at the stop line (weight came first). */
    spawnLive(type, plate, atStopLine) {
        const wb = Y.wb;
        const v = this.addVehicle(type, { plate: (plate || 'ON SCALE').slice(0, 12), s: 0 });
        this.vehicles = this.vehicles.filter((o) => o !== v);   // moved here, not by the yard
        v.path = new Path([[wb.x, 70], [wb.x, -80]]);
        v.s = atStopLine ? v.path.sOf(wb.x, Y.wbStopZ + 2) : this.totalLength(v) + 1;
        v.ra = v.path.at(v.s - v.T.wheelbase).p;
        v.fa = v.path.at(v.s).p;
        if (v.T.trailer) v.ta = v.path.at(v.s - v.T.wheelbase - v.T.trailer.hitch - v.T.trailer.wheelbase).p;
        v.stopS = v.path.sOf(wb.x, (wb.z1 + wb.z2) / 2 - this.totalLength(v) / 2 + 0.2);
        v.liveState = 'arriving';
        v.livePlate = plate;
        this.kinematics(v, 0);
        this.liveTruck = v;
        this.log(`${v.T.label} ${v.plate} called to the weighbridge`);
        return v;
    }

    updateLive(dt) {
        const kg = this.live.live && this.live.kg !== null ? this.live.kg : 0;
        const weighed = kg >= 300;
        this.formTick = (this.formTick || 0) - dt;
        if (this.formTick <= 0) { this.readForm(); this.formTick = 0.25; }
        const called = this.form.plate !== '';
        const present = weighed || called;
        const wb = Y.wb;
        let v = this.liveTruck;
        // Details changed while it is still only called up (no weight on it): it turns back, or becomes the right kind.
        if (v && !weighed && v.liveState !== 'leaving') {
            if (called && v.livePlate && v.livePlate !== this.form.plate) { v.liveState = 'leaving'; this.barriers.exit.target = 1; }
            else if (called && v.type !== this.form.kind && v.s < v.stopS - 1) {
                const { s, liveState } = v;
                for (const p of v.parts) this.scene.remove(p);
                v = this.spawnLive(this.form.kind, this.form.plate, false);
                v.s = s; v.ra = v.path.at(s - v.T.wheelbase).p; v.fa = v.path.at(s).p;
                if (v.T.trailer) v.ta = v.path.at(s - v.T.wheelbase - v.T.trailer.hitch - v.T.trailer.wheelbase).p;
                v.liveState = liveState;
                this.kinematics(v, 0);
            }
        }
        if (present && !this.liveTruck) {
            v = this.spawnLive(called ? this.form.kind : this.liveKind(), called ? this.form.plate : this.live.plate, !called);
            if (called) this.toast(`${v.plate} — driving onto the weighbridge`);
        }
        if (v && v.livePlate === '' && called) { v.livePlate = this.form.plate; }
        if (v) {
            const keep = weighed || (called && (!v.livePlate || v.livePlate === this.form.plate));
            if (!keep && v.liveState !== 'leaving') { v.liveState = 'leaving'; this.barriers.exit.target = 1; this.leftAt = null; }
            if (keep && v.liveState === 'leaving' && v.s < v.stopS + 4) v.liveState = 'arriving';
            if (v.liveState === 'arriving' && !v.entryOpened && this.bumper(v).y < wb.z1 + 14) { this.barriers.entry.target = 1; v.entryOpened = true; }
            // It waits at the main gate until it is open; on the road and in the yard it drives at yard speed, and
            // creeps onto the platform.
            const gateS = v.path.sOf(Y.gateIn.x, Y.wallS + 7);
            const atGate = v.liveState !== 'leaving' && v.s <= gateS + 0.5 && this.gates.in.state !== 'OPEN';
            const target = v.liveState === 'leaving' ? v.path.length + 30 : (atGate ? gateS : v.stopS);
            const room = target - v.s;
            const limit = v.liveState === 'leaving' ? LIMITS.scale * 2.2 : (this.bumper(v).y > Y.wb.z1 + 12 ? LIMITS.yard : LIMITS.scale);
            const want = Math.min(limit, Math.sqrt(Math.max(0, 2 * BRAKE * Math.max(0, room - 0.05))));
            const prev = v.v;
            v.v = clamp(v.v + clamp(want - v.v, -BRAKE * 1.6 * dt, ACCEL * dt), 0, 12);
            if (room <= 0.02) v.v = 0;
            v.s += v.v * dt;
            this.kinematics(v, v.v * dt);
            v.brake = v.v < prev - 0.01 * dt || (v.v < 0.05 && v.liveState !== 'leaving');
            v.brakeMat.emissiveIntensity = v.brake ? 2.2 : 0;
            v.headMat.emissiveIntensity = this.night ? 2.5 : 0.2;
            if (v.liveState === 'arriving' && v.v === 0 && !atGate) v.liveState = 'on';
            // The load shows when the weight says it carries one.
            if (v.cargoMesh) {
                const loaded = kg > (v.type === 'tractor' ? 8000 : 15000);
                v.cargoMesh.visible = v.type === 'container' ? false : loaded;
            }
            this.place(v);
            v.parts.forEach((p) => { p.position.y += this.deck.position.y; });
            this.exhaust(v, dt);
            if (this.barriers.entry.target === 1 && this.footprint(v).every((p) => p.y < wb.z1 + 2.6)) this.barriers.entry.target = 0;
            if (v.liveState === 'leaving' && this.footprint(v).every((p) => p.y < wb.z2 - 4.2)) this.barriers.exit.target = 0;
            if (v.liveState === 'leaving' && v.s > v.path.length + 20) {
                for (const p of v.parts) this.scene.remove(p);
                this.liveTruck = null;
            }
        }
        // The main entry gate opens for a vehicle called up from the road and closes behind it.
        const gin = this.gates.in;
        const t = this.liveTruck;
        const through = t && t.liveState !== 'leaving' && this.footprint(t).some((p) => p.y > Y.wallS - 6) && this.bumper(t).y < Y.roadZ + 2 && this.bumper(t).x > Y.gateIn.x - 30;
        const goal = through ? 1 : 0;
        gin.vel += clamp(clamp((goal - gin.pos) * 1.6, -0.22, 0.22) - gin.vel, -0.35 * dt, 0.35 * dt);
        gin.pos = clamp(gin.pos + gin.vel * dt, 0, 1);
        if (gin.pos >= 1 || gin.pos <= 0) gin.vel = 0;
        gin.state = gin.pos > 0.995 ? 'OPEN' : (gin.pos < 0.005 ? 'CLOSED' : (goal ? 'OPENING' : 'CLOSING'));
        gin.leaf.position.x = gin.closedX + (gin.openX - gin.closedX) * gin.pos;
        this.setSignal(gin, gin.state === 'OPEN' ? 'green' : (gin.state === 'CLOSED' ? 'red' : 'yellow'));
        // The deck gives a few millimetres under the load (drawn a little larger so it shows).
        const sink = -clamp(kg / this.capacity, 0, 1) * 0.045;
        this.deck.position.y += (sink - this.deck.position.y) * Math.min(1, dt * 4);
        for (const b of Object.values(this.barriers)) {
            b.angle += clamp(b.target - b.angle, -dt * 1.4, dt * 1.4);
            b.pivot.rotation.z = b.angle * (Math.PI / 2 - 0.05);
        }
        const tone = !this.live.live ? 0x555555 : (this.live.stable ? 0x22ff66 : 0xffb000);
        for (const m of this.cellLamps) { m.emissive.setHex(tone); m.emissiveIntensity = this.live.live ? 1.3 : 0.1; }
        const green = this.liveTruck && this.liveTruck.liveState === 'leaving';
        this.wbSignals.exit.lamps.green.emissiveIntensity = green ? 2.6 : 0;
        this.wbSignals.exit.lamps.red.emissiveIntensity = green ? 0 : 2.6;
        const enter = this.liveTruck && this.liveTruck.liveState === 'arriving';
        this.wbSignals.entry.lamps.green.emissiveIntensity = enter ? 2.6 : 0;
        this.wbSignals.entry.lamps.red.emissiveIntensity = enter ? 0 : 2.6;
    }

    /** A weight recorded with the form beside the view: the platform display and a banner say so. */
    recorded(detail) {
        const d = Array.isArray(detail) ? detail[0] : detail;
        if (!d) return;
        // Weighed: it no longer waits for the form, and drives off when the weight goes.
        this.form.recorded = this.form.typed || null;
        const kind = d.kind === 'tare' ? 'EMPTY WEIGHT (TARE)' : 'LOADED WEIGHT (GROSS)';
        this.recordedUntil = this.simTime + 5;
        this.recordedText = `RECORD SAVED · ${kind}: ${fmtKg(d.kg)}`;
        const el = this.root.querySelector('[data-wb3d="recorded"]');
        el.textContent = `${d.plate ? d.plate + ' · ' : ''}${kind.toLowerCase()} ${fmtKg(d.kg)} recorded${d.net ? ' · net ' + fmtKg(d.net) : ''}`;
        el.hidden = false;
        clearTimeout(this.recordedTimer);
        this.recordedTimer = setTimeout(() => { el.hidden = true; }, 4500);
        this.lastLed = '';
    }

    /** The big readout over the view: the weighbridge's reading (live) or the model's (simulation). */
    hud() {
        const q = (k) => this.root.querySelector(`[data-hud="${k}"]`);
        const set = (el, t) => { if (el && el.textContent !== t) el.textContent = t; };
        let kg, state, plate;
        if (this.mode === 'live') {
            kg = this.live.kg; plate = this.live.plate;
            state = !this.live.live ? 'NO SIGNAL' : (this.live.stable ? 'STABLE' : 'MOVING');
        } else {
            kg = this.wbReading; plate = this.wbVehicle ? this.wbVehicle.plate + ' · SIMULATION' : 'SIMULATION';
            state = { STABLE: 'STABLE', RECORDING: 'RECORDED', STABILIZING: 'MOVING' }[this.wbState] || (this.wbVehicle ? 'MOVING' : 'STABLE');
        }
        set(q('kg'), kg === null || kg === undefined ? '— — —' : Math.round(kg).toLocaleString('en-US'));
        set(q('maund'), kg === null || kg === undefined ? 'no reading yet' : (kg / 40).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' maund');
        const st = q('state');
        set(st, state);
        if (st) st.className = state === 'STABLE' || state === 'RECORDED' ? 'stable' : (state === 'NO SIGNAL' ? 'none' : 'moving');
        set(q('plate'), plate ? 'VEHICLE: ' + plate : '');
    }

    /** Every 100 ms: the reading of `delay` ms ago (server clock), so every reading the agent sent is shown in turn. */
    playLive() {
        const buf = this.live.buf;
        if (!buf || !buf.length) return;
        const at = Date.now() + (this.live.offset || 0) - (this.live.delay || 1000);
        for (let i = buf.length - 1; i >= 0; i--) {
            if (buf[i].ms <= at) {
                this.live.kg = Number(buf[i].kg);
                this.live.stable = !!buf[i].stable;
                return;
            }
        }
    }

    async readLive() {
        if (!this.liveUrl || this.mode !== 'live' || !this.visible || document.hidden) return;
        try {
            const res = await fetch(this.liveUrl, { credentials: 'same-origin', cache: 'no-store', redirect: 'manual', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const r = res.ok ? await res.json() : null;
            this.live.fails = r ? 0 : (this.live.fails || 0) + 1;
            if (r && r.live && Array.isArray(r.readings) && r.readings.length && typeof r.now === 'number') {
                // The readings of the last seconds (about 10 a second), played back by playLive() as in live-weight.js.
                this.live.live = true;
                this.live.offset = r.now - Date.now();
                const lag = r.now - r.readings[r.readings.length - 1].ms + 800;
                this.live.delay = Math.min(6000, Math.max(1000, lag, (this.live.delay || 1000) * 0.97));
                const buf = this.live.buf || [];
                const lastMs = buf.length ? buf[buf.length - 1].ms : 0;
                r.readings.forEach((s) => { if (s.ms > lastMs) buf.push(s); });
                this.live.buf = buf.slice(-60);
            } else if (r) {
                this.live.buf = null;
                Object.assign(this.live, { kg: r.kg === null ? null : Number(r.kg), live: !!r.live, stable: !!r.stable });
            } else if (this.live.fails >= 3) {
                this.live.buf = null;
                this.live.live = false;
            }
        } catch (e) {
            this.live.fails = (this.live.fails || 0) + 1;
            if (this.live.fails >= 3) this.live.live = false;
        }
        // The vehicle number typed in the weighing form next to it.
        const plateInput = document.querySelector('input[wire\\:model\\.blur^="plate."]');
        this.live.plate = plateInput ? plateInput.value.trim().toUpperCase() : '';
    }

    // ------------------------------------------------------------------ the display, the dashboard, the log

    drawLed() {
        let lines;
        const live = this.mode === 'live';
        if (live) {
            const L = this.live;
            const status = !L.live ? 'NO SIGNAL' : (L.kg !== null && L.kg > 0 && !L.stable ? 'MOVING' : (L.stable ? 'STABLE' : 'READY'));
            lines = [this.mill, `${this.scaleCode} · LIVE`, `VEHICLE NO: ${L.plate || '—'}`, `STATUS: ${status}`, L.kg === null ? '— — — — KG' : fmtKg(L.kg)];
            if (this.recordedUntil && this.simTime < this.recordedUntil) lines[3] = this.recordedText;
        } else {
            const v = this.wbVehicle;
            const status = { EMPTY: 'AVAILABLE', VEHICLE_ENTERING: 'VEHICLE ENTERING', POSITIONING: 'POSITIONING', STABILIZING: 'STABILIZING', STABLE: 'WEIGHT STABLE', RECORDING: 'RECORD SAVED', VEHICLE_EXITING: 'VEHICLE EXITING' }[this.wbState];
            lines = [`${this.mill} · SIM`, v ? `VEHICLE: ${v.T.label}` : 'SIMULATION', `VEHICLE NO: ${v ? v.plate : '—'}`, `STATUS: ${status}`, fmtKg(this.wbReading)];
            if (this.wbState === 'RECORDING') lines[3] = this.wbMessage;
        }
        const key = lines.join('|');
        if (key === this.lastLed) return;
        this.lastLed = key;
        const { ctx, canvas, tex } = this.led;
        ctx.fillStyle = '#050805'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#ffb000'; ctx.font = 'bold 46px "Courier New", monospace'; ctx.fillText(lines[0], 512, 70);
        ctx.fillStyle = '#9fe8ff'; ctx.font = 'bold 40px "Courier New", monospace'; ctx.fillText(lines[1], 512, 135); ctx.fillText(lines[2], 512, 190);
        ctx.fillStyle = lines[3].includes('STABLE') || lines[3].includes('RECORD') || lines[3].includes('WEIGHT:') ? '#3dff7a' : (lines[3].includes('NO SIGNAL') ? '#ff5050' : '#ffd84d');
        ctx.font = 'bold 44px "Courier New", monospace'; ctx.fillText(lines[3], 512, 260);
        ctx.fillStyle = '#ff3b2f'; ctx.font = 'bold 150px "Courier New", monospace'; ctx.shadowColor = '#ff2a00'; ctx.shadowBlur = 24;
        ctx.fillText(lines[4], 512, 430);
        ctx.shadowBlur = 0;
        tex.needsUpdate = true;
    }

    log(text) {
        if (!this.logs) this.logs = [];
        this.logs.unshift(`${this.clockText()}  ${text}`);
        this.logs = this.logs.slice(0, 40);
        this.logDirty = true;
    }

    clockText() {
        const t = Math.floor(this.simTime);
        return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
    }

    toast(text) {
        const el = this.root.querySelector('[data-wb3d="toast"]');
        el.textContent = text;
        el.hidden = false;
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(() => { el.hidden = true; }, 3500);
    }

    dashboard() {
        const q = (k) => this.root.querySelector(`[data-wb3d-stat="${k}"]`);
        const set = (k, v) => { const el = q(k); if (el && el.textContent !== String(v)) el.textContent = v; };
        const live = this.mode === 'live';
        if (live) {
            const L = this.live;
            set('weight', L.kg === null ? '—' : fmtKg(L.kg));
            set('wb', !L.live ? 'NO SIGNAL' : (L.kg > 200 ? (L.stable ? 'OCCUPIED · STABLE' : 'STABILIZING') : 'AVAILABLE'));
            set('vehicle', L.kg > 200 ? (L.plate || 'Vehicle on the scale') : '—');
            return;
        }
        const v = this.wbVehicle;
        const inside = this.vehicles.filter((o) => o.gateCleared.in && !o.gateCleared.out).length;
        set('inside', inside);
        set('entryWait', this.vehicles.filter((o) => !o.gateCleared.in).length);
        set('wbWait', this.wbQueue.filter((o) => o !== v).length);
        set('vehicle', v ? `${v.plate} · ${v.T.label}` : '—');
        set('weight', fmtKg(this.wbReading));
        const last = v || this.history[0];
        set('gross', last && last.gross != null ? fmtKg(last.gross) : '—');
        set('tare', last && (last.tare_kg ?? last.tare) != null ? fmtKg(last.tare_kg ?? last.tare) : '—');
        set('net', last && last.net != null ? fmtKg(last.net) : '—');
        set('gateIn', this.gates.in.state);
        set('gateOut', this.gates.out.state);
        set('wb', { EMPTY: 'AVAILABLE', STABILIZING: 'STABILIZING' }[this.wbState] || (this.wbState === 'STABLE' || this.wbState === 'RECORDING' ? 'OCCUPIED · STABLE' : 'OCCUPIED'));
        set('done', this.completed);
        if (this.logDirty) {
            const list = this.root.querySelector('[data-wb3d="log"]');
            list.textContent = '';
            for (const line of this.logs.slice(0, 14)) { const li = document.createElement('li'); li.textContent = line; list.appendChild(li); }
            const hist = this.root.querySelector('[data-wb3d="history"]');
            hist.textContent = '';
            for (const h of this.history.slice(0, 6)) {
                const tr = document.createElement('tr');
                for (const c of [h.slip, h.plate, h.type, fmtKg(h.gross), fmtKg(h.tare), fmtKg(h.net)]) { const td = document.createElement('td'); td.textContent = c; tr.appendChild(td); }
                hist.appendChild(tr);
            }
            this.logDirty = false;
        }
    }

    // ------------------------------------------------------------------ cameras

    cameraPose(name) {
        const wb = Y.wb;
        const follow = this.vehicles.find((o) => o.id === this.followId) || this.wbVehicle || this.vehicles[0];
        switch (name) {
            case 'gate': return { pos: [-38, 14, 88], target: [-4, 3, Y.wallS] };
            case 'side': return { pos: [wb.x + 19, 5.2, 9], target: [wb.x, 1.4, -0.5] };
            case 'cab': {
                const v = this.mode === 'live' ? this.liveTruck : (this.wbVehicle || follow);
                if (!v) return { pos: [wb.x, 2.8, wb.z1 + 16], target: [wb.x + 5.2, 4.8, wb.z1 + 1] };
                const p = this.bumper(v), d = v.dir;
                return { pos: [p.x - d.x * 1.6 - d.y * 0.5, v.type === 'tractor' ? 2.6 : 3.0, p.y - d.y * 1.6 + d.x * 0.5], target: [p.x + d.x * 20, 2.2, p.y + d.y * 20], follow: true };
            }
            case 'top': return { pos: [wb.x + 0.01, 46, 0.5], target: [wb.x, 0, 0] };
            case 'operator': return { pos: [wb.x + 8.5, 5.8, wb.z1 + 7.5], target: [wb.x + 5.2, 5.4, wb.z1 + 1] };
            case 'follow': {
                if (!follow) return this.cameraPose('aerial');
                const p = follow.ra, d = follow.dir;
                return { pos: [p.x - d.x * 18 + 6, 9, p.y - d.y * 18 + 6], target: [p.x + d.x * 4, 1.5, p.y + d.y * 4], follow: true };
            }
            default: return { pos: [-120, 120, 150], target: [0, 0, -5] };
        }
    }

    setCamera(name, instant = false) {
        this.cameraName = name;
        const pose = this.cameraPose(name);
        if (instant) {
            this.camera.position.set(...pose.pos);
            this.controls.target.set(...pose.target);
            this.controls.update();
            this.camMove = null;
        } else {
            this.camMove = { from: this.camera.position.clone(), fromT: this.controls.target.clone(), t: 0 };
        }
        this.root.querySelectorAll('[data-wb3d-cam]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.wb3dCam === name)));
    }

    updateCamera(dt) {
        const pose = this.cameraPose(this.cameraName);
        if (this.camMove) {
            this.camMove.t = Math.min(1, this.camMove.t + dt / 1.4);
            const k = this.camMove.t < 0.5 ? 2 * this.camMove.t ** 2 : 1 - (-2 * this.camMove.t + 2) ** 2 / 2;
            this.camera.position.lerpVectors(this.camMove.from, new THREE.Vector3(...pose.pos), k);
            this.controls.target.lerpVectors(this.camMove.fromT, new THREE.Vector3(...pose.target), k);
            if (this.camMove.t >= 1) this.camMove = null;
        } else if (pose.follow) {
            this.camera.position.lerp(new THREE.Vector3(...pose.pos), Math.min(1, dt * 2));
            this.controls.target.lerp(new THREE.Vector3(...pose.target), Math.min(1, dt * 3));
        }
        this.controls.update();
    }

    setNight(on) {
        this.night = on;
        this.scene.background = new THREE.Color(on ? 0x0b1220 : 0xbfdcf2);
        if (this.sky) this.sky.material.color.setHex(on ? 0x1a2236 : 0xffffff);
        if (this.scene.environment) this.scene.environmentIntensity = on ? 0.08 : 0.35;
        this.scene.fog = new THREE.Fog(on ? 0x0b1220 : 0xbfdcf2, 220, 650);
        this.hemi.intensity = on ? 0.15 : 0.9;
        this.sun.intensity = on ? 0.15 : 2.4;
        this.sun.color.setHex(on ? 0x8fa6ff : 0xfff1d6);
        this.lampMat.emissiveIntensity = on ? 3 : 0;
        this.windowMat.emissiveIntensity = on ? 1.2 : 0;
        for (const l of this.nightLights) l.intensity = on ? 380 : 0;
        this.root.querySelectorAll('[data-wb3d-action="night"]').forEach((b) => b.setAttribute('aria-pressed', String(on)));
    }

    // ------------------------------------------------------------------ controls

    setMode(mode) {
        if (this.liveTruck) { for (const p of this.liveTruck.parts) this.scene.remove(p); this.liveTruck = null; }
        this.mode = mode;
        this.root.dataset.mode = mode;
        this.root.querySelectorAll('[data-wb3d-mode]').forEach((m) => m.setAttribute('aria-pressed', String(m.dataset.wb3dMode === mode)));
        this.reset();
        if (mode === 'sim') this.log('Simulation: weights come from the model and are not real weighments');
        this.lastLed = '';
    }

    reset() {
        for (const v of [...this.vehicles]) { for (const p of v.parts) this.scene.remove(p); }
        this.vehicles = [];
        this.wbQueue = [];
        this.wbVehicle = null;
        this.setWb('EMPTY');
        this.barriers.entry.target = 0; this.barriers.exit.target = 0;
        for (const g of Object.values(this.gates)) Object.assign(g, { state: 'CLOSED', pos: 0, vel: 0, request: null, manualOpen: false });
        if (this.liveTruck) { for (const p of this.liveTruck.parts) this.scene.remove(p); this.liveTruck = null; }
        this.simTime = 0;
        this.completed = 0;
        this.history = [];
        this.logs = [];
        this.logDirty = true;
        this.demo = null;
        this.running = this.mode === 'sim' ? this.running : true;
        this.heap.scale.setScalar(0.6);
        this.showTitle(false);
    }

    startDemo() {
        this.setMode('sim');
        this.running = true;
        this.auto = true;
        this.updateAutoButton();
        const near = this.pathIn.sOf(-70, Y.roadZ);
        this.demo = { t: 0, steps: [
            [0, () => { this.setCamera('aerial'); this.addVehicle('tractor', { s: near }); }],
            [3, () => this.addVehicle('truck', { s: near - 40 })],
            [7, () => this.setCamera('gate')],
            [22, () => this.setCamera('side')],
            [34, () => { this.addVehicle('container', { s: near }); this.setCamera('gate'); }],
            [50, () => this.setCamera('aerial')],
            [58, () => this.setCamera('side')],
            [72, () => { this.followId = this.vehicles.find((v) => v.type === 'tractor')?.id ?? this.followId; this.setCamera('follow'); }],
            [100, () => this.setCamera('gate')],
            [118, () => { this.setCamera('aerial'); this.showTitle(true); }],
            [130, () => this.showTitle(false)],
        ] };
    }

    showTitle(on) {
        const el = this.root.querySelector('[data-wb3d="title"]');
        if (el) el.hidden = !on;
    }

    updateAutoButton() {
        const b = this.root.querySelector('[data-wb3d-action="auto"]');
        if (b) b.textContent = this.auto ? 'AUTO MODE' : 'MANUAL MODE';
    }

    slip() {
        const h = this.lastSlip;
        if (!h) { this.toast('No vehicle has both weights yet.'); return; }
        const box = this.root.querySelector('[data-wb3d="slip"]');
        box.querySelector('[data-slip="body"]').textContent =
            `${this.mill}\nWEIGHT SLIP ${h.slip}   (SIMULATION — NOT A WEIGHMENT)\n\nVehicle: ${h.plate}  (${h.type})\nDirection: ${h.inbound ? 'Inbound (paddy in)' : 'Outbound (rice out)'}\n\nGross weight : ${fmtKg(h.gross)}\nTare weight  : ${fmtKg(h.tare)}\nNet weight   : ${fmtKg(h.net)}\n\nNET = GROSS − TARE`;
        box.hidden = false;
    }

    bind() {
        this.root.addEventListener('click', (e) => {
            const b = e.target.closest('[data-wb3d-action], [data-wb3d-cam], [data-wb3d-mode]');
            if (!b) return;
            if (b.dataset.wb3dCam) { if (b.dataset.wb3dCam === 'follow' && this.wbVehicle) this.followId = this.wbVehicle.id; this.setCamera(b.dataset.wb3dCam); return; }
            if (b.dataset.wb3dMode) { this.setMode(b.dataset.wb3dMode); this.root.querySelectorAll('[data-wb3d-mode]').forEach((m) => m.setAttribute('aria-pressed', String(m === b))); return; }
            switch (b.dataset.wb3dAction) {
                case 'start': if (this.mode !== 'sim') this.setMode('sim'); this.running = true; if (!this.vehicles.length) { this.addVehicle('tractor'); } break;
                case 'pause': this.running = false; break;
                case 'reset': this.reset(); break;
                case 'demo': this.startDemo(); break;
                case 'tractor': case 'truck': case 'container': if (this.mode !== 'sim') this.setMode('sim'); this.running = true; this.addVehicle(b.dataset.wb3dAction); break;
                case 'open-in': this.commandGate('in', true); break;
                case 'close-in': this.commandGate('in', false); break;
                case 'open-out': this.commandGate('out', true); break;
                case 'close-out': this.commandGate('out', false); break;
                case 'auto': this.auto = !this.auto; this.updateAutoButton(); this.log(this.auto ? 'Automatic mode' : 'Manual mode: the operator opens the gates'); break;
                case 'night': this.setNight(!this.night); break;
                case 'drawer': { const d = this.root.querySelector('.drawer'); d.hidden = !d.hidden; b.setAttribute('aria-pressed', String(!d.hidden)); break; }
                case 'slip': this.slip(); break;
                case 'close-slip': this.root.querySelector('[data-wb3d="slip"]').hidden = true; break;
                case 'print-slip': { const w = window.open('', '_blank', 'width=520,height=640'); if (w) { const pre = w.document.createElement('pre'); pre.style.font = '15px monospace'; pre.textContent = this.root.querySelector('[data-slip="body"]').textContent; w.document.body.appendChild(pre); w.print(); } break; }
                default: break;
            }
        });
        // Draw only while it is on screen.
        new IntersectionObserver((entries) => { this.visible = entries.some((en) => en.isIntersecting); }, { threshold: 0.05 }).observe(this.root);
        setInterval(() => this.readLive(), 500);
        setInterval(() => this.playLive(), 100);
    }

    resize() {
        const wrap = this.root.querySelector('[data-wb3d="canvas"]');
        const w = wrap.clientWidth || 800, h = wrap.clientHeight || 450;
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
    }

    loop() {
        requestAnimationFrame(this.loop);
        const dt = Math.min(0.05, this.clock.getDelta());   // frame-rate independent, capped after a pause
        if (!this.visible || document.hidden) return;
        if (this.mode === 'sim' && this.running) {
            this.simTime += dt;
            if (this.demo) {
                this.demo.t += dt;
                while (this.demo.steps.length && this.demo.steps[0][0] <= this.demo.t) this.demo.steps.shift()[1]();
            }
            // Short steps keep the stops exact at any frame rate.
            const steps = Math.ceil(dt / 0.02);
            for (let i = 0; i < steps; i++) {
                const h = dt / steps;
                for (const v of [...this.vehicles]) this.updateVehicle(v, h);
                this.updateGate(this.gates.in, h);
                this.updateGate(this.gates.out, h);
                this.updateWeighbridge(h);
            }
            if (this.auto && this.demo === null && this.autoSpawn !== false) this.autoTraffic();
        } else if (this.mode === 'live') {
            this.simTime += dt;
            this.updateLive(dt);
        }
        if (this.mode === 'sim') for (const v of this.vehicles) this.exhaust(v, dt);
        this.updateSmoke(dt);
        this.hud();
        // Dust drifts up and fades.
        for (let i = 0; i < this.dustLife.length; i++) {
            if (this.dustLife[i] <= 0) continue;
            this.dustLife[i] -= dt;
            for (let k = 0; k < 3; k++) this.dustPos[i * 3 + k] += this.dustVel[i * 3 + k] * dt;
            if (this.dustLife[i] <= 0) this.dustPos[i * 3 + 1] = -50;
        }
        this.dust.geometry.attributes.position.needsUpdate = true;
        this.drawLed();
        this.dashboard();
        this.updateCamera(dt);
        this.renderer.render(this.scene, this.camera);
    }

    /** In automatic mode the yard keeps a steady flow: a new vehicle when the road outside is clear. */
    autoTraffic() {
        if (!this.autoEvery) this.autoEvery = 0;
        if (this.simTime < this.autoEvery || this.vehicles.length >= 6) return;
        const outside = this.vehicles.filter((v) => !v.gateCleared.in).length;
        if (outside >= 2) return;
        const order = ['tractor', 'truck', 'container', 'tractor', 'truck'];
        this.addVehicle(order[(this.spawnTurn = ((this.spawnTurn ?? -1) + 1)) % order.length]);
        this.autoEvery = this.simTime + 22;
    }
}

// ------------------------------------------------------------------ start

function start(root) {
    if (root.dataset.wb3dReady) return;
    root.dataset.wb3dReady = '1';
    try {
        const scene = new WeighbridgeScene(root);
        scene.bind();
        scene.setMode(scene.mode);
        root.querySelectorAll('[data-wb3d-mode]').forEach((m) => m.setAttribute('aria-pressed', String(m.dataset.wb3dMode === scene.mode)));
        window.millWeighbridge3d = scene;
    } catch (e) {
        const msg = root.querySelector('[data-wb3d="canvas"]');
        if (msg) msg.textContent = '3D view is not available on this computer (WebGL is switched off or not supported).';
        console.error(e);
    }
}

document.querySelectorAll('[data-wb3d-root]').forEach(start);
setInterval(() => document.querySelectorAll('[data-wb3d-root]:not([data-wb3d-ready])').forEach(start), 1500);
window.addEventListener('wb3d-recorded', (e) => { if (window.millWeighbridge3d) window.millWeighbridge3d.recorded(e.detail); });
document.addEventListener('livewire:navigated', () => document.querySelectorAll('[data-wb3d-root]').forEach(start));
