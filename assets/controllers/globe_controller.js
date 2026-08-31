import { Controller } from '@hotwired/stimulus';

/*
 * Hand-rolled orthographic dot-globe on a 2D canvas — no WebGL, no external
 * library. Land is a coarse polygon mask sampled into dots once at connect();
 * per frame we only rotate + project, cheap enough to run continuously.
 * Visitor pins are country centroids only (see COUNTRY_CENTROIDS) — our GeoIP
 * data never has more precision than that, deliberately (privacy-first).
 *
 * Turbo-safety is the whole reason this is Canvas 2D and not a WebGL library:
 * disconnect() only ever needs cancelAnimationFrame + clearInterval, no risk
 * of leaking a GPU context across repeated navigations to /dashboard.
 */

const D2R = Math.PI / 180;

// Coarse continent outlines, [lat, lon] pairs — rough approximations meant to
// render as ~2px dots on a small canvas, not a precise coastline dataset.
const LAND_POLYGONS = [
    // North America
    [[70, -165], [70, -130], [60, -95], [49, -95], [45, -83], [42, -82], [41, -73], [35, -76], [30, -81], [25, -81], [26, -97], [19, -96], [16, -95], [9, -83], [8, -77], [7, -80], [18, -105], [23, -110], [32, -117], [41, -124], [49, -125], [55, -133], [60, -142], [65, -166]],
    // Greenland
    [[83, -35], [76, -20], [65, -40], [70, -55], [80, -55]],
    // South America
    [[12, -72], [5, -53], [0, -50], [-8, -35], [-15, -39], [-23, -43], [-34, -54], [-40, -63], [-50, -70], [-55, -68], [-45, -73], [-30, -71], [-18, -70], [-5, -81], [1, -79], [8, -77]],
    // Europe
    [[71, 25], [68, 40], [59, 30], [54, 20], [47, 29], [41, 20], [36, -5], [43, -9], [48, -4], [51, 3], [54, 8], [58, 9], [63, 10], [66, 14]],
    // UK + Ireland
    [[59, -3], [54, -3], [51, 1], [50, -5], [54, -8], [57, -6]],
    // Africa
    [[35, -6], [37, 10], [33, 12], [31, 32], [22, 37], [12, 43], [11, 51], [2, 45], [-11, 40], [-20, 35], [-26, 33], [-34, 20], [-33, 18], [-23, 14], [-17, 12], [-6, 12], [0, 9], [4, 8], [4, -8], [10, -15], [15, -17], [21, -17], [28, -13], [32, -9]],
    // Asia
    [[70, 45], [73, 80], [71, 110], [70, 140], [65, 170], [60, 150], [54, 137], [43, 132], [35, 120], [30, 122], [23, 117], [16, 108], [10, 105], [6, 101], [1, 104], [8, 98], [16, 94], [22, 91], [21, 88], [16, 82], [8, 77], [15, 74], [22, 68], [25, 57], [27, 51], [30, 49], [37, 44], [41, 37], [42, 27], [47, 32], [50, 40], [55, 45], [62, 42]],
    // Japan
    [[45, 142], [43, 145], [38, 141], [34, 135], [31, 130], [35, 133], [39, 140], [43, 141]],
    // SE Asia islands
    [[-2, 95], [3, 99], [-1, 104], [-6, 106], [-8, 113], [-9, 120], [-8, 126], [-4, 133], [-2, 140], [-6, 143], [-9, 141], [-8, 131], [-9, 116], [-6, 106]],
    // Australia
    [[-11, 132], [-12, 136], [-16, 141], [-11, 142], [-16, 146], [-22, 149], [-27, 153], [-33, 152], [-38, 147], [-38, 140], [-35, 136], [-32, 133], [-31, 125], [-34, 119], [-33, 115], [-27, 114], [-20, 117], [-14, 127]],
    // New Zealand
    [[-35, 173], [-38, 178], [-41, 175], [-45, 171], [-46, 167], [-42, 172], [-38, 174]],
    // Madagascar
    [[-12, 49], [-16, 50], [-22, 48], [-25, 47], [-22, 44], [-16, 44]],
];

// ISO-3166-1 alpha-2 -> approximate [lat, lon] centroid, covering essentially
// every code GeoIP can return (not just the ~100 most-trafficked countries
// from the original table) — a visitor from an unmapped code used to vanish
// from the globe with no pin and no indication anything was dropped; a
// handful of uninhabited/unlikely-to-ever-appear territories (Antarctica,
// Bouvet Island, French Southern Territories, ...) are still intentionally
// left out since a pin for them would never actually render in practice.
const COUNTRY_CENTROIDS = {
    AD: [42.5, 1.6], AE: [24, 54], AF: [33, 66], AG: [17.1, -61.8], AI: [18.2, -63.1],
    AL: [41, 20], AM: [40, 45], AO: [-12, 17], AR: [-34, -64], AS: [-14.3, -170.7],
    AT: [47.5, 14], AU: [-25, 134], AW: [12.5, -69.97], AX: [60.2, 20], AZ: [40.5, 47.5],
    BA: [44, 18], BB: [13.2, -59.5], BD: [24, 90], BE: [50.8, 4.5], BF: [12.2, -1.6],
    BG: [43, 25], BH: [26, 50.5], BI: [-3.4, 29.9], BJ: [9.3, 2.3], BM: [32.3, -64.8],
    BN: [4.5, 114.7], BO: [-17, -65], BQ: [12.2, -68.3], BR: [-10, -52], BS: [24.3, -76.6],
    BT: [27.5, 90.4], BW: [-22.3, 24.7], BY: [53, 28], BZ: [17.2, -88.5],
    CA: [56, -106], CD: [-2.9, 23.6], CF: [6.6, 20.9], CG: [-0.2, 15.8], CH: [47, 8],
    CI: [7.5, -5.5], CK: [-21.2, -159.8], CL: [-32, -71], CM: [5.7, 12.7], CN: [35, 105],
    CO: [4, -73], CR: [10, -84], CU: [22, -79], CV: [16, -24], CW: [12.2, -69],
    CY: [35, 33], CZ: [49.8, 15.5],
    DE: [51, 10], DJ: [11.8, 42.6], DK: [56, 10], DM: [15.4, -61.4], DO: [19, -70.5], DZ: [28, 3],
    EC: [-1.5, -78.5], EE: [59, 26], EG: [27, 30], EH: [24.2, -12.9], ER: [15.2, 39.8],
    ES: [40, -4], ET: [9, 39],
    FI: [62, 26], FJ: [-17.7, 178.1], FK: [-51.8, -59.5], FM: [6.9, 158.2], FO: [62, -6.8], FR: [46.5, 2.5],
    GA: [-0.8, 11.6], GB: [54, -2], GD: [12.1, -61.7], GE: [42, 43.5], GF: [4, -53],
    GG: [49.5, -2.6], GH: [8, -1], GI: [36.1, -5.3], GL: [71.7, -42.6], GM: [13.4, -15.3],
    GN: [9.9, -9.7], GP: [16.3, -61.6], GQ: [1.6, 10.6], GR: [39, 22], GT: [15.5, -90],
    GU: [13.4, 144.8], GW: [12, -15.2], GY: [4.9, -58.9],
    HK: [22.3, 114.2], HN: [15, -86.5], HR: [45.5, 16], HT: [18.9, -72.3], HU: [47, 20],
    ID: [-2, 118], IE: [53, -8], IL: [31, 35], IM: [54.2, -4.5], IN: [21, 78],
    IO: [-6.3, 71.9], IQ: [33, 44], IR: [32, 53], IS: [65, -19], IT: [43, 12.5],
    JE: [49.2, -2.1], JM: [18, -77], JO: [31, 36], JP: [36, 138],
    KE: [1, 38], KG: [41.2, 74.8], KH: [13, 105], KI: [1.9, -157.4], KM: [-11.9, 43.9],
    KN: [17.3, -62.7], KP: [40.3, 127.5], KR: [36, 128], KW: [29.5, 47.5], KY: [19.5, -80.6], KZ: [48, 68],
    LA: [19.9, 102.6], LB: [34, 36], LC: [13.9, -60.9], LI: [47.2, 9.5], LK: [7, 81],
    LR: [6.4, -9.4], LS: [-29.6, 28.2], LT: [55, 24], LU: [49.7, 6.1], LV: [57, 25], LY: [26.3, 17.2],
    MA: [32, -6], MC: [43.7, 7.4], MD: [47, 29], ME: [42.7, 19.4], MF: [18.07, -63.05],
    MG: [-19, 47], MH: [7.1, 171.2], MK: [41.6, 21.7], ML: [17.6, -4], MM: [22, 96],
    MN: [46, 105], MO: [22.2, 113.5], MP: [15.1, 145.7], MQ: [14.6, -61], MR: [20.3, -10.3],
    MS: [16.7, -62.2], MT: [35.9, 14.4], MU: [-20.3, 57.6], MV: [3.2, 73.2], MW: [-13.3, 34.3],
    MX: [23, -102], MY: [2.5, 112.5], MZ: [-18.7, 35.5],
    NA: [-22.9, 18.5], NC: [-21.3, 165.5], NE: [17.6, 8.1], NF: [-29, 167.9], NG: [10, 8],
    NI: [12.9, -85.2], NL: [52, 5.7], NO: [61, 9], NP: [28, 84], NR: [-0.5, 166.9], NZ: [-42, 174],
    OM: [21, 57],
    PA: [8.5, -80], PE: [-10, -76], PF: [-17.7, -149.4], PG: [-6.3, 143.9], PH: [13, 122],
    PK: [30, 70], PL: [52, 19.5], PM: [46.9, -56.3], PR: [18.2, -66.5], PS: [31.9, 35.2],
    PT: [39.5, -8], PW: [7.5, 134.6], PY: [-23, -58],
    QA: [25.3, 51],
    RE: [-21.1, 55.5], RO: [46, 25], RS: [44, 21], RU: [61, 90], RW: [-1.9, 29.9],
    SA: [24, 45], SB: [-9.6, 160.2], SC: [-4.7, 55.5], SD: [15.6, 30.2], SE: [62, 15],
    SG: [1.35, 103.8], SH: [-15.9, -5.7], SI: [46, 15], SK: [48.7, 19.5], SL: [8.5, -11.8],
    SM: [43.9, 12.5], SN: [14.5, -14.5], SO: [5.2, 46.2], SR: [4, -56], SS: [7.9, 30],
    ST: [0.3, 6.6], SV: [13.8, -88.9], SX: [18.03, -63.06], SY: [35, 38], SZ: [-26.5, 31.5],
    TC: [21.7, -71.8], TD: [15.5, 19], TG: [8.6, 1.2], TH: [15, 101], TJ: [38.9, 71.3],
    TK: [-9.2, -171.8], TL: [-8.9, 125.7], TM: [39, 59.6], TN: [34, 9], TO: [-21.2, -175.2],
    TR: [39, 35], TT: [10.7, -61.2], TV: [-7.5, 178.7], TW: [23.7, 121], TZ: [-6.4, 34.9],
    UA: [49, 32], UG: [1.4, 32.3], US: [39, -98], UY: [-33, -56], UZ: [41, 64],
    VA: [41.9, 12.45], VC: [13.3, -61.2], VE: [8, -66], VG: [18.4, -64.6], VI: [18.3, -64.9],
    VN: [16, 108], VU: [-16, 167],
    WF: [-13.3, -176.2], WS: [-13.8, -172.1],
    XK: [42.6, 21],
    YE: [15.6, 48], YT: [-12.8, 45.2],
    ZA: [-29, 24], ZM: [-13.1, 27.9], ZW: [-19, 29.9],
};

// Linear-interpolates two hex colors — used to shade pins from "signal" teal
// (a single visitor) toward "alert" copper (a busy country), so color itself
// carries information instead of just dot size.
const lerpColor = (hexA, hexB, t) => {
    const a = [parseInt(hexA.slice(1, 3), 16), parseInt(hexA.slice(3, 5), 16), parseInt(hexA.slice(5, 7), 16)];
    const b = [parseInt(hexB.slice(1, 3), 16), parseInt(hexB.slice(3, 5), 16), parseInt(hexB.slice(5, 7), 16)];
    const c = a.map((v, i) => Math.round(v + (b[i] - v) * t));
    return `rgb(${c[0]}, ${c[1]}, ${c[2]})`;
};

const pointInPolygon = (lat, lon, poly) => {
    let inside = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const [yi, xi] = poly[i];
        const [yj, xj] = poly[j];
        if (yi > lat !== yj > lat && lon < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) {
            inside = !inside;
        }
    }
    return inside;
};

const isLand = (lat, lon) => LAND_POLYGONS.some((poly) => pointInPolygon(lat, lon, poly));

export default class extends Controller {
    static targets = ['canvas', 'count', 'countries', 'feed'];
    static values = { url: String, initial: Object, interval: { type: Number, default: 5000 } };

    connect() {
        this.reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.dots = this.buildLandDots();
        this.pins = [];
        this.lon0 = -20;
        this.lat0 = 22;
        this.dragging = false;
        this.pollSeq = 0;

        this.setupCanvas();
        this.resizeObserver = new ResizeObserver(() => this.setupCanvas());
        this.resizeObserver.observe(this.canvasTarget.parentElement);

        this.hoveredPin = null;
        this.pinnedPin = null;
        this.setupTooltip();

        this.render(this.initialValue);

        this.frame = requestAnimationFrame(() => this.tick());
        this.timer = setInterval(() => this.poll(), this.intervalValue);

        this.onPointerDown = this.onPointerDown.bind(this);
        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
        this.onHoverMove = this.onHoverMove.bind(this);
        this.onHoverLeave = this.onHoverLeave.bind(this);
        this.canvasTarget.style.cursor = 'grab';
        this.canvasTarget.style.touchAction = 'none';
        this.canvasTarget.addEventListener('pointerdown', this.onPointerDown);
        this.canvasTarget.addEventListener('pointermove', this.onHoverMove);
        this.canvasTarget.addEventListener('pointerleave', this.onHoverLeave);
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        clearInterval(this.timer);
        this.resizeObserver?.disconnect();
        this.canvasTarget.removeEventListener('pointerdown', this.onPointerDown);
        this.canvasTarget.removeEventListener('pointermove', this.onHoverMove);
        this.canvasTarget.removeEventListener('pointerleave', this.onHoverLeave);
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        this.tooltip?.remove();
    }

    // Built once at connect() rather than in the template: this controller
    // already owns 100% of its own visuals (canvas is the only markup it's
    // given), so a floating detail card follows the same self-contained
    // pattern instead of adding a new Stimulus target just for this.
    setupTooltip() {
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'pointer-events-none absolute z-10 hidden whitespace-nowrap rounded-sm bg-ink px-2 py-1 font-mono text-xs text-paper shadow-lg';
        const parent = this.canvasTarget.parentElement;
        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }
        parent.appendChild(this.tooltip);
    }

    // Hold and drag to rotate manually — pauses auto-rotation for the
    // duration of the drag (see tick()) and resumes it from wherever the
    // user left the globe. Pointer Events cover mouse/touch/pen in one API;
    // move/up listeners live on window (not the canvas) so the drag keeps
    // tracking even if the cursor leaves the canvas bounds mid-gesture.
    onPointerDown(event) {
        this.dragging = true;
        this.dragDistance = 0;
        this.lastX = event.clientX;
        this.lastY = event.clientY;
        this.canvasTarget.style.cursor = 'grabbing';
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        event.preventDefault();
    }

    onPointerMove(event) {
        const dx = event.clientX - this.lastX;
        const dy = event.clientY - this.lastY;
        this.lastX = event.clientX;
        this.lastY = event.clientY;
        this.dragDistance += Math.abs(dx) + Math.abs(dy);

        // Positive sign on both axes so the surface tracks the cursor
        // directly: drag right and the point under the cursor moves right
        // (project() maps increasing lon0 to increasing screen x).
        const sensitivity = 0.3;
        this.lon0 += dx * sensitivity;
        this.lat0 = Math.max(-85, Math.min(85, this.lat0 + dy * sensitivity));
    }

    // A drag that barely moved is treated as a tap — toggles a "pinned"
    // tooltip on whatever pin is underneath, the touch-device equivalent of
    // hover (see onHoverMove) since touch never fires pointermove without
    // a preceding pointerdown.
    onPointerUp(event) {
        this.dragging = false;
        this.canvasTarget.style.cursor = 'grab';
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);

        if (this.dragDistance < 5) {
            const rect = this.canvasTarget.getBoundingClientRect();
            const hit = this.findPinAt(event.clientX - rect.left, event.clientY - rect.top);
            this.pinnedPin = this.pinnedPin === hit ? null : hit;
            this.updateTooltip(event.clientX - rect.left, event.clientY - rect.top);
        }
    }

    // Mouse-only (touch doesn't fire hover) — pauses auto-rotation while a
    // pin is under the cursor so its tooltip doesn't chase a moving target,
    // same rationale as pausing during a manual drag.
    onHoverMove(event) {
        if (this.dragging) return;
        const rect = this.canvasTarget.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        this.hoveredPin = this.findPinAt(x, y);
        this.canvasTarget.style.cursor = this.hoveredPin ? 'pointer' : 'grab';
        this.updateTooltip(x, y);
    }

    onHoverLeave() {
        this.hoveredPin = null;
        this.updateTooltip();
    }

    updateTooltip(x, y) {
        const pin = this.hoveredPin ?? this.pinnedPin;
        if (!pin) {
            this.tooltip.classList.add('hidden');
            return;
        }

        const label = pin.country ? pin.country : '??';
        const suffix = pin.count === 1 ? 'visiteur en ligne' : 'visiteurs en ligne';
        this.tooltip.textContent = `${label} · ${pin.count} ${suffix}`;
        this.tooltip.classList.remove('hidden');

        if (x !== undefined && y !== undefined) {
            this.tooltipX = x;
            this.tooltipY = y;
        }
        this.tooltip.style.left = `${Math.min(this.tooltipX + 12, this.size - 10)}px`;
        this.tooltip.style.top = `${Math.max(this.tooltipY - 28, 0)}px`;
    }

    // Same projection used by draw() so a pin's clickable/hoverable area
    // always matches exactly what's actually drawn (see pinRadius()).
    findPinAt(x, y) {
        if (this.pins.length === 0) return null;

        const { sinLon, cosLon, sinLat, cosLat } = this.rotationTrig();
        let closest = null;
        let closestDist = Infinity;

        for (const pin of this.pins) {
            const p = this.project(pin, sinLon, cosLon, sinLat, cosLat);
            if (p.z <= 0.05) continue;

            const dist = Math.hypot(p.sx - x, p.sy - y);
            const hitRadius = this.pinRadius(pin.count) + 6;
            if (dist <= hitRadius && dist < closestDist) {
                closest = pin;
                closestDist = dist;
            }
        }

        return closest;
    }

    buildLandDots() {
        const dots = [];
        const STEP = 3;
        for (let lat = -80; lat <= 80; lat += STEP) {
            const lonStep = STEP / Math.max(0.3, Math.cos(lat * D2R));
            for (let lon = -180; lon < 180; lon += lonStep) {
                if (!isLand(lat, lon)) continue;
                dots.push(this.toVector(lat, lon));
            }
        }
        return dots;
    }

    toVector(lat, lon) {
        const phi = lat * D2R;
        const lambda = lon * D2R;
        return {
            x: Math.cos(phi) * Math.sin(lambda),
            y: Math.sin(phi),
            z: Math.cos(phi) * Math.cos(lambda),
        };
    }

    setupCanvas() {
        const box = this.canvasTarget.parentElement.getBoundingClientRect();
        const size = Math.max(0, Math.min(box.width, 440));
        if (!size) return;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        this.canvasTarget.style.width = `${size}px`;
        this.canvasTarget.style.height = `${size}px`;
        this.canvasTarget.width = size * dpr;
        this.canvasTarget.height = size * dpr;
        this.ctx = this.canvasTarget.getContext('2d');
        this.ctx.scale(dpr, dpr);
        this.size = size;
        this.radius = size * 0.46;
        this.center = size / 2;
    }

    project(point, sinLon, cosLon, sinLat, cosLat) {
        const x1 = point.x * cosLon - point.z * sinLon;
        const z1 = point.x * sinLon + point.z * cosLon;
        const y1 = point.y * cosLat - z1 * sinLat;
        const z2 = point.y * sinLat + z1 * cosLat;
        return { sx: this.center + this.radius * x1, sy: this.center - this.radius * y1, z: z2 };
    }

    rotationTrig() {
        return {
            sinLon: Math.sin(-this.lon0 * D2R),
            cosLon: Math.cos(-this.lon0 * D2R),
            sinLat: Math.sin(this.lat0 * D2R),
            cosLat: Math.cos(this.lat0 * D2R),
        };
    }

    pinRadius(count) {
        return 4 + Math.min(count, 10) * 1.3;
    }

    tick() {
        if (!this.reduced && !this.dragging && !this.hoveredPin && !this.pinnedPin) {
            this.lon0 += 0.06;
        }
        this.draw();
        this.frame = requestAnimationFrame(() => this.tick());
    }

    // A dark "radar scope" reading rather than a pale globe on a white card:
    // deliberately higher-contrast than the rest of the paper/ink UI, because
    // this is the one signature element on the page allowed to be bold. The
    // sphere is lit from the upper-left (radial gradient) so rotation reads
    // as real depth, not just a color swap.
    draw() {
        if (!this.ctx) return;
        const ctx = this.ctx;
        ctx.clearRect(0, 0, this.size, this.size);

        const { sinLon, cosLon, sinLat, cosLat } = this.rotationTrig();

        const sphere = ctx.createRadialGradient(
            this.center - this.radius * 0.35, this.center - this.radius * 0.35, this.radius * 0.1,
            this.center, this.center, this.radius * 1.15
        );
        sphere.addColorStop(0, '#1B2B27');
        sphere.addColorStop(0.6, '#101A17');
        sphere.addColorStop(1, '#0A1210');
        ctx.fillStyle = sphere;
        ctx.beginPath();
        ctx.arc(this.center, this.center, this.radius, 0, Math.PI * 2);
        ctx.fill();

        ctx.save();
        ctx.filter = 'blur(3px)';
        ctx.strokeStyle = 'rgba(94, 245, 212, 0.65)';
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        ctx.arc(this.center, this.center, this.radius, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();

        ctx.strokeStyle = 'rgba(94, 245, 212, 0.9)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(this.center, this.center, this.radius, 0, Math.PI * 2);
        ctx.stroke();

        // Thin bright highlight on the lit edge, matching the gradient's
        // light source — reinforces the sphere's depth rather than reading
        // as a flat ring.
        ctx.strokeStyle = 'rgba(180, 255, 235, 0.5)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(this.center, this.center, this.radius - 1, Math.PI * 1.05, Math.PI * 1.45);
        ctx.stroke();

        ctx.fillStyle = '#5EF5D4';
        for (const dot of this.dots) {
            const p = this.project(dot, sinLon, cosLon, sinLat, cosLat);
            if (p.z <= 0.02) continue;
            // Higher floor on both alpha and size than before: at the previous
            // 0.35 alpha / <2px size, land dots blended into the sphere's dark
            // gradient badly enough to read as "nothing rendered" rather than
            // "a world map".
            ctx.globalAlpha = 0.55 + 0.45 * p.z;
            const r = 1.6 + p.z * 1.2;
            ctx.fillRect(p.sx - r / 2, p.sy - r / 2, r, r);
        }
        ctx.globalAlpha = 1;

        // A slow radar-style pulse ring per pin — the single most "alive"
        // touch on the page, so it's worth the animation budget. Frozen (no
        // ring, static glow+core only) under prefers-reduced-motion.
        const pulse = this.reduced ? 0 : (Date.now() % 1600) / 1600;

        const maxCount = this.pins.reduce((max, pin) => Math.max(max, pin.count), 1);
        const activePin = this.hoveredPin ?? this.pinnedPin;
        for (const pin of this.pins) {
            const p = this.project(pin, sinLon, cosLon, sinLat, cosLat);
            if (p.z <= 0.05) continue;

            const t = Math.min(pin.count / Math.max(maxCount, 4), 1);
            const color = lerpColor('#2EE0B8', '#F0894D', t);
            const r = this.pinRadius(pin.count);
            const active = pin === activePin;

            if (!this.reduced) {
                ctx.strokeStyle = color;
                ctx.lineWidth = active ? 2 : 1.5;
                ctx.globalAlpha = (1 - pulse) * 0.55 * (0.4 + 0.6 * p.z);
                ctx.beginPath();
                ctx.arc(p.sx, p.sy, r * (1.4 + pulse * 2.6), 0, Math.PI * 2);
                ctx.stroke();
            }

            ctx.save();
            ctx.filter = 'blur(4px)';
            ctx.fillStyle = color;
            ctx.globalAlpha = 0.45 + 0.35 * p.z;
            ctx.beginPath();
            ctx.arc(p.sx, p.sy, r * 1.8, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();

            ctx.fillStyle = color;
            ctx.globalAlpha = 0.85 + 0.15 * p.z;
            ctx.beginPath();
            ctx.arc(p.sx, p.sy, r, 0, Math.PI * 2);
            ctx.fill();

            // A crisp white ring on top of whichever pin the pointer is
            // resting on (or last tapped) — a clearer "this one" signal than
            // relying on the cursor position alone once several pins cluster.
            if (active) {
                ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)';
                ctx.lineWidth = 1.5;
                ctx.globalAlpha = 0.9;
                ctx.beginPath();
                ctx.arc(p.sx, p.sy, r + 3, 0, Math.PI * 2);
                ctx.stroke();
            }
        }
        ctx.globalAlpha = 1;
    }

    render(data) {
        if (!data) return;

        this.pins = (data.pins || [])
            .map((pin) => {
                const centroid = COUNTRY_CENTROIDS[pin.country];
                if (!centroid) return null;
                return { ...this.toVector(centroid[0], centroid[1]), count: pin.count, country: pin.country };
            })
            .filter(Boolean);

        // Each poll rebuilds this.pins from scratch, so hoveredPin/pinnedPin
        // (set from a previous array) are stale object references by identity
        // even when "the same" country is still online — re-resolve by
        // country code instead of just nulling out on the next poll, so a
        // tap-pinned tooltip survives normal 5s refreshes and only actually
        // clears when that country really did go offline.
        const rebind = (pin) => (pin ? (this.pins.find((p) => p.country === pin.country) ?? null) : null);
        this.hoveredPin = rebind(this.hoveredPin);
        this.pinnedPin = rebind(this.pinnedPin);
        this.updateTooltip();

        if (this.hasCountTarget) this.countTarget.textContent = data.online;
        if (this.hasCountriesTarget) this.countriesTarget.textContent = data.onlineCountries;
        if (this.hasFeedTarget) this.renderFeed(data.feed || []);
    }

    // Built with DOM APIs + textContent, never innerHTML: `path` is visitor-
    // supplied text (from the public tracking script) and must never be
    // interpreted as HTML in the tenant's authenticated dashboard.
    renderFeed(feed) {
        this.feedTarget.replaceChildren();

        for (const item of feed) {
            const row = document.createElement('li');
            row.className = 'py-2 flex items-center justify-between gap-4';
            row.dataset.globe = 'feed-item';

            const label = document.createElement('span');
            label.className = 'text-sm text-ink-muted truncate';

            const path = document.createElement('span');
            path.className = 'font-medium text-ink';
            path.textContent = item.path;

            if (item.country) {
                const country = document.createElement('span');
                country.className = 'font-mono text-xs text-ink-muted mr-2';
                country.textContent = item.country;
                label.append(country);
            }
            label.append(document.createTextNode('quelqu\'un lit '), path);

            const time = document.createElement('span');
            time.className = 'font-mono text-xs text-ink-muted shrink-0';
            time.textContent = item.relativeTime;

            row.append(label, time);
            this.feedTarget.appendChild(row);
        }
    }

    // Tagged with a sequence number so a slow response can't overwrite the UI
    // after a faster, later poll already rendered fresher data.
    async poll() {
        const seq = ++this.pollSeq;
        let response;
        try {
            response = await fetch(this.urlValue);
        } catch {
            return;
        }
        if (!response.ok) return;

        const data = await response.json();
        if (seq !== this.pollSeq) return;
        this.render(data);
    }
}
