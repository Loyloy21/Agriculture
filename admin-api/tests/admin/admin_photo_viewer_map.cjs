const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const php = fs.readFileSync('frontend/admin/damage-reports.php', 'utf8');
const start = php.indexOf('  const clearViewerLocationMap =');
const end = php.indexOf('  const showViewerImage =', start);
assert(start > 0 && end > start, 'Selected-photo map code must exist');
let removed = 0;
const maps = [];
const L = {
  map: (_target, _options) => {
    const item = { point: null, marker: null, removed: false, setView(point) { this.point = point; return this; },
      invalidateSize() {}, remove() { this.removed = true; removed++; } };
    maps.push(item); return item;
  },
  tileLayer: () => ({ addTo: () => {} }),
  marker: (point) => ({ addTo(map) { map.marker = point; return this; }, bindPopup: () => {} }),
};
const link = { hidden: true, href: '', removeAttribute(name) { if (name === 'href') this.href = ''; } };
const mapElement = { hidden: true, children: [], replaceChildren() { if (this.children.length) removed++; this.children = []; }, appendChild(frame) { this.children.push(frame); maps.push(frame); } };
const summary = { textContent: '' };
const context = { L, window: { L, requestAnimationFrame: (callback) => callback() }, viewerIndex: 0,
  document: { createElement: () => ({ style: {} }) },
  viewerLocationMap: mapElement, viewerLocationLink: link, viewerLocationText: summary,
  photoSourceLabel: (image) => image.gps_source === 'photo_exif' ? 'Original photo EXIF GPS' : 'Fresh device GPS at capture',
  pointEvidence: (lat, lon) => {
    if (lat == null || lon == null) return { state: 'unavailable', reason: 'No coordinate pair was stored.' };
    const a = Number(lat), b = Number(lon);
    return Number.isFinite(a) && Number.isFinite(b) && a >= -90 && a <= 90 && b >= -180 && b <= 180 && (a !== 0 || b !== 0)
      ? { state: 'passed', point: [a, b] } : { state: 'failed', reason: 'Invalid coordinates.' };
  },
};
vm.runInNewContext(`let viewerLeafletMap = null; ${php.slice(start, end)}; globalThis.show = showViewerLocation; globalThis.clear = clearViewerLocationMap;`, context);
context.show({ id: 101, gps_latitude: '9.1000000', gps_longitude: '125.5000000', gps_source: 'photo_exif' });
assert.match(maps[0].src, /maps\.google\.com.*9\.1%2C125\.5.*t=k/);
assert.match(link.href, /9\.1%2C125\.5/);
context.viewerIndex = 1;
context.show({ id: 202, gps_latitude: '9.5000000', gps_longitude: '125.9000000', gps_source: 'device_capture' });
assert.equal(removed, 1, 'Previous photo map removed');
assert.match(maps[1].src, /maps\.google\.com.*9\.5%2C125\.9/);
assert.match(summary.textContent, /saved image ID 202/);
assert.match(link.href, /9\.5%2C125\.9/);
context.viewerIndex = 2;
context.show({ id: 303, gps_latitude: null, gps_longitude: null });
assert.equal(removed, 2, 'No previous marker retained for missing GPS');
assert.equal(mapElement.hidden, true);
assert.equal(link.hidden, true);
assert.equal(link.href, '');
assert.match(summary.textContent, /saved image ID 303.*No usable saved photo GPS/);
context.show({ id: 404, gps_latitude: 0, gps_longitude: 0 });
assert.equal(maps.length, 2, '0,0 never makes a map');
console.log('PASS: Admin selected-photo viewer changes saved image ID, Google preview coordinate and exact link; missing/0,0 clears prior map. Browser tiles not tested.');
