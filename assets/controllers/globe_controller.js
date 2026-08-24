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

// ISO-3166-1 alpha-2 -> approximate [lat, lon] centroid. Unmapped codes are
// simply skipped (no pin) — same trade-off the rest of the app already makes
// with country-level-only geolocation.
const COUNTRY_CENTROIDS = {
    AD: [42.5, 1.6], AE: [24, 54], AF: [33, 66], AL: [41, 20], AM: [40, 45], AO: [-12, 17],
    AR: [-34, -64], AT: [47.5, 14], AU: [-25, 134], AZ: [40.5, 47.5], BA: [44, 18], BD: [24, 90],
    BE: [50.8, 4.5], BG: [43, 25], BH: [26, 50.5], BO: [-17, -65], BR: [-10, -52], BY: [53, 28],
    CA: [56, -106], CH: [47, 8], CL: [-32, -71], CN: [35, 105], CO: [4, -73], CR: [10, -84],
    CU: [22, -79], CY: [35, 33], CZ: [49.8, 15.5], DE: [51, 10], DK: [56, 10], DO: [19, -70.5],
    DZ: [28, 3], EC: [-1.5, -78.5], EE: [59, 26], EG: [27, 30], ES: [40, -4], ET: [9, 39],
    FI: [62, 26], FR: [46.5, 2.5], GB: [54, -2], GE: [42, 43.5], GH: [8, -1], GR: [39, 22],
    GT: [15.5, -90], HK: [22.3, 114.2], HN: [15, -86.5], HR: [45.5, 16], HU: [47, 20],
    ID: [-2, 118], IE: [53, -8], IL: [31, 35], IN: [21, 78], IQ: [33, 44], IR: [32, 53],
    IS: [65, -19], IT: [43, 12.5], JM: [18, -77], JO: [31, 36], JP: [36, 138], KE: [1, 38],
    KH: [13, 105], KR: [36, 128], KW: [29.5, 47.5], KZ: [48, 68], LB: [34, 36], LK: [7, 81],
    LT: [55, 24], LU: [49.7, 6.1], LV: [57, 25], MA: [32, -6], MD: [47, 29], MM: [22, 96],
    MN: [46, 105], MT: [35.9, 14.4], MX: [23, -102], MY: [2.5, 112.5], NG: [10, 8], NL: [52, 5.7],
    NO: [61, 9], NP: [28, 84], NZ: [-42, 174], OM: [21, 57], PA: [8.5, -80], PE: [-10, -76],
    PH: [13, 122], PK: [30, 70], PL: [52, 19.5], PR: [18.2, -66.5], PT: [39.5, -8], PY: [-23, -58],
    QA: [25.3, 51], RO: [46, 25], RS: [44, 21], RU: [61, 90], SA: [24, 45], SE: [62, 15],
    SG: [1.35, 103.8], SI: [46, 15], SK: [48.7, 19.5], SV: [13.8, -88.9], TH: [15, 101], TN: [34, 9],
    TR: [39, 35], TW: [23.7, 121], UA: [49, 32], US: [39, -98], UY: [-33, -56], UZ: [41, 64],
    VE: [8, -66], VN: [16, 108], ZA: [-29, 24],
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

        this.render(this.initialValue);

        this.frame = requestAnimationFrame(() => this.tick());
        this.timer = setInterval(() => this.poll(), this.intervalValue);

        this.onPointerDown = this.onPointerDown.bind(this);
        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
        this.canvasTarget.style.cursor = 'grab';
        this.canvasTarget.style.touchAction = 'none';
        this.canvasTarget.addEventListener('pointerdown', this.onPointerDown);
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        clearInterval(this.timer);
        this.resizeObserver?.disconnect();
        this.canvasTarget.removeEventListener('pointerdown', this.onPointerDown);
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
    }

    // Hold and drag to rotate manually — pauses auto-rotation for the
    // duration of the drag (see tick()) and resumes it from wherever the
    // user left the globe. Pointer Events cover mouse/touch/pen in one API;
    // move/up listeners live on window (not the canvas) so the drag keeps
    // tracking even if the cursor leaves the canvas bounds mid-gesture.
    onPointerDown(event) {
        this.dragging = true;
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

        // Positive sign on both axes so the surface tracks the cursor
        // directly: drag right and the point under the cursor moves right
        // (project() maps increasing lon0 to increasing screen x).
        const sensitivity = 0.3;
        this.lon0 += dx * sensitivity;
        this.lat0 = Math.max(-85, Math.min(85, this.lat0 + dy * sensitivity));
    }

    onPointerUp() {
        this.dragging = false;
        this.canvasTarget.style.cursor = 'grab';
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
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

    tick() {
        if (!this.reduced && !this.dragging) {
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

        const sinLon = Math.sin(-this.lon0 * D2R);
        const cosLon = Math.cos(-this.lon0 * D2R);
        const sinLat = Math.sin(this.lat0 * D2R);
        const cosLat = Math.cos(this.lat0 * D2R);

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
        for (const pin of this.pins) {
            const p = this.project(pin, sinLon, cosLon, sinLat, cosLat);
            if (p.z <= 0.05) continue;

            const t = Math.min(pin.count / Math.max(maxCount, 4), 1);
            const color = lerpColor('#2EE0B8', '#F0894D', t);
            const r = 2.5 + Math.min(pin.count, 8);

            if (!this.reduced) {
                ctx.strokeStyle = color;
                ctx.lineWidth = 1.5;
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
        }
        ctx.globalAlpha = 1;
        ctx.globalAlpha = 1;
    }

    render(data) {
        if (!data) return;

        this.pins = (data.pins || [])
            .map((pin) => {
                const centroid = COUNTRY_CENTROIDS[pin.country];
                if (!centroid) return null;
                return { ...this.toVector(centroid[0], centroid[1]), count: pin.count };
            })
            .filter(Boolean);

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
