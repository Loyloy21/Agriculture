// Executes the real screen handlers with a minimal React/native harness.
// This checks orchestration, NOT UIKit presentation or a physical phone.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const root = path.join(__dirname, '../../frontend/mobile/src');
function load(file, mocks, globals = {}) {
  const exports = {};
  const js = ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  vm.runInNewContext(js, { exports, require: (name) => {
    if (!(name in mocks)) throw Error('Unexpected import: ' + name);
    return mocks[name];
  }, __DEV__: false, Date, setTimeout, clearTimeout, ...globals });
  return exports;
}
const metadata = load('services/photoLocationMetadata.ts', {});
const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
async function scenario(mode, os = 'ios') {
  const timers = new Map();
  let timerId = 0;
  const operations = load('services/galleryOperation.ts', {}, {
    setTimeout: (callback, delay) => { timers.set(++timerId, { callback, delay }); return timerId; },
    clearTimeout: (id) => timers.delete(id),
  });
  function expire(delay) {
    const timer = [...timers.values()].find((t) => t.delay === delay);
    assert(timer, `Expected active ${delay}ms deadline`);
    timer.callback();
  }
  const slots = [];
  let cursor = 0, pickerCalls = 0, conversions = 0, pickerResolve, metadataResolve, boundaryCalls = 0;
  const useState = (value) => {
    const id = cursor++;
    if (!(id in slots)) slots[id] = value;
    return [slots[id], (next) => { slots[id] = typeof next === 'function' ? next(slots[id]) : next; }];
  };
  const jsx = (type, props) => ({ type, props });
  const native = Object.fromEntries(['ActivityIndicator', 'Image', 'KeyboardAvoidingView', 'Modal', 'Pressable', 'ScrollView', 'Text', 'TextInput', 'View'].map((name) => [name, name]));
  const Screen = load('app/reports/new.tsx', {
    'react': { useState, useEffect: () => {}, useRef: (value) => useState({ current: value })[0] },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { ...native, Platform: { OS: os }, StyleSheet: { create: (x) => x }, useWindowDimensions: () => ({ width: 390 }) },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-router': { router: {} },
    'expo-location': {},
    'expo-image-picker': {
      UIImagePickerPreferredAssetRepresentationMode: { Current: 'current' },
      launchImageLibraryAsync: (options) => {
        pickerCalls++;
        assert.equal(options.allowsMultipleSelection, true);
        assert.equal(options.selectionLimit, 10);
        assert.equal(options.preferredAssetRepresentationMode, 'current');
        if (mode === 'picker_error') throw Error('native error with private file path');
        return new Promise((resolve) => { pickerResolve = resolve; });
      },
    },
    '@/services/galleryOperation': operations,
    '@/services/androidSystemPhotoPicker': { androidOriginalPickerAvailable: () => false },
    '@/services/photoLocationMetadata': metadata,
    '@/services/photoLocation': {},
    '@/services/galleryPhotoEvidence': {
      galleryPhotoEvidence: async () => {
        if (mode === 'metadata_timeout') return new Promise((resolve) => { metadataResolve = resolve; });
        if (mode === 'metadata_error') throw Error('native metadata denied');
        return metadata.emptyPhotoEvidence('No accessible photo GPS');
      },
    },
    '@/services/uploadImages': {
      UploadImagePreparationError: class extends Error {},
      MAX_DAMAGE_PHOTOS: 10, MAX_DAMAGE_IMAGE_BYTES: 5242880, MAX_DAMAGE_TOTAL_BYTES: 33554432,
      preparePickedImage: async () => {
        conversions++;
        if (mode === 'conversion_timeout') return new Promise(() => {});
        if (mode === 'conversion_error') throw Error('private native error');
        return { uri: 'prepared.jpg', size: 100, fileName: 'damage.jpg', mimeType: 'image/jpeg' };
      },
    },
    '@/services/farmerDataApi': { validateDamagePoint: async (lat, lon) => {
      assert.equal(lat, 9.12); assert.equal(lon, 125.53); boundaryCalls++;
    } },
    '@/state/farmerAuthContext': { useFarmerAuth: () => ({}) },
    '@/utils/damageDetails': load('utils/damageDetails.ts', {}),
    '@/components/damage-location-map': () => null,
    '@/components/damage-manual-pin-map': () => null,
    '@/components/farm-ui': { AppScreen: 'AppScreen', Hero: 'Hero', Icon: 'Icon', Motion: 'Motion', Action: 'Action', palette: { red: '#A83F35' }, ui: {}, useReducedMotion: () => true },
  }).default;
  function render() { cursor = 0; return Screen(); }
  function walk(node) {
    if (!node || typeof node !== 'object') return [];
    if (Array.isArray(node)) return node.flatMap(walk);
    return [node, ...walk(node.props?.children)];
  }
  const hasText = (node, text) => walk(node).some((n) => n.type === 'Text' && n.props.children === text);
  const button = (tree, text) => walk(tree).find((n) => n.type === 'Pressable' && hasText(n, text));
  if (os === 'android') {
    walk(render()).find((n) => n.props?.label === 'Latitude').props.onChangeText('9.12');
    walk(render()).find((n) => n.props?.label === 'Longitude').props.onChangeText('125.53');
    const confirm = button(render(), 'This is the damaged location');
    assert.equal(confirm.props.disabled, false, 'Manual confirmation must not wait for map tiles');
    await confirm.props.onPress();
    await flush();
    assert.equal(boundaryCalls, 1);
    assert(button(render(), 'Damage location confirmed'));
  }
  // Legacy source sheet remains available via Photo options. Test its UIKit
  // dismissal independently from the new immediately visible Gallery action.
  walk(render()).find(n => n.props?.accessibilityLabel === 'Photo options').props.onPress();
  const choices = walk(render()).filter(n => n.type === 'Modal');
  const running = button(choices.find(n => n.props.onDismiss), 'Choose from Gallery').props.onPress();
  await flush();
  if (os === 'ios') assert.equal(pickerCalls, 0, 'Never present picker during iOS modal dismissal');
  if (mode === 'dismiss_timeout') expire(5000);
  else {
    if (os === 'ios') walk(render()).find((n) => n.type === 'Modal' && n.props.onDismiss).props.onDismiss();
    await flush();
    assert.equal(pickerCalls, 1);
    if (mode === 'picker_timeout') expire(120000);
    else if (mode !== 'picker_error') {
      pickerResolve(mode === 'cancel' ? { canceled: true, assets: null } : { canceled: false, assets: [{ uri: 'original.heic', width: 3000, height: 4000, exif: null }] });
      await flush();
      if (mode === 'metadata_timeout') expire(35000);
      await flush();
      if (mode === 'conversion_timeout') expire(30000);
    }
  }
  await running;
  assert.equal(button(render(), 'Choose from Gallery').props.disabled, false, `${mode}: loading state must clear`);
  const expectedPhoto = ['success', 'metadata_error', 'metadata_timeout'].includes(mode);
  assert.equal(walk(render()).filter((n) => n.type === 'Image' && n.props.source?.uri === 'prepared.jpg').length, expectedPhoto ? 1 : 0);
  if (mode === 'metadata_timeout') {
    metadataResolve({ ...metadata.emptyPhotoEvidence(), latitude: 9.12, longitude: 125.53 });
    await flush();
    assert(!hasText(render(), '9.1200000, 125.5300000'), 'Late metadata must not modify the report pin');
  }
  if (mode === 'picker_timeout') {
    pickerResolve({ canceled: false, assets: [{ uri: 'late.jpg' }] });
    await flush();
    assert.equal(conversions, 0, 'Late picker result must not add photos');
  }
  assert.equal(timers.size, 0, 'Settled operations must clear timers');
  if (os === 'android') {
    assert.equal(walk(render()).find((n) => n.props?.label === 'Latitude').props.value, '9.12');
    assert.equal(walk(render()).find((n) => n.props?.label === 'Longitude').props.value, '125.53');
    assert(button(render(), 'Damage location confirmed'), 'Adding no-GPS photos must preserve explicit confirmed point');
  }
}
(async () => {
  for (const mode of ['success', 'cancel', 'dismiss_timeout', 'picker_error', 'picker_timeout', 'metadata_error', 'metadata_timeout', 'conversion_error', 'conversion_timeout']) {
    await scenario(mode);
    console.log('PASS: mocked iOS screen flow — ' + mode);
  }
  for (const mode of ['success', 'metadata_error', 'conversion_error']) {
    await scenario(mode, 'android');
    console.log('PASS: Android no-tiles confirmation and explicit-point preservation: ' + mode);
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
