// Registration pin map: tile/projection maths (pure) checked against independent reference formulas.
// node tests/map_tiles.cjs [--network]   (--network also fetches ONE real tile for Cabadbaran; no secrets involved)
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const source = fs.readFileSync(path.join(__dirname, '../../frontend/mobile/src/services/mapTiles.ts'), 'utf8');
const mod = { exports: {} };
vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText, { exports: mod.exports, Math, Number, encodeURIComponent, String });
const m = mod.exports;
let passed = 0;
const ok = (condition, label) => { assert(condition, label); passed++; console.log('PASS: ' + label); };
const near = (a, b, eps, label) => ok(Math.abs(a - b) <= eps, `${label} (got ${a}, expected ${b})`);

// Reference: the OSM wiki "Slippy map tilenames" formulas, written independently of the module.
const refTile = (lat, lon, z) => { const n = 2 ** z; const r = (lat * Math.PI) / 180; return { x: Math.floor(((lon + 180) / 360) * n), y: Math.floor(((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2) * n) }; };

const CABADBARAN = { latitude: 9.1236, longitude: 125.5354 };
const POBLACION_PIN = { latitude: 9.1172482, longitude: 125.5296631 };
const template = m.DEFAULT_TILE_URL;

// 1. The tile that covers a point is the tile the reference formula names.
for (const z of [10, 13, 15, 17, 19]) {
  for (const point of [CABADBARAN, POBLACION_PIN]) {
    const ref = refTile(point.latitude, point.longitude, z);
    const tiles = m.visibleTiles(point, z, 360, 280, template);
    const centre = tiles.find((t) => t.left <= 180 && 180 < t.left + 256 && t.top <= 140 && 140 < t.top + 256);
    ok(centre && centre.x === ref.x && centre.y === ref.y, `z${z} (${point.latitude}, ${point.longitude}): the tile under the map centre is ${ref.z ?? z}/${ref.x}/${ref.y}`);
    ok(centre.url === `https://tile.openstreetmap.org/${z}/${ref.x}/${ref.y}.png`, `z${z}: tile URL is built from the same x/y`);
  }
}

// 2. Tiles cover the whole viewport with no gaps.
for (const [w, h] of [[360, 280], [393, 340], [412, 360], [1024, 600]]) {
  const tiles = m.visibleTiles(POBLACION_PIN, 16, w, h, template);
  const covered = (x, y) => tiles.some((t) => x >= t.left && x < t.left + 256 && y >= t.top && y < t.top + 256);
  let gaps = 0; for (let x = 0; x < w; x += 7) for (let y = 0; y < h; y += 7) if (!covered(x, y)) gaps++;
  ok(gaps === 0, `${w}x${h} viewport is fully covered by ${tiles.length} tiles`);
}

// 3. The pin lands exactly where the point is, and a tap returns the exact point (round trip).
for (const z of [8, 12, 15, 17, 19]) {
  const centre = m.pointToViewport(CABADBARAN, CABADBARAN, z, 360, 280);
  near(centre.x, 180, 1e-9, `z${z}: the centre point is drawn at the viewport centre x`);
  near(centre.y, 140, 1e-9, `z${z}: the centre point is drawn at the viewport centre y`);
  const px = m.pointToViewport(POBLACION_PIN, CABADBARAN, z, 360, 280);
  const back = m.viewportToPoint(px.x, px.y, CABADBARAN, z, 360, 280);
  near(back.latitude, POBLACION_PIN.latitude, 1e-9, `z${z}: tap-to-coordinate round trip, latitude`);
  near(back.longitude, POBLACION_PIN.longitude, 1e-9, `z${z}: tap-to-coordinate round trip, longitude`);
}
// Scale check: at zoom 17 one pixel is 360/(256*2^17) degrees of longitude.
const a = m.viewportToPoint(180, 140, CABADBARAN, 17, 360, 280); const b = m.viewportToPoint(280, 140, CABADBARAN, 17, 360, 280);
near(b.longitude - a.longitude, (100 * 360) / (256 * 2 ** 17), 1e-12, '100 px east at zoom 17 equals the expected number of degrees');
// Metres per pixel sanity at latitude 9.12, zoom 17: about 1.18 m/px (standard OSM table 156543.03*cos(lat)/2^z).
const metresPerPixel = ((b.longitude - a.longitude) / 100) * 111320 * Math.cos((CABADBARAN.latitude * Math.PI) / 180);
near(metresPerPixel, (156543.03392 * Math.cos((CABADBARAN.latitude * Math.PI) / 180)) / 2 ** 17, 0.02, 'zoom 17 ground resolution matches the standard OSM table');

// 4. Panning: dragging the map right by 100 px moves a fixed point 100 px right on screen.
const before = m.pointToViewport(POBLACION_PIN, CABADBARAN, 16, 360, 280);
const panned = m.panCenter(CABADBARAN, 16, 100, -40);
const after = m.pointToViewport(POBLACION_PIN, panned, 16, 360, 280);
near(after.x - before.x, 100, 1e-6, 'drag right 100 px: the pin moves 100 px right');
near(after.y - before.y, -40, 1e-6, 'drag up 40 px: the pin moves 40 px up');

// 5. Zoom and configuration helpers.
ok(m.clampZoom(99) === 19 && m.clampZoom(-3) === 5 && m.clampZoom(14.4) === 14, 'zoom is clamped to 5..19');
ok(m.zoomForLongitudeDelta(0.18, 393) >= 11 && m.zoomForLongitudeDelta(0.18, 393) <= 12 && m.zoomForLongitudeDelta(0.025, 393) === 14 && m.zoomForLongitudeDelta(0.018, 393) === 15, 'native Region deltas map to the expected tile zoom (city 11-12, 0.025 -> 14, 0.018 -> 15)');
ok(m.resolveTileUrlTemplate('http://insecure.example/{z}/{x}/{y}.png') === template && m.resolveTileUrlTemplate('https://tiles.example/{z}/{x}.png') === template && m.resolveTileUrlTemplate(undefined) === template, 'a non-https or incomplete tile template is ignored');
ok(m.resolveTileUrlTemplate('https://tiles.example/{z}/{x}/{y}.png') === 'https://tiles.example/{z}/{x}/{y}.png', 'a valid https template is accepted');
ok(m.isValidLatLng(POBLACION_PIN) && !m.isValidLatLng({ latitude: 0, longitude: 0 }) && !m.isValidLatLng({ latitude: NaN, longitude: 1 }) && !m.isValidLatLng(null), 'invalid points (0,0, NaN, null) are rejected');
const wrapped = m.visibleTiles({ latitude: 0.5, longitude: 179.9 }, 3, 800, 400, template);
ok(wrapped.every((t) => t.x >= 0 && t.x < 8 && t.y >= 0 && t.y < 8), 'tiles wrap across the antimeridian and stay inside the world');
ok(m.visibleTiles(CABADBARAN, 15, 0, 0, template).length === 0, 'an unmeasured (0 px) viewport requests no tiles');
const link = m.openStreetMapLink(POBLACION_PIN); const g = m.googleMapsLink(POBLACION_PIN);
ok(link.includes('mlat=9.1172482') && link.includes('mlon=125.5296631') && g.includes('9.1172482%2C125.5296631'), 'external links carry the exact selected coordinates');
ok(m.formatCoordinates(POBLACION_PIN) === '9.117248, 125.529663', 'coordinates are displayed to six decimals');

// 6. Architecture guards for the registration map (source-level; the physical phone is a separate test).
const src = (file) => fs.readFileSync(path.join(__dirname, '../../frontend/mobile/src', file), 'utf8');
const register = src('app/register.tsx');
const tileMap = src('components/registration-pin-map.tsx');
const iosMap = src('components/registration-pin-map.ios.tsx');
ok(!/import\s+MapView/.test(register) && !/from 'react-native-maps'/.test(register.replace(/import type [^;]*from 'react-native-maps';/, '')), 'registration no longer imports the native map at runtime (only a type import)');
ok(!tileMap.includes('react-native-maps') && !tileMap.includes('PROVIDER_GOOGLE'), 'the Android/web registration map uses no native Google Maps SDK, so a missing or unauthorised Maps key cannot blank it');
ok(iosMap.includes("from 'react-native-maps'") && iosMap.includes('<Marker') && iosMap.includes('animateToRegion'), 'the iPhone map keeps the native Apple Maps view, marker and re-centre behaviour');
ok(/pin=\{residentialDraftPin\}/.test(register) && /pin=\{draftPin\}/.test(register) && /pinLabel="home"/.test(register) && /pinLabel="farm"/.test(register), 'home and farm maps are separate instances driven by separate draft pins');
const fnBody = (name) => { const start = register.indexOf(`async function ${name}`); return register.slice(start, register.indexOf('\n  }\n', start)); };
for (const name of ['useResidentialGpsLocation', 'useGpsLocation']) {
  const body = fnBody(name);
  ok(body.length > 200 && !/PinConfirmed:\s*true|pinConfirmed:\s*true|setData\(/.test(body) && !/validateFarmPin/.test(body), `${name}: the phone location only proposes a starting view/draft pin; it never confirms or validates a pin`);
}
ok(/Selected \{pinLabel\} point/.test(src('components/registration-pin-review.tsx')) && /not confirmed yet/.test(src('components/registration-pin-review.tsx')), 'the exact selected coordinates are shown with a confirmed / not-confirmed label');
ok(tileMap.includes('Map imagery could not be loaded') && tileMap.includes('Retry map') && tileMap.includes('Open exact point in Google Maps'), 'a failed tile load shows an explanatory state with retry and an exact-point link, never a silent blank map');
ok(tileMap.includes('© OpenStreetMap') || tileMap.includes('TILE_ATTRIBUTION'), 'OpenStreetMap attribution is displayed');

(async () => {
  if (process.argv.includes('--network')) {
    const z = 17; const ref = refTile(POBLACION_PIN.latitude, POBLACION_PIN.longitude, z);
    const url = m.tileUrl(template, z, ref.x, ref.y);
    const response = await fetch(url, { headers: { 'User-Agent': m.TILE_USER_AGENT } });
    const bytes = Buffer.from(await response.arrayBuffer());
    ok(response.status === 200 && (response.headers.get('content-type') || '').startsWith('image/png') && bytes.length > 500 && bytes.subarray(1, 4).toString() === 'PNG', `real OpenStreetMap tile ${z}/${ref.x}/${ref.y} for the Poblacion pin was served (HTTP ${response.status}, ${bytes.length} bytes, PNG)`);
    fs.mkdirSync(path.join(__dirname, '../.artifacts/registration-map'), { recursive: true });
    fs.writeFileSync(path.join(__dirname, '../.artifacts/registration-map/real-tile.png'), bytes);
  }
  console.log(`\nALL ${passed} CHECKS PASSED`);
})().catch((error) => { console.error(error); process.exit(1); });
