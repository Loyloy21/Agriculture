const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
function load(name, mocks = {}, globals = {}) {
  const exports = {};
  const js = ts.transpileModule(fs.readFileSync(path.join(__dirname, '../../frontend/mobile/src/services', name + '.ts'), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
  }).outputText;
  vm.runInNewContext(js, { exports, require: (id) => {
    assert(id in mocks, 'Unexpected dependency (including device GPS): ' + id); return mocks[id];
  }, Date, setTimeout: (callback) => setTimeout(callback, 5), clearTimeout, __DEV__: true, ...globals });
  return exports;
}
const metadata = load('photoLocationMetadata');
const operations = load('galleryOperation', {}, { __DEV__: false });
const parser = load('photoJpegExif', { './photoLocationMetadata': metadata });
const exif = { GPSLatitude: 9.12, GPSLatitudeRef: 'N', GPSLongitude: 125.53, GPSLongitudeRef: 'E' };
function jpeg() {
  const tiff = Buffer.alloc(128);
  tiff.write('II'); tiff.writeUInt16LE(42, 2); tiff.writeUInt32LE(8, 4); tiff.writeUInt16LE(1, 8);
  tiff.writeUInt16LE(0x8825, 10); tiff.writeUInt16LE(4, 12); tiff.writeUInt32LE(1, 14); tiff.writeUInt32LE(26, 18);
  tiff.writeUInt16LE(4, 26);
  for (const [at, tag, direction] of [[28, 1, 'N'], [52, 3, 'E']]) {
    tiff.writeUInt16LE(tag, at); tiff.writeUInt16LE(2, at + 2); tiff.writeUInt32LE(2, at + 4); tiff.write(direction, at + 8);
  }
  for (const [at, tag, offset, degrees] of [[40, 2, 80, 9], [64, 4, 104, 125]]) {
    tiff.writeUInt16LE(tag, at); tiff.writeUInt16LE(5, at + 2); tiff.writeUInt32LE(3, at + 4); tiff.writeUInt32LE(offset, at + 8);
    for (let i = 0; i < 3; i++) { tiff.writeUInt32LE(i === 0 ? degrees : 0, offset + i * 8); tiff.writeUInt32LE(1, offset + i * 8 + 4); }
  }
  const payload = Buffer.concat([Buffer.from('Exif\0\0'), tiff]);
  return Buffer.concat([Buffer.from([255, 216, 255, 225, 0, payload.length + 2]), payload, Buffer.from([255, 217])]);
}
let files = {}, logs = [], assetCalls = [];
const library = {
  getPermissionsAsync: async () => { throw Error('permission denied'); },
  getAssetInfoAsync: async (id) => { assetCalls.push(id); throw Error('missing ACCESS_MEDIA_LOCATION'); },
  requestPermissionsAsync: () => { throw Error('MUST NOT REQUEST PERMISSIONS'); },
};
const service = load('galleryPhotoEvidence.android', {
  'expo-constants': { expoVersion: '57.0.9' },
  'expo-file-system': { File: class {
    constructor(uri) { this.uri = uri; }
    get exists() { return !!files[this.uri]; }
    get size() { return files[this.uri]?.length || 0; }
    async bytes() { return files[this.uri]; }
  } },
  'expo-media-library/legacy': library,
  'react-native': { Platform: { Version: 31 }, PermissionsAndroid: { PERMISSIONS: { ACCESS_MEDIA_LOCATION: 'media_location' }, check: async () => false } },
  './galleryOperation': operations, './photoLocationMetadata': metadata, './photoJpegExif': parser,
}, { console: { info: (...args) => logs.push(args) } });
(async () => {
  const picker = await service.galleryPhotoEvidence({ exif, uri: 'file:///private/cache.jpg' });
  assert.equal(picker.latitude, 9.12);
  for (const invalid of [null, {}, { ...exif, GPSLatitude: 0, GPSLongitude: 0 }, { ...exif, GPSLatitudeRef: '' }, { ...exif, GPSLongitude: NaN }]) {
    const evidence = await service.galleryPhotoEvidence({ exif: invalid, uri: 'file:///private/cache.jpg' });
    assert.equal(evidence.latitude, null);
    assert.match(evidence.locationIssue, /picker supplied no usable GPS or original asset ID/);
  }
  files['file:///private/cache.jpg'] = jpeg();
  const bytes = await service.galleryPhotoEvidence({ exif: null, uri: 'file:///private/cache.jpg', mimeType: 'image/jpeg' });
  assert.equal(bytes.latitude, 9);
  assert.equal(bytes.longitude, 125);
  assert.equal(bytes.locationSource, 'photo_exif');
  files = {};
  const denied = await service.galleryPhotoEvidence({ uri: 'file:///private/cache.jpg', assetId: 'selected-id' });
  assert.match(denied.locationIssue, /Original media-location access was denied/);
  assert.deepEqual(assetCalls, ['selected-id']);
  library.getAssetInfoAsync = async () => ({ location: { latitude: 9.2, longitude: 125.6 }, exif: null });
  const original = await service.galleryPhotoEvidence({ assetId: 'selected-original', exif });
  assert.equal(original.locationSource, 'asset_location');
  assert.equal(original.latitude, 9.2);
  library.getAssetInfoAsync = async () => ({ location: null, exif, creationTime: 1 });
  assert.equal((await service.galleryPhotoEvidence({ assetId: 'original-exif' })).latitude, 9.12);
  files['file:///private/original.jpg'] = jpeg();
  library.getAssetInfoAsync = async () => ({ location: null, exif: null, localUri: 'file:///private/original.jpg', filename: 'original.jpg' });
  assert.equal((await service.galleryPhotoEvidence({ assetId: 'original-file' })).latitude, 9);
  library.getAssetInfoAsync = () => new Promise(() => {});
  assert.match((await service.galleryPhotoEvidence({ assetId: 'hung' })).locationIssue, /timed out/);
  assert.equal(picker.latitude, 9.12, 'Later photos cannot change earlier evidence');
  assert(!JSON.stringify(logs).includes('/private/'));
  assert(!JSON.stringify(logs).includes('125.53'));
  const oldIOS = () => 'unchanged';
  assert.equal(load('galleryPhotoEvidence', { './photoLocation': { galleryPhotoEvidence: oldIOS } }).galleryPhotoEvidence, oldIOS);
  const manual = load('androidDamagePoint', { './photoLocationMetadata': metadata });
  for (const value of ['', ',', '0,0', '9,', 'north,east', '9,Infinity', '91,125', '9,125 extra', '9,125,1']) assert.equal(manual.parseAndroidDamagePoint(value), null);
  assert.equal(manual.parseAndroidDamagePoint('9.12, 125.53').longitude, 125.53);
  console.log('PASS: Android picker EXIF, cache JPEG, exact original asset/location/EXIF/file, invalid GPS, timeout/permission failure, per-photo isolation, no device GPS, safe diagnostics, iOS delegate identity, manual coordinate parser. Native APIs mocked.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
