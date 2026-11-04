// Native renderer is mocked. These tests cannot establish whether real tiles render.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const ts = require('../../frontend/mobile/node_modules/typescript');
const root = path.join(__dirname, '../../frontend/mobile/src');
const nativeSource = fs.readFileSync(path.join(root, 'android/AndroidOriginalPhotoPicker.kt'), 'utf8');
assert(nativeSource.includes('setPackage("com.google.android.apps.maps")'), 'New Android APK must explicitly target Google Maps');
function load(file, mocks, globals = {}) {
  const exports = {};
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, jsx: ts.JsxEmit.ReactJSX },
  }).outputText, { exports, require: (name) => {
    if (!(name in mocks)) throw Error('Unexpected import: ' + name);
    return mocks[name];
  }, __DEV__: true, ...globals });
  return exports;
}
const metadata = load('services/photoLocationMetadata.ts', {});
const keyCheck = (nativeModule) => load('services/androidNativeMapsKey.ts', {
  'expo-modules-core': { requireOptionalNativeModule: () => nativeModule },
}).hasNativeGoogleMapsKey();
assert.equal(keyCheck(null), false, 'Old APK without new native check must be treated as keyless');
assert.equal(keyCheck({ hasGoogleMapsApiKey: () => false }), false);
assert.equal(keyCheck({ hasGoogleMapsApiKey: () => true }), true);
assert.equal(keyCheck({ hasGoogleMapsApiKey: () => { throw Error('native failure'); } }), false);
assert.equal(load('services/androidNativeMapsKey.ts', {
  'expo-modules-core': { requireOptionalNativeModule: () => { throw Error('bridge unavailable'); } },
}).hasNativeGoogleMapsKey(), false);
const nativeLaunch = load('services/androidNativeMapsKey.ts', {
  'expo-modules-core': { requireOptionalNativeModule: () => ({ openGoogleMapsPoint: (lat, lon) => lat === 9.11 && lon === 125.52 }) },
}).tryOpenInstalledGoogleMaps;
assert.equal(nativeLaunch(9.11, 125.52), true);
assert.equal(nativeLaunch(0, 0), false);
let slots = [], cursor = 0, effects = [], timers = [], opened, keyReady = false;
const state = (value) => {
  const id = cursor++;
  if (!(id in slots)) slots[id] = value;
  return [slots[id], (next) => { slots[id] = typeof next === 'function' ? next(slots[id]) : next; }];
};
const jsx = (type, props, key) => ({ type, props: key === undefined ? props : { ...props, key } });
const Screen = load('components/damage-location-map.android.tsx', {
  'react': { useState: state, useRef: (value) => state({ current: value })[0], useEffect: (effect, deps) => {
    const id = cursor++;
    if (!slots[id] || deps.some((dep, index) => dep !== slots[id][index])) effects.push(effect);
    slots[id] = deps;
  } },
  'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
  'react-native': { View: 'View', Pressable: 'Pressable', Text: 'Text',
    Linking: { openURL: async (url) => { opened = url; } }, StyleSheet: { create: (x) => x, absoluteFillObject: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0 } } },
  'react-native-maps': { __esModule: true, default: 'MapView', Marker: 'Marker', Circle: 'Circle', PROVIDER_GOOGLE: 'google' },
  '@/services/androidNativeMapsKey': { hasNativeGoogleMapsKey: () => keyReady, tryOpenInstalledGoogleMaps: () => false },
  '@/services/photoLocationMetadata': metadata,
}, { setTimeout: (fn) => { timers.push(fn); return fn; }, clearTimeout: () => {} }).default;
function walk(node) {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(walk);
  return [node, ...walk(node.props?.children)];
}
function render(Component, props) { cursor = 0; const tree = Component(props); effects.splice(0).forEach((fn) => fn()); return tree; }
const props = { latitude: 9.11, longitude: 125.52, accuracy: 8, label: 'Photo location' };
assert.equal(render(Screen, { ...props, latitude: 0, longitude: 0 }), null);
const draw = (props) => { const inner = render(Screen, props); return inner && render(inner.type, inner.props); };
let tree = draw(props);
assert(!walk(tree).some((n) => n.type === 'MapView'), 'Missing native key must never mount MapView');
assert(!JSON.stringify(tree).includes('Try embedded map'));
assert(!JSON.stringify(tree).includes('Cabadbaran map diagnostic'));
assert(JSON.stringify(tree).includes('new Android APK'));
walk(tree).find((n) => n.type === 'Pressable' && n.props.accessibilityRole === 'link').props.onPress();
assert.equal(opened, 'geo:9.11,125.52?q=9.11%2C125.52');
keyReady = true;
slots = []; effects = [];
tree = draw(props);
let map = walk(tree).find((n) => n.type === 'MapView');
assert.equal(map.props.provider, 'google');
assert.equal(map.props.mapType, 'satellite');
assert.equal(map.props.initialRegion.latitude, props.latitude);
assert.equal(map.props.initialRegion.longitude, props.longitude);
assert.equal(map.props.key, '9.11,125.52');
assert.equal(walk(tree).find((n) => n.type === 'Marker').props.coordinate.longitude, props.longitude);
assert.equal(walk(tree).find((n) => n.type === 'Circle').props.radius, 8);
assert.equal(walk(tree).find((n) => n.props?.style?.height === 240).props.style.width, '100%');
tree = draw({ ...props, latitude: 9.2, longitude: 125.6 });
map = walk(tree).find((n) => n.type === 'MapView');
assert.equal(map.props.key, '9.2,125.6', 'Switching point remounts map at new center');
assert.equal(map.props.initialRegion.latitude, 9.2);
assert.equal(walk(tree).find((n) => n.type === 'Marker').props.coordinate.longitude, 125.6);
map.props.onPress();
assert.equal(opened, 'geo:9.2,125.6?q=9.2%2C125.6');
timers.at(-1)();
tree = draw({ ...props, latitude: 9.2, longitude: 125.6 });
assert(JSON.stringify(tree).includes('Map tiles have not loaded'));
map.props.onMapLoaded();
tree = draw({ ...props, latitude: 9.2, longitude: 125.6 });
assert(!JSON.stringify(tree).includes('Map tiles have not loaded'));
console.log('PASS: Android keyless crash guard, satellite marker/center, exact geo intent, no 0,0 map, point switching, tile timeout. Native rendering NOT tested.');

const manualParser = load('services/androidDamagePoint.ts', { './photoLocationMetadata': metadata });
slots = []; effects = [];
const Manual = load('components/damage-manual-pin-map.android.tsx', {
  'react': { useState: state, useEffect: () => {} },
  'react/jsx-runtime': { jsx, jsxs: jsx },
  'react-native': { View: 'View', ScrollView: 'ScrollView', TextInput: 'TextInput', Pressable: 'Pressable', Text: 'Text',
    Linking: { openURL: async (url) => { opened = url; } }, StyleSheet: { create: (x) => x } },
  'react-native-maps': { __esModule: true, default: 'MapView', Marker: 'Marker', PROVIDER_GOOGLE: 'google' },
  '@/services/androidDamagePoint': manualParser, '@/services/photoLocationMetadata': metadata,
  '@/services/androidNativeMapsKey': { hasNativeGoogleMapsKey: () => false },
}).default;
let manualPoint = null;
const drawManual = () => render(Manual, { point: manualPoint, onChange: (point) => { manualPoint = point; } });
let manualTree = drawManual();
assert(!walk(manualTree).some((n) => n.type === 'MapView'), 'Manual entry must work without native tiles');
assert(!JSON.stringify(manualTree).includes('Show optional embedded map'), 'Keyless manual map must have no crashing action');
const apply = (node) => walk(node).find((n) => n.type === 'Pressable' && walk(n).some((child) => child.props?.children === 'Apply these farmer-selected coordinates'));
assert.equal(apply(manualTree).props.disabled, true);
walk(manualTree).find((n) => n.type === 'TextInput').props.onChangeText('9.12, 125.53');
manualTree = drawManual();
assert.equal(manualPoint, null, 'Pasting alone must not select or confirm the point');
assert.equal(apply(manualTree).props.disabled, false);
apply(manualTree).props.onPress();
assert.equal(manualPoint.latitude, 9.12);
manualTree = drawManual();
walk(manualTree).find((n) => n.props.accessibilityRole === 'link').props.onPress();
assert.equal(opened, 'https://www.google.com/maps/search/?api=1&query=9.12%2C125.53');
walk(manualTree).find((n) => n.type === 'TextInput').props.onChangeText('0,0');
assert.equal(manualPoint, null, 'Editing must invalidate the old manual draft');
assert.equal(apply(drawManual()).props.disabled, true);
console.log('PASS: Android manual coordinate entry works with no map, explicit apply, exact-point link, and invalid-input protection.');
