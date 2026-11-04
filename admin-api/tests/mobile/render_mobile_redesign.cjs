// Offline layout QA only: actual screen JSX/styles rendered through React Native
// Web at 393x852 (1179x2556 at 3x). Controlled fixtures stay outside the app.
// Native maps, secure image transport, UIKit and EXIF are NOT simulated as passes.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { walk, text, loadMetadata, loadDamageDetails, flush } = require('./mobile_redesign.cjs');
const modules = path.resolve(__dirname, '../../frontend/mobile/node_modules');
const React = require(path.join(modules, 'react'));
const jsx = require(path.join(modules, 'react/jsx-runtime'));
const RN = require(path.join(modules, 'react-native-web'));
const DOM = require(path.join(modules, 'react-dom/server'));
const ts = require(path.join(modules, 'typescript'));
const root = path.resolve(__dirname, '../../frontend/mobile/src');
const out = path.resolve(__dirname, '../.artifacts/redesign-review');
const asset = file => 'file:///' + path.resolve(__dirname, '../../frontend/mobile', file).replaceAll('\\', '/').replaceAll(' ', '%20');
const routing = { router: { push() {}, replace() {}, back() {} }, useFocusEffect: fn => React.useEffect(fn, [fn]) };
// Platform services are boundary doubles only. Shared JSX and native-web styles
// below come from the shipping components, not hand-recreated mockup CSS.
function ScenicImage({ source, style, contentFit, contentPosition, ...rest }) {
  const pos = contentPosition ? `${contentPosition.left || '50%'} ${contentPosition.top || '50%'}` : 'center';
  return React.createElement('img', { src: typeof source === 'string' ? source : source.uri, alt: '', style: { ...RN.StyleSheet.flatten(style), objectFit: contentFit || 'cover', objectPosition: pos } });
}
const shared = {};
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, 'components/farm-ui.tsx'), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2020 },
}).outputText, { exports: shared, require: name => {
  if (name.startsWith('@/assets/')) return asset(name.slice(2));
  return ({ react: React, 'react/jsx-runtime': jsx, 'react-native': RN,
    'expo-router': routing, 'expo-image': { Image: ScenicImage }, 'expo-status-bar': { StatusBar: () => null },
    'react-native-safe-area-context': { SafeAreaView: props => React.createElement(RN.View, { style: [props.style, { paddingTop: 52 }] }, props.children), useSafeAreaInsets: () => ({ bottom: 24 }) },
    'expo-symbols': { SymbolView: ({ name, size, tintColor }) => React.createElement('span', { style: { fontFamily: 'Material', fontSize: size, color: tintColor, lineHeight: 1, width: size, height: size, display: 'inline-block', flexShrink: 0 }, 'aria-hidden': true }, name.web) },
  })[name];
}, setTimeout, clearTimeout });
function LayoutMap({ latitude, longitude, label }) {
  return React.createElement(RN.View, { style: { backgroundColor: '#E8EFDF', borderRadius: 12, padding: 12, gap: 6, marginVertical: 8 } },
    React.createElement(RN.Text, { style: shared.ui.small }, 'Native map preview: device test pending'),
    React.createElement(RN.Text, { style: shared.ui.body }, label + ': ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7)),
    React.createElement(shared.Action, { title: 'Open exact point in Google Maps', onPress() {}, secondary: true }));
}
const farm = { id: 27, name: 'Layout QA farm', status: 'Active', agriculturalCategory: 'Crops', barangayName: 'Tolosa', address: 'Tolosa, Cabadbaran City', landArea: 2.5, mainProduct: 'Rice', latitude: null, longitude: null };
const account = { farmer: { id: 1, fullName: 'Layout QA farmer', firstName: 'Layout QA farmer', lastName: 'Account', email: 'long-email-layout-check@example.test', contactNumber: '+639000000000', address: 'Tolosa, Cabadbaran City, Agusan del Norte', accountStatus: 'Active', gender: 'Not provided', dateOfBirth: null, farm: { id: 27, name: farm.name, type: 'Crops', location: farm.address, sizeHectares: 2.5, mainProduct: 'Rice' } }, refreshSession: async () => {}, logout: async () => {} };
const advice = { id: 'qa-advice', category: 'Crops', title: 'Layout QA: review drainage after rain', message: 'Controlled test recommendation. Check field drainage and monitor crops after heavy rain.', locationCoverage: 'Cabadbaran City, Agusan del Norte', effectiveDate: '2026-10-10', expirationDate: '2026-10-12', publishedAt: '2026-10-10T07:00:00+08:00', updatedAt: '2026-10-10', advisoryKind: 'Admin-created rule-based advisory' };
const api = { FarmerDataApiError: class extends Error {}, listFarmerFarms: async () => ({ farms: [farm], barangays: [] }), listWeatherAdvisories: async () => ({ items: [advice] }), listDamageReports: async () => ['Pending', 'Under Review', 'Resolved'].map((status, i) => ({ id: 'qa-report-' + i, farmName: farm.name, farmType: i === 1 ? 'Livestock' : 'Crops', description: 'Controlled layout description with enough text to verify two-line wrapping.', date: '2026-10-10', status, photos: [] })), listFarmerNotifications: async () => [{ id: 'qa-alert', title: 'Damage report resolved', shortMessage: 'Controlled notification preview: the assessment is available in your report.', fullMessage: 'Controlled complete message', isRead: false, relatedReportId: 'qa-report', type: 'success', createdAt: '2026-10-10T07:00:00+08:00' }, { id: 'qa-alert2', title: 'Admin response', shortMessage: 'Controlled preview of a response to a damage report.', fullMessage: 'Controlled complete response', isRead: true, relatedReportId: 'qa-report', type: 'info', createdAt: '2026-10-09T07:00:00+08:00' }], validateDamagePoint: async () => {}, getCurrentWeather: async () => ({ temperature: 29.8, temperatureUnit: '°C', condition: 'Drizzle', humidity: 69, humidityUnit: '%', windSpeed: 7.2, windSpeedUnit: 'km/h', rain: 0, rainUnit: 'mm', weatherCode: 51, location: 'Cabadbaran City', provider: 'Open-Meteo', observationType: 'Current model conditions', observedAt: '2026-10-10T07:15:00+08:00', fetchedAt: '2026-10-10T07:23:00+08:00' }) };
function create(file) {
  // Screen state uses the regression harness; child components render real React
  // and RN Web. No production auth, API, picker or app files are rewritten.
  let model;
  const router = { ...routing, useFocusEffect: fn => fakeReact.useEffect(fn, [fn]) };
  const overrides = { 'react/jsx-runtime': jsx, 'react-native': { ...RN, Platform: { OS: 'ios' }, useWindowDimensions: () => ({ width: 393, height: 852 }) },
    '@/components/farm-ui': shared, 'expo-router': router, '@/services/farmerDataApi': api,
    '@/state/farmerAuthContext': { useFarmerAuth: () => account },
    // Real name helpers used by the profile editor (pure functions, no app data).
    '@/utils/farmerName': (() => { const exported = {}; vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, 'utils/farmerName.ts'), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports: exported }); return exported; })(),
    '@/components/damage-location-map': LayoutMap,
    '@/components/damage-manual-pin-map': () => null,
    'expo-location': {},
    '@/components/protected-report-photo': () => { throw Error('Layout fixture has no saved photos; protected loading is tested separately.'); },
    'react-native-safe-area-context': { SafeAreaView: RN.View },
    '@/services/photoLocationMetadata': loadMetadata(),
    '@/utils/damageDetails': loadDamageDetails(),
    '@/services/galleryOperation': { galleryEvent() {}, galleryOperation: async (_stage, fn) => fn(), GalleryTimeoutError: class extends Error {} },
    '@/services/androidSystemPhotoPicker': { androidOriginalPickerAvailable: () => false }, '@/services/photoLocation': {},
    '@/services/galleryPhotoEvidence': { galleryPhotoEvidence: async a => ({ ...loadMetadata().emptyPhotoEvidence(), latitude: a.lat, longitude: a.lon, locationSource: 'photo_exif', locationStatus: 'available', locationIssue: null }) },
    '@/services/uploadImages': { MAX_DAMAGE_PHOTOS: 10, MAX_DAMAGE_IMAGE_BYTES: 5242880, MAX_DAMAGE_TOTAL_BYTES: 33554432, UploadImagePreparationError: class extends Error {}, preparePickedImage: async a => ({ uri: a.uri, size: 100, mimeType: 'image/png', fileName: 'layout-qa.png' }) },
    'expo-image-picker': { UIImagePickerPreferredAssetRepresentationMode: { Current: 'current' }, launchImageLibraryAsync: async () => ({ canceled: false, assets: [0, 1].map(i => ({ uri: 'data:image/svg+xml,' + encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="300" height="210"><rect width="300" height="210" fill="${i ? '#779986' : '#567568'}"/><text x="30" y="115" fill="white" font-size="28">TEST PHOTO ${i + 1}</text></svg>`), lat: 9.12 + i * .001, lon: 125.53 + i * .001 })) }) },
  };
  // Access harness hooks without exposing state slots: capture them in the
  // module loader's React boundary for useFocusEffect.
  const state = [], effects = []; let cursor = 0;
  const fakeReact = { ...React,
    useState: init => { const id = cursor++; if (!(id in state)) state[id] = typeof init === 'function' ? init() : init; return [state[id], value => { state[id] = typeof value === 'function' ? value(state[id]) : value; }]; },
    useEffect: (fn, deps = []) => { const id = cursor++; if (!state[id] || deps.some((v, i) => v !== state[id][i])) { state[id] = deps; effects.push(fn); } },
    useRef: value => fakeReact.useState({ current: value })[0],
    useMemo: (fn, deps) => { const id = cursor++; if (!state[id] || deps.some((v, i) => v !== state[id].deps[i])) state[id] = { deps, value: fn() }; return state[id].value; },
    useCallback: (fn, deps) => fakeReact.useMemo(() => fn, deps),
  };
  const exported = {};
  const screenShared = { ...shared, useReducedMotion: () => true };
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2020 } }).outputText, {
    exports: exported, require: name => name === 'react' ? fakeReact : name === '@/components/farm-ui' ? screenShared : name in overrides ? overrides[name] : (() => { throw Error('Unexpected layout import ' + name); })(), Date, __DEV__: false, setTimeout: () => 1, clearTimeout() {},
  });
  model = { render() { cursor = 0; const result = exported.default(); for (const effect of effects.splice(0)) effect(); return result; } };
  return model;
}
function findButton(model, title) { return walk(model.render()).find(n => n.props?.accessibilityRole === 'button' && text(n) === title); }
function save(name, tree) {
  const app = React.createElement(RN.View, { style: { width: 393, height: 852 } }, tree);
  const html = DOM.renderToString(app);
  const sheet = RN.StyleSheet.getSheet();
  fs.writeFileSync(path.join(out, name + '.html'), `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${sheet.textContent}</style><style>@font-face{font-family:Material;src:url('${asset('node_modules/@expo-google-fonts/material-symbols/400Regular/MaterialSymbols_400Regular.ttf')}')}html,body{margin:0;width:100%;height:100%;background:#F7F5EE}*{box-sizing:border-box}</style></head><body>${html}</body></html>`);
  if (['profile', 'report-step2', 'report-step3'].includes(name)) {
    const page = fs.readFileSync(path.join(out, name + '.html'), 'utf8');
    fs.writeFileSync(path.join(out, name + '-bottom.html'), page.replace('</body>', `<script>document.fonts.ready.then(()=>{for(const el of document.querySelectorAll('div')){if(['auto','scroll'].includes(getComputedStyle(el).overflowY)&&el.scrollHeight>el.clientHeight+100){el.scrollTop=el.scrollHeight;break;}}});</script></body>`));
  }
}
(async () => {
  fs.mkdirSync(out, { recursive: true });
  for (const [name, file] of [['home', 'farmer-home'], ['reports', 'my-reports'], ['advice', 'weather-advice'], ['alerts', 'notifications'], ['profile', 'profile'], ['farms', 'my-farms']]) {
    const model = create('app/' + file + '.tsx'); model.render(); await flush(); save(name, model.render());
  }
  const wizard = create('app/reports/new.tsx'); wizard.render(); await flush();
  const field = label => walk(wizard.render()).find(n => n.props?.label === label);
  walk(wizard.render()).find(n => n.props?.title === 'Select crop' && n.props.onSelect).props.onSelect('Rice');
  walk(wizard.render()).find(n => n.props?.title === 'Suspected cause' && n.props.onSelect).props.onSelect('Flooding');
  field('What happened?').props.onChangeText('Controlled layout description: crops were submerged after heavy rain. Review the field and drainage before assessment.');
  field('Estimated crop damage').props.onChangeText('40');
  field('Planted area').props.onChangeText('2.5');
  field('Affected area').props.onChangeText('1');
  save('report-step1', wizard.render());
  findButton(wizard, 'Next: Photos & location').props.onPress();
  await findButton(wizard, 'Choose from Gallery').props.onPress(); await flush();
  field('Address / Landmark').props.onChangeText('Controlled layout landmark, Cabadbaran City');
  await findButton(wizard, 'This is the damaged location').props.onPress(); await flush();
  save('report-step2', wizard.render());
  findButton(wizard, 'Next: Review report').props.onPress(); save('report-step3', wizard.render());
  fs.writeFileSync(path.join(out, 'README.txt'), 'Offline layout previews: actual screen JSX/styles, controlled test data, React Native Web. 393x852 logical pixels, capture at 3x = 1179x2556. Maps are explicitly not native previews. No app mock data or production writes. These are NOT physical-device acceptance screenshots.');
  const names = ['home','reports','advice','alerts','profile','farms','report-step1','report-step2','report-step3','profile-bottom','report-step2-bottom','report-step3-bottom'];
  fs.writeFileSync(path.join(out, 'index.html'), `<!doctype html><html><head><meta charset="utf-8"><title>AgriReach layout review</title><style>body{font:16px system-ui;background:#F7F5EE;color:#172B24;margin:24px}main{display:flex;flex-wrap:wrap;gap:24px}figure{margin:0;width:280px}img{width:100%;border:1px solid #ddd;border-radius:12px}a{color:#174D36}header{max-width:900px;margin-bottom:24px}</style></head><body><header><h1>AgriReach layout review</h1><p>Actual screen JSX/styles, rendered offline through React Native Web with controlled test data outside the app. Each PNG is 1179 × 2556 (393 × 852 at 3×), matching the supplied phone screenshot dimensions.</p><p>These are not phone acceptance screenshots. Native maps are explicitly marked pending; secure photo transport, GPS extraction, font scaling, keyboard and native picker presentation require separate tests. No mock data was added to AgriReach.</p></header><main>${names.map(name=>`<figure><figcaption><a href="${name}.png">${name}</a></figcaption><a href="${name}.png"><img alt="${name} code-derived layout preview" src="${name}.png"></a></figure>`).join('')}</main></body></html>`);
  console.log('Wrote nine offline screen previews at ' + out);
})().catch(error => { console.error(error); process.exitCode = 1; });
