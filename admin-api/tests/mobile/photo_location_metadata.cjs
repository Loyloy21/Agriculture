// No device or network access: exercise the actual TypeScript services with native mocks.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const serviceRoot = path.join(__dirname, '../../frontend/mobile/src/services');
function load(name, mocks = {}, timers = {}) {
  const exports = {};
  const output = ts.transpileModule(fs.readFileSync(path.join(serviceRoot, name + '.ts'), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
  }).outputText;
  vm.runInNewContext(output, { exports, require: (name) => {
    if (!(name in mocks)) throw new Error('Unexpected dependency: ' + name);
    return mocks[name];
  }, Date, setTimeout, clearTimeout, AbortController, __DEV__: false, ...timers });
  return exports;
}
const metadata = load('photoLocationMetadata');
const galleryOperations = load('galleryOperation', {}, { setTimeout: (callback) => setTimeout(callback, 10) });
const jpegParser = load('photoJpegExif', { './photoLocationMetadata': metadata });
function gpsJpeg(lat = [9, 7, 0], lon = [125, 31, 0], latRef = 'N', lonRef = 'E') {
  const tiff = Buffer.alloc(128);
  tiff.write('II', 0, 'ascii'); tiff.writeUInt16LE(42, 2); tiff.writeUInt32LE(8, 4);
  tiff.writeUInt16LE(1, 8);
  tiff.writeUInt16LE(0x8825, 10); tiff.writeUInt16LE(4, 12); tiff.writeUInt32LE(1, 14); tiff.writeUInt32LE(26, 18);
  tiff.writeUInt16LE(4, 26);
  const ref = (at, tag, direction) => {
    tiff.writeUInt16LE(tag, at); tiff.writeUInt16LE(2, at + 2); tiff.writeUInt32LE(2, at + 4);
    tiff.write(direction, at + 8, 'ascii');
  };
  const dms = (at, tag, offset, parts) => {
    tiff.writeUInt16LE(tag, at); tiff.writeUInt16LE(5, at + 2); tiff.writeUInt32LE(3, at + 4); tiff.writeUInt32LE(offset, at + 8);
    parts.forEach((part, index) => { tiff.writeUInt32LE(part, offset + index * 8); tiff.writeUInt32LE(1, offset + index * 8 + 4); });
  };
  ref(28, 1, latRef); dms(40, 2, 80, lat); ref(52, 3, lonRef); dms(64, 4, 104, lon);
  const payload = Buffer.concat([Buffer.from('Exif\0\0', 'binary'), tiff]);
  const header = Buffer.from([0xff, 0xd8, 0xff, 0xe1, (payload.length + 2) >> 8, (payload.length + 2) & 255]);
  return Buffer.concat([header, payload, Buffer.from([0xff, 0xd9])]);
}
let selectedJpegBytes = null;
let libraryPermissionRequests = 0;
const fileSystem = { File: class {
  constructor(uri) { this.uri = uri; }
  get exists() { return selectedJpegBytes !== null; }
  get size() { return selectedJpegBytes?.length || 0; }
  async bytes() { return selectedJpegBytes; }
} };
let removed = 0;
let locationCalls = 0;
let mode = 'fresh';
const library = {
  getPermissionsAsync: async () => ({ granted: false, accessPrivileges: 'limited' }),
  requestPermissionsAsync: async () => { libraryPermissionRequests++; throw new Error('ExpoMediaLibrary.requestPermissionsAsync rejected'); },
  getAssetInfoAsync: async () => ({ location: { latitude: 9.1172482, longitude: 125.5296631 }, creationTime: Date.now() - 86400000 }),
};
const platform = { OS: 'android', Version: 35 };
const native = {
  Accuracy: { High: 6 },
  hasServicesEnabledAsync: async () => mode !== 'disabled',
  requestForegroundPermissionsAsync: async () => ({ granted: mode !== 'denied' }),
  watchPositionAsync: async (_options, callback) => {
    locationCalls++;
    if (mode !== 'timeout') callback({
      timestamp: mode === 'bad-time' ? NaN : Date.now() - (mode === 'stale' ? 60000 : 0),
      coords: { latitude: 9.12, longitude: 125.53, accuracy: mode === 'poor' ? 500 : 8 },
    });
    return { remove: () => removed++ };
  },
};
const services = load('photoLocation', {
  './galleryOperation': galleryOperations,
  './photoLocationMetadata': metadata, 'expo-location': native,
  './photoJpegExif': jpegParser, 'expo-file-system': fileSystem,
  'expo-media-library/legacy': library,
  'expo-constants': { expoVersion: '57.0.0-test', appOwnership: 'expo' },
  'expo-device': { osVersion: 'test-os' },
  'expo/package.json': { version: '57.0.22-test' },
  'react-native': { Platform: platform, PermissionsAndroid: { PERMISSIONS: { ACCESS_MEDIA_LOCATION: 'android.permission.ACCESS_MEDIA_LOCATION' }, check: async () => false } },
}, { setTimeout: (callback) => setTimeout(callback, 10) });

(async () => {
  const decimal = metadata.evidenceFromExif({ GPSLatitude: 9.1172482, GPSLatitudeRef: 'N', GPSLongitude: 125.5296631, GPSLongitudeRef: 'E' });
  assert.equal(decimal.locationSource, 'photo_exif');
  assert.equal(decimal.latitude, 9.1172482);
  const dms = metadata.evidenceFromExif({ GPSLatitude: ['9/1', '7/1', '2/1'], GPSLatitudeRef: 'S', GPSLongitude: [125, 31, 47], GPSLongitudeRef: 'W' });
  assert(dms.latitude < -9 && dms.longitude < -125);
  const nested = metadata.evidenceFromExif({ '{GPS}': { Latitude: 9.1, LatitudeRef: 'N', Longitude: 125.5, LongitudeRef: 'E' } });
  assert.equal(nested.latitude, 9.1);
  for (const input of [null, {}, { GPSLatitude: 0, GPSLatitudeRef: 'N', GPSLongitude: 0, GPSLongitudeRef: 'E' },
    { GPSLatitude: 91, GPSLatitudeRef: 'N', GPSLongitude: 125, GPSLongitudeRef: 'E' },
    { GPSLatitude: [9, 90, 0], GPSLatitudeRef: 'N', GPSLongitude: 125, GPSLongitudeRef: 'E' },
    { GPSLatitude: 9.1, GPSLongitude: 125.5 },
    { GPSLatitude: 9.1, GPSLatitudeRef: 'X', GPSLongitude: 125.5, GPSLongitudeRef: 'E' },
    { GPSLatitude: -9.1, GPSLatitudeRef: 'N', GPSLongitude: 125.5, GPSLongitudeRef: 'E' }]) {
    const empty = metadata.evidenceFromExif(input);
    assert.equal(empty.latitude, null);
    assert.equal(empty.locationStatus, 'no_gps');
  }
  const timed = metadata.evidenceFromExif({ DateTimeOriginal: '2026:01:01 10:00:00', OffsetTimeOriginal: '+08:00' });
  assert.equal(timed.capturedAt, '2026-01-01T02:00:00.000Z');
  assert.equal(metadata.evidenceFromExif({ DateTimeOriginal: '2026:01:01 10:00:00' }).capturedAt, null);
  assert.equal(metadata.validPoint(0, 0), false);
  assert.equal(metadata.exifGpsState({ GPSLatitude: 0, GPSLatitudeRef: 'N', GPSLongitude: 0, GPSLongitudeRef: 'E' }), 'zero_zero');
  assert.equal(metadata.exifGpsState({ GPSLatitude: 9, GPSLongitude: 125 }), 'missing_references');
  assert.equal(metadata.exifGpsState({ GPSLatitude: 9, GPSLatitudeRef: 'Q', GPSLongitude: 125, GPSLongitudeRef: 'E' }), 'invalid_references');
  assert.equal(metadata.exifGpsState({ GPSLatitude: 9, GPSLatitudeRef: 'N', GPSLongitude: 125, GPSLongitudeRef: 'E' }), 'valid');
  const jpegPoint = jpegParser.selectedJpegGps(gpsJpeg());
  assert.equal(jpegPoint.state, 'valid');
  assert(Math.abs(jpegPoint.evidence.latitude - 9.1166666667) < 0.0000001);
  assert(Math.abs(jpegPoint.evidence.longitude - 125.5166666667) < 0.0000001);
  assert(jpegParser.selectedJpegGps(gpsJpeg([9, 7, 0], [125, 31, 0], 'S', 'W')).evidence.latitude < 0);
  assert.equal(jpegParser.selectedJpegGps(gpsJpeg([0, 0, 0], [0, 0, 0])).state, 'zero_zero');
  assert.equal(jpegParser.selectedJpegGps(gpsJpeg([9, 7, 0], [125, 31, 0], '', 'E')).state, 'missing_references');
  assert.equal(jpegParser.selectedJpegGps(Buffer.from([0xff, 0xd8, 0xff, 0xd9])).evidence.latitude, null);
  assert.equal(metadata.damageMapRegion(0, 0), null, 'Map must not center on Null Island');
  assert.equal(metadata.damageMapRegion(9.12, 125.53).latitude, 9.12);
  assert.equal(metadata.damageMapRegion(9.13, 125.54).longitude, 125.54, 'A new photo point must update the map camera target');
  const gallery = await services.galleryPhotoEvidence({ exif: { GPSLatitude: 8, GPSLatitudeRef: 'N', GPSLongitude: 126, GPSLongitudeRef: 'E' } });
  assert.equal(gallery.latitude, 8, 'Gallery must retain photo location, not phone position');
  const noGps = await services.galleryPhotoEvidence({ exif: null });
  assert.equal(noGps.latitude, null);
  const limited = await services.galleryPhotoEvidence({ assetId: 'selected-only', exif: null });
  assert.equal(limited.locationSource, 'asset_location');
  assert.equal(limited.latitude, 9.1172482);
  assert.equal(metadata.photoLocationLabel(limited.locationSource, true), 'Original library asset location');
  const androidOriginal = await services.galleryPhotoEvidence({ assetId: 'android-original', exif: { GPSLatitude: 0, GPSLatitudeRef: 'N', GPSLongitude: 0, GPSLongitudeRef: 'E' } });
  assert.equal(androidOriginal.latitude, 9.1172482, 'A valid original-asset location must override invalid picker 0,0');
  const edited = await services.galleryPhotoEvidence({ assetId: 'edited-location', exif: { GPSLatitude: 8, GPSLatitudeRef: 'N', GPSLongitude: 126, GPSLongitudeRef: 'E', GPSHPositioningError: 2 } });
  assert.equal(edited.accuracy, null, 'Library location must not inherit another point accuracy');
  assert.equal(gallery.latitude, 8, 'Reading a later image must not mutate earlier evidence');
  library.getAssetInfoAsync = async () => ({ location: { latitude: 0, longitude: 0 }, exif: { GPSLatitude: 0, GPSLatitudeRef: 'N', GPSLongitude: 0, GPSLongitudeRef: 'E' } });
  const androidZero = await services.galleryPhotoEvidence({ assetId: 'android-zero', exif: { GPSLatitude: 0, GPSLatitudeRef: 'N', GPSLongitude: 0, GPSLongitudeRef: 'E' } });
  assert.equal(androidZero.latitude, null);
  assert.equal(androidZero.locationStatus, 'no_gps');
  assert.equal(metadata.photoLocationLabel(androidZero.locationSource, metadata.validPoint(androidZero.latitude, androidZero.longitude)), 'No accessible GPS');
  assert.match(androidZero.locationIssue, /No usable GPS/);
  platform.OS = 'ios';
  library.getAssetInfoAsync = async () => ({ location: { latitude: 9.13, longitude: 125.54 }, exif: null });
  const iphoneHeic = await services.galleryPhotoEvidence({ assetId: 'iphone-geotagged-heic', fileName: 'location.heic', exif: null });
  assert.equal(iphoneHeic.latitude, 9.13, 'Accessible original HEIC library location must be used');
  library.getAssetInfoAsync = async () => ({ location: null, exif: null });
  const iphoneNoGps = await services.galleryPhotoEvidence({ assetId: 'iphone-no-location', fileName: 'IMG_8484.HEIC', exif: null });
  assert.equal(iphoneNoGps.latitude, null);
  assert.match(iphoneNoGps.locationIssue, /no saved location/);
  library.getPermissionsAsync = async () => ({ granted: false, accessPrivileges: 'none' });
  const deniedLibrary = await services.galleryPhotoEvidence({ assetId: 'library-denied', exif: null });
  assert.equal(deniedLibrary.latitude, null);
  assert.match(deniedLibrary.locationIssue, /current photo-library permission/);
  library.getPermissionsAsync = async () => ({ granted: false, accessPrivileges: 'limited' });
  platform.OS = 'android';
  let diagnostic;
  const diagnosticServices = load('photoLocation', {
    './galleryOperation': galleryOperations,
    './photoLocationMetadata': metadata, 'expo-location': native,
    './photoJpegExif': jpegParser, 'expo-file-system': fileSystem,
    'expo-media-library/legacy': library,
    'expo-constants': { expoVersion: '57.0.0-test', appOwnership: 'expo' },
    'expo-device': { osVersion: 'test-os' },
    'expo/package.json': { version: '57.0.22-test' },
    'react-native': { Platform: platform, PermissionsAndroid: { PERMISSIONS: { ACCESS_MEDIA_LOCATION: 'android.permission.ACCESS_MEDIA_LOCATION' }, check: async () => false } },
  }, { __DEV__: true, console: { info: (_label, details) => { diagnostic = details; } } });
  const missingId = await diagnosticServices.galleryPhotoEvidence({ uri: 'file:///private/9.1172482.jpg', assetId: null, exif: null });
  assert.equal(missingId.latitude, null);
  assert.match(missingId.locationIssue, /farmer-confirmed damage pin/);
  assert.equal(diagnostic.assetId, null);
  assert.equal(diagnostic.originalContentUriExposedByImagePicker, false);
  assert.equal(diagnostic.mediaLocationPermission, 'not_granted_or_not_in_manifest');
  assert.equal(diagnostic.unavailableReason, 'asset_id_missing');
  assert.equal(diagnostic.expoSdkPackageVersion, '57.0.22-test');
  assert(!JSON.stringify(diagnostic).includes('/private/'), 'Diagnostics must not leak selected URI paths');
  selectedJpegBytes = gpsJpeg();
  const cacheExif = await diagnosticServices.galleryPhotoEvidence({ uri: 'file:///cache/original.jpg', assetId: null, mimeType: 'image/jpeg', exif: null });
  assert.equal(cacheExif.locationSource, 'photo_exif', 'Valid selected JPEG GPS must work even without an asset ID');
  assert.equal(diagnostic.selectedJpegExifGps, 'valid');
  selectedJpegBytes = gpsJpeg([0, 0, 0], [0, 0, 0]);
  const cacheZero = await diagnosticServices.galleryPhotoEvidence({ uri: 'file:///cache/zero.jpg', assetId: null, mimeType: 'image/jpeg', exif: null });
  assert.equal(cacheZero.latitude, null);
  assert.equal(diagnostic.selectedJpegExifGps, 'zero_zero');
  selectedJpegBytes = null;
  library.getAssetInfoAsync = async () => { throw new Error('missing ACCESS_MEDIA_LOCATION permission'); };
  const missingNativePermission = await diagnosticServices.galleryPhotoEvidence({ uri: 'file:///cache/photo.jpg', assetId: 'asset-with-id', exif: null });
  assert.match(missingNativePermission.locationIssue, /No accessible photo GPS.*media-location access/);
  assert.equal(diagnostic.unavailableReason, 'access_media_location_missing_or_denied');
  assert.equal(libraryPermissionRequests, 0, 'Expo Go Gallery must not call the failing MediaLibrary permission request');
  library.getAssetInfoAsync = async () => ({ location: { latitude: 9.1172482, longitude: 125.5296631 }, exif: null });
  library.requestPermissionsAsync = async () => { libraryPermissionRequests++; return { granted: true, accessPrivileges: 'all' }; };
  const customBuildServices = load('photoLocation', {
    './galleryOperation': galleryOperations,
    './photoLocationMetadata': metadata, 'expo-location': native,
    './photoJpegExif': jpegParser, 'expo-file-system': fileSystem,
    'expo-media-library/legacy': library,
    'expo-constants': { expoVersion: null, appOwnership: null },
    'expo-device': { osVersion: 'test-os' },
    'expo/package.json': { version: '57.0.22-test' },
    'react-native': { Platform: platform, PermissionsAndroid: { PERMISSIONS: { ACCESS_MEDIA_LOCATION: 'android.permission.ACCESS_MEDIA_LOCATION' }, check: async () => true } },
  });
  const customBuildLocation = await customBuildServices.galleryPhotoEvidence({ assetId: 'selected-original', exif: null });
  assert.equal(customBuildLocation.latitude, 9.1172482);
  assert.equal(libraryPermissionRequests, 1, 'Only a custom Android build may request original-media permission');
  assert.equal(locationCalls, 0, 'Gallery must never call device location');
  assert.equal(typeof services.requestPhotoLibraryMetadataAccess, 'undefined', 'Normal picker must not request library permission');
  assert.equal(typeof services.originalLibraryPickerAsset, 'undefined', 'Failing original-library picker must be removed');
  library.getAssetInfoAsync = async () => { throw new Error('Original unavailable'); };
  assert.equal((await services.galleryPhotoEvidence({ assetId: 'shared-photo' })).latitude, null);
  mode = 'denied';
  assert.equal((await services.requestCaptureLocationAccess()).locationStatus, 'permission_denied');
  mode = 'disabled';
  assert.equal((await services.requestCaptureLocationAccess()).locationStatus, 'services_disabled');
  mode = 'fresh';
  const camera = await services.freshCaptureEvidence(Date.now());
  assert.equal(camera.locationSource, 'device_capture');
  assert.equal(camera.accuracy, 8);
  assert.equal(camera.captureTimeSource, 'camera_return');
  assert.equal(camera.latitude, 9.12);
  for (const [testMode, status] of [['poor', 'poor_accuracy'], ['stale', 'stale_fix'], ['timeout', 'timeout'], ['bad-time', 'stale_fix']]) {
    mode = testMode;
    const failed = await services.freshCaptureEvidence(Date.now());
    assert.equal(failed.locationStatus, status);
    if (testMode !== 'poor') assert.equal(failed.latitude, null, 'Never reuse previous camera GPS');
  }
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(removed, 5, 'Each foreground location watch must be stopped');
  const delayedCapture = await services.freshCaptureEvidence(Date.now(), { DateTimeOriginal: '2026:01:01 10:00:00', OffsetTimeOriginal: '+08:00' });
  assert.equal(delayedCapture.locationStatus, 'stale_fix');
  assert.equal(delayedCapture.captureTimeSource, 'exif');
  assert.equal(delayedCapture.latitude, null);
  assert(metadata.pointDistanceMeters({ latitude: 9.1, longitude: 125.5 }, { latitude: 9.2, longitude: 125.5 }) > 10000);
  // Exercise the real multipart builder, including a deletion that shifts indexes.
  let sent;
  class TestFormData {
    fields = [];
    append(...entry) { this.fields.push(entry); }
  }
  const api = load('farmerDataApi', {
    'expo/fetch': { fetch: async (url, options) => {
      sent = { url, options };
      return { ok: true, status: 201, headers: { get: () => null }, text: async () => JSON.stringify({ success: true, data: { id: 'controlled' } }) };
    } },
    'expo-file-system': { File: class { constructor(uri) { this.uri = uri; this.exists = true; this.size = 100; } } },
    '@/config/api': { getApiUrl: (route) => 'https://controlled.invalid/api' + route },
    '@/services/secureFarmerSession': { readFarmerToken: async () => 'controlled-token-never-printed' },
    '@/services/apiFailure': load('apiFailure'),
    '@/services/uploadImages': { MAX_DAMAGE_PHOTOS: 10, MAX_DAMAGE_IMAGE_BYTES: 5242880, MAX_DAMAGE_TOTAL_BYTES: 33554432 },
  }, { FormData: TestFormData });
  const first = { id: 'first', uri: 'first.jpg', ...gallery };
  const second = { id: 'second', uri: 'second.jpg', ...camera };
  const input = { farmId: 1, farmType: 'Crops', description: 'Test',
    detailFields: { damage_details_version: '1', subject_type: 'Rice', subject_type_other: '', suspected_cause: 'Unsure', suspected_cause_other: '', crop_damage_percent: '25', planted_area_ha: '', affected_area_ha: '' },
    damageAddress: 'Controlled location', damageLatitude: camera.latitude, damageLongitude: camera.longitude,
    clientSubmissionId: 'controlled_submission_123', locationConfirmed: true, locationSource: 'photo', locationPhotoId: second.id,
    photos: [first, second] };
  await api.submitDamageReport(input);
  const field = (key) => sent.options.body.fields.find(([name]) => name === key)?.[1];
  assert.equal(field('photo_latitude[0]'), String(first.latitude));
  assert.equal(field('photo_latitude[1]'), String(second.latitude));
  assert.equal(field('photo_capture_time_source[1]'), 'camera_return');
  assert.equal(field('location_photo_order'), '2');
  assert.equal(field('damage_details_version'), '1');
  assert.equal(field('crop_damage_percent'), '25');
  assert.equal(field('suspected_cause'), 'Unsure');
  for (const legacy of ['damage_type', 'custom_damage_type', 'estimated_damage_cost']) assert.equal(field(legacy), undefined, legacy + ' is not sent by new reports');
  assert.equal(sent.options.headers['Content-Type'], undefined, 'Transport must generate multipart boundary');
  assert.equal(sent.options.headers.Authorization.startsWith('Bearer '), true);
  await api.submitDamageReport({ ...input, photos: [second] });
  assert.equal(field('location_photo_order'), '1');
  assert.equal(field('photo_latitude[0]'), String(second.latitude));
  assert.equal(field('client_submission_id'), input.clientSubmissionId);
  assert.equal(field('photo_latitude[1]'), undefined);
  await api.submitDamageReport({ ...input, photos: [second, first] });
  assert.equal(field('photo_latitude[0]'), String(second.latitude));
  assert.equal(field('photo_latitude[1]'), String(first.latitude));
  assert.equal(field('location_photo_order'), '1', 'Reordering must preserve the selected photo identity and update its order');
  assert.equal(sent.url, 'https://controlled.invalid/api/reports/index.php');
  const reportScreen = fs.readFileSync(path.join(__dirname, '../../frontend/mobile/src/app/reports/new.tsx'), 'utf8');
  const manualMap = fs.readFileSync(path.join(__dirname, '../../frontend/mobile/src/components/damage-manual-pin-map.native.tsx'), 'utf8');
  assert(!reportScreen.includes('Browse original photo library'), 'Failing original-library UI must be removed');
  assert(!reportScreen.includes('requestPhotoLibraryMetadataAccess'), 'Expo Go Gallery must open without a MediaLibrary permission request');
  assert(reportScreen.includes("galleryPhotoEvidence(assets[index])"));
  assert(reportScreen.indexOf('galleryPhotoEvidence(assets[index])') < reportScreen.indexOf('preparePickedImage(assets[index]'), 'Read selected GPS before conversion');
  assert(reportScreen.includes("changeLocation(manualDraft.latitude.toFixed(7), manualDraft.longitude.toFixed(7), 'manual')"));
  assert(manualMap.includes('onPress={(event) => choose(event.nativeEvent.coordinate)}'));
  assert(manualMap.includes('point && validPoint(point.latitude, point.longitude) && <Marker'), 'Map must not invent an initial damage pin');
  // A stuck native metadata operation must settle without blocking selection.
  platform.OS = 'ios';
  library.getPermissionsAsync = () => new Promise(() => {});
  const hungPermission = await services.galleryPhotoEvidence({ assetId: 'hung-permission', exif: null });
  assert.equal(hungPermission.latitude, null);
  assert.match(hungPermission.locationIssue, /permission check timed out/);
  library.getPermissionsAsync = async () => ({ granted: true, accessPrivileges: 'all' });
  let completeLate;
  library.getAssetInfoAsync = () => new Promise((resolve) => { completeLate = resolve; });
  const hungAsset = await services.galleryPhotoEvidence({ assetId: 'hung-asset', exif: null });
  assert.equal(hungAsset.latitude, null);
  assert.match(hungAsset.locationIssue, /metadata reading timed out/);
  completeLate({ location: { latitude: 9.12, longitude: 125.53 } });
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(hungAsset.latitude, null, 'Late native metadata must not mutate returned evidence');
  const retainedExif = await services.galleryPhotoEvidence({ assetId: 'hung-original', exif: { GPSLatitude: 9, GPSLatitudeRef: 'N', GPSLongitude: 125, GPSLongitudeRef: 'E' } });
  assert.equal(retainedExif.latitude, 9, 'Timeout must retain genuine EXIF for this photo only');
  for (const stage of ['sheet_dismiss', 'picker', 'conversion']) {
    await assert.rejects(galleryOperations.galleryOperation(stage, () => new Promise(() => {}), 1), (error) => error.stage === stage);
  }
  assert.equal(await galleryOperations.galleryOperation('picker', async () => ({ canceled: true }), 1).then((r) => r.canceled), true);
  await assert.rejects(galleryOperations.galleryOperation('conversion', () => { throw new Error('conversion failed'); }, 1), /conversion failed/);
  const events = [];
  const loggedOperations = load('galleryOperation', {}, { __DEV__: true, console: { info: (...args) => events.push(args) } });
  await assert.rejects(loggedOperations.galleryOperation('conversion', async () => { throw new Error('file:///private/9.12,125.53.jpg'); }, 10));
  assert(!JSON.stringify(events).includes('private'), 'Stage failures must not log native error paths');
  assert(!JSON.stringify(events).includes('125.53'), 'Stage failures must not log coordinates');
  assert(reportScreen.includes('onDismiss={() => gallerySheetDismissed.current?.()}'), 'iOS must wait for modal dismissal');
  assert(reportScreen.includes("galleryEvent('loading_cleared')"));
  console.log('PASS: bounded gallery permission/metadata, late-result isolation, preserved picker EXIF, cancellation/exception/timeout handling and privacy-safe stage logs.');
  console.log('PASS: multipart builder preserves per-photo GPS/timestamps after add, removal and reorder; selected-photo order, bearer auth, central API URL and retry identifier.');
  console.log('PASS: EXIF decimal/DMS/nested and raw JPEG GPS, hemisphere signs, 0,0/missing-reference rejection, no-asset-ID fallback, privacy-safe diagnostics, mocked Android/iPhone library and HEIC access, map target updates, camera fix and failure states. Native APIs mocked; not phone verification.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
