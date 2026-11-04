// Controlled UI/data harness. No phone/native rendering claims and no production API writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const root = path.join(__dirname, '../../frontend/mobile/src');
const flush = async () => { for (let i = 0; i < 50; i++) await Promise.resolve(); };
const jsx = (type, props) => ({ type, props });
function walk(node) {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(walk);
  return [node, ...walk(node.props?.children)];
}
function text(node) {
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(text).join('');
  return node && node.props ? text(node.props.children) : '';
}
const button = (tree, title) => walk(tree).find(n => n.type === 'Pressable' && walk(n).some(child => child.type === 'Text' && text(child) === title));
function harness(file, overrides = {}, os = 'android', width = 320) {
  const state = [], effects = [], timers = new Map(); let cursor = 0, timerId = 0;
  const useState = value => {
    const id = cursor++; if (!(id in state)) state[id] = typeof value === 'function' ? value() : value;
    return [state[id], next => { state[id] = typeof next === 'function' ? next(state[id]) : next; }];
  };
  const memo = (fn, deps) => {
    const id = cursor++;
    if (!state[id] || deps.some((v, i) => v !== state[id].deps[i])) state[id] = { deps, value: fn() };
    return state[id].value;
  };
  const useEffect = (fn, deps = []) => {
    const id = cursor++;
    if (!state[id] || deps.some((v, i) => v !== state[id][i])) { state[id] = deps; effects.push(fn); }
  };
  const native = Object.fromEntries(['ActivityIndicator','Image','KeyboardAvoidingView','Modal','Pressable','ScrollView','Text','TextInput','View','FlatList','SectionList','RefreshControl'].map(n => [n,n]));
  const auth = { farmer: { id: 1 }, refreshSession: async () => {} };
  const mocks = {
    react: { useState, useEffect, useRef: value => useState({ current: value })[0], useCallback: (fn,deps) => memo(() => fn,deps), useMemo: memo },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { ...native, Platform: { OS: os }, StyleSheet: { create: v => v }, useWindowDimensions: () => ({ width, height: 700 }) },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView', SafeAreaProvider: 'SafeAreaProvider' },
    'expo-router': { router: { push: () => {}, replace: () => {} }, useLocalSearchParams: () => ({ id: 'controlled-report' }), useFocusEffect: fn => useEffect(fn,[fn]) },
    '@/components/farm-ui': { AppScreen: 'AppScreen', Hero: 'Hero', Icon: 'Icon', Motion: 'Motion', Action: 'Action', EmptyState: 'EmptyState', SectionHeading: 'SectionHeading', StatusBadge: 'StatusBadge', Badge: 'Badge', palette: { forest: '#174D36', red: '#A83F35' }, ui: {}, useReducedMotion: () => true, formatDate: v => v || 'Not recorded', categoryIcon: () => 'leaf' },
    '@/state/farmerAuthContext': { useFarmerAuth: () => auth },
    '@/components/damage-location-map': 'DamageLocationMap',
    '@/components/damage-manual-pin-map': 'DamageManualPinMap',
    '@/components/protected-report-photo': 'ProtectedReportPhoto',
    'expo-location': {},
    'expo-image-picker': {},
    '@/utils/damageDetails': loadDamageDetails(),
    ...overrides,
  };
  const exports = {};
  mocks['./farm-ui'] = mocks['@/components/farm-ui'];
  const js = ts.transpileModule(fs.readFileSync(path.join(root,file),'utf8'), { compilerOptions: { module:ts.ModuleKind.CommonJS, target:ts.ScriptTarget.ES2020, jsx:ts.JsxEmit.ReactJSX } }).outputText;
  vm.runInNewContext(js, { exports, require: name => { if (!(name in mocks)) throw Error('Unexpected import '+name); return mocks[name]; }, __DEV__: false, Date, fetch: overrides.fetch, AbortController, setTimeout: (fn, delay) => { timers.set(++timerId,{fn,delay}); return timerId; }, clearTimeout: id => timers.delete(id) });
  return {
    render(props) { cursor = 0; const tree = exports.default(props); for (const effect of effects.splice(0)) effect(); return tree; },
    timers, expire(delay) { const timer = [...timers.values()].find(t => t.delay === delay); assert(timer); timer.fn(); },
  };
}
function loadDamageDetails() {
  const exports={};
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root,'utils/damageDetails.ts'),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2020}}).outputText,{exports});
  return exports;
}
function loadMetadata() {
  const exports={};
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root,'services/photoLocationMetadata.ts'),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText,{exports});
  return exports;
}
async function wizard(os, width) {
  const metadata=loadMetadata(); let submissions=[], resolveSubmit, boundary=[];
  const model=harness('app/reports/new.tsx',{
    '@/services/photoLocationMetadata': metadata,
    '@/services/galleryOperation': { galleryEvent:()=>{}, galleryOperation: async (_stage,fn)=>fn(), GalleryTimeoutError:class extends Error{} },
    '@/services/androidSystemPhotoPicker': { androidOriginalPickerAvailable:()=>false },
    '@/services/photoLocation': {},
    '@/services/galleryPhotoEvidence': { galleryPhotoEvidence:async asset=>({...metadata.emptyPhotoEvidence(),latitude:asset.lat,longitude:asset.lon,locationSource:'photo_exif',locationStatus:'available'}) },
    '@/services/uploadImages': { MAX_DAMAGE_PHOTOS:10,MAX_DAMAGE_IMAGE_BYTES:5242880,MAX_DAMAGE_TOTAL_BYTES:33554432,UploadImagePreparationError:class extends Error{},preparePickedImage:async a=>({uri:a.uri+'-prepared',size:100,fileName:'controlled.jpg',mimeType:'image/jpeg'}) },
    'expo-image-picker': { UIImagePickerPreferredAssetRepresentationMode:{Current:'current'},launchImageLibraryAsync:async()=>({canceled:false,assets:[{uri:'controlled-A',lat:9.12,lon:125.53},{uri:'controlled-B',lat:9.13,lon:125.54}]}) },
    '@/services/farmerDataApi': { FarmerDataApiError:class extends Error{},
      listFarmerFarms:async()=>({farms:[{id:27,name:'Controlled farm',status:'Active',agriculturalCategory:'Crops',barangayName:'Controlled barangay'}]}),
      validateDamagePoint:async(lat,lon)=>boundary.push({lat,lon}),
      submitDamageReport:async payload=>{submissions.push(payload);await new Promise(resolve=>{resolveSubmit=resolve;});} },
  },os,width);
  model.render(); await flush();
  button(model.render(),'Next: Photos & location').props.onPress();
  assert(text(model.render()).includes('Select the crop.'),'Crop is required');
  assert(text(model.render()).includes('Select the suspected cause, or choose Unsure.'));
  assert(walk(model.render()).some(n=>n.props?.error==='Enter your estimate of how much of this crop was damaged (0 to 100%).'));
  assert(!walk(model.render()).some(n=>n.props?.label==='What happened?'&&n.props.error),'What happened? is optional');
  assert(!walk(model.render()).some(n=>n.props?.error==='At least one damage photo is required.'),'Step one must not validate future photos');
  assert(!walk(model.render()).some(n=>n.props?.label==='Estimated Damage Cost'),'No peso cost field for new reports');
  const field=label=>walk(model.render()).find(n=>n.props?.label===label);
  field('What happened?').props.onChangeText('Controlled long description '.repeat(30));
  walk(model.render()).find(n=>n.props?.title==='Select crop' && n.props.onSelect).props.onSelect('Corn');
  walk(model.render()).find(n=>n.props?.title==='Suspected cause' && n.props.onSelect).props.onSelect('Flooding');
  field('Estimated crop damage').props.onChangeText('40');
  assert(text(model.render()).includes('Corn: 40% of this crop damaged'));
  button(model.render(),'Next: Photos & location').props.onPress();
  assert(text(model.render()).includes('2 of 3'));
  // Direct Gallery action has no source sheet to dismiss on iPhone.
  const adding=button(model.render(),'Choose from Gallery').props.onPress();
  await flush();
  await adding; await flush();
  assert.equal(field('Latitude').props.value,'9.12');
  assert.equal(field('Latitude').props.editable,false,'Photo GPS must not be exposed as an editable manual field');
  assert(button(model.render(),'Take Photo Now'),'Camera remains directly visible');
  walk(model.render()).find(n=>n.props?.accessibilityLabel==='Review location for photo 2').props.onPress();
  assert.equal(field('Latitude').props.value,'9.13');
  assert(button(model.render(),'This is the damaged location'));
  field('Address / Landmark').props.onChangeText('Controlled Cabadbaran landmark');
  await button(model.render(),'This is the damaged location').props.onPress();
  await flush();
  assert.deepEqual(boundary,[{lat:9.13,lon:125.54}]);
  button(model.render(),'GPS help').props.onPress();
  walk(model.render()).find(n=>n.props?.accessibilityLabel==='Move photo 2 earlier').props.onPress();
  assert.equal(field('Latitude').props.value,'9.13','Reorder must not exchange coordinates');
  assert(button(model.render(),'Damage location confirmed'),'Reorder preserves selected identity');
  button(model.render(),'Next: Review report').props.onPress();
  assert(text(model.render()).includes('3 of 3'));
  assert(text(model.render()).includes('Controlled farm'));
  button(model.render(),'Back to photos & location').props.onPress();
  assert.equal(field('What happened?').props.value,'Controlled long description '.repeat(30),'Draft survives back navigation');
  assert.equal(field('Estimated crop damage').props.value,'40','Category figures survive back navigation');
  walk(model.render()).find(n=>n.props?.accessibilityLabel==='Review location for photo 2').props.onPress();
  assert.equal(field('Latitude').props.value,'9.12');
  assert(button(model.render(),'This is the damaged location'),'Switch photo clears confirmation');
  walk(model.render()).find(n=>n.props?.accessibilityLabel==='Remove damage photo 1').props.onPress();
  assert.equal(field('Latitude').props.value,'9.12','Removing another photo leaves selected GPS');
  await button(model.render(),'This is the damaged location').props.onPress();
  await flush();
  button(model.render(),'Next: Review report').props.onPress();
  const submit=button(model.render(),'Submit Report');
  const running=submit.props.onPress(); submit.props.onPress(); await flush();
  assert.equal(submissions.length,1,'Duplicate submission stays locked');
  const payload=submissions[0];
  assert.equal(payload.farmId,27);assert.equal(payload.photos.length,1);assert.equal(payload.photos[0].latitude,9.12);
  assert.equal(payload.locationPhotoId,payload.photos[0].id);assert.equal(payload.locationConfirmed,true);
  assert.equal(payload.estimatedCost,undefined,'No peso cost is sent for new reports');
  assert.equal(JSON.stringify(payload.detailFields),JSON.stringify({damage_details_version:'1',subject_type:'Corn',subject_type_other:'',suspected_cause:'Flooding',suspected_cause_other:'',crop_damage_percent:'40',planted_area_ha:'',affected_area_ha:''}));
  assert(payload.clientSubmissionId.startsWith('report_'));
  resolveSubmit();await running;
  console.log('PASS: '+os+' '+width+'px wizard, validations, long draft, back/review, per-photo switch/reorder/remove, unchanged payload and submit lock.');
}
async function viewer() {
  const metadata=loadMetadata();
  const report={id:'controlled-report',status:'Pending',photos:[
    {id:'saved-A',uri:'/reports/image.php?id=saved-A',latitude:9.12,longitude:125.53,locationSource:'photo_exif'},
    {id:'saved-B',uri:'/reports/image.php?id=saved-B',latitude:9.13,longitude:125.54,locationSource:'device_capture'},
    {id:'saved-C',uri:'/reports/image.php?id=saved-C',latitude:null,longitude:null}],
    farmType:'Crops',estimatedCost:100,latitude:9.11,longitude:125.52,description:'Controlled description'};
  const model=harness('app/reports/[id].tsx',{'@/services/photoLocationMetadata':metadata,'@/services/farmerDataApi':{FarmerDataApiError:class extends Error{},getDamageReport:async()=>report}});
  model.render();await flush();
  const actions=walk(model.render()).filter(n=>n.type==='Action'&&n.props.title==='View photo & its location');
  actions[1].props.onPress();
  let modal=walk(model.render()).find(n=>n.type==='Modal');
  assert.equal(modal.props.visible,true);
  const map=walk(modal).find(n=>n.type==='DamageLocationMap');assert.equal(map.props.latitude,9.13);assert.equal(map.props.longitude,125.54);
  assert.equal(walk(modal).find(n=>n.type==='ProtectedReportPhoto').props.uri,report.photos[1].uri);
  walk(modal).find(n=>n.type==='Action'&&n.props.title==='Next photo').props.onPress();
  modal=walk(model.render()).find(n=>n.type==='Modal');
  assert(!walk(modal).some(n=>n.type==='DamageLocationMap'),'No-GPS image never borrows previous photo pin');
  console.log('PASS: ordered protected fullscreen viewer changes image ID and its own map; missing GPS clears prior map.');
}
async function notifications() {
  let destinations=[],reads=[];
  const items=[{id:'n1',title:'Controlled report response',shortMessage:'Preview',fullMessage:'Full',isRead:false,createdAt:'2026-01-01T00:00:00',relatedReportId:'exact-report',relatedAdvisoryId:null,type:'info'}];
  // Focus triggers the real loader once, with controlled server responses.
  let invoked=false;
  const loaded=harness('app/notifications.tsx',{'expo-router':{router:{push:r=>destinations.push(r)},useFocusEffect:fn=>{if(!invoked){invoked=true;fn();}}},
    '@/services/farmerDataApi':{FarmerDataApiError:class extends Error{},listFarmerNotifications:async()=>items,markFarmerNotificationRead:async id=>reads.push(id)}});
  loaded.render();await flush();
  const list=walk(loaded.render()).find(n=>n.type==='SectionList');
  assert.equal(list.props.sections[0].data.length,1);
  const card=list.props.renderItem({item:list.props.sections[0].data[0]});
  await card.props.onPress();await flush();
  assert.equal(reads.length,1);assert.equal(destinations[0].params.id,'exact-report');
  const refreshed=walk(loaded.render()).find(n=>n.type==='SectionList');
  await refreshed.props.renderItem({item:refreshed.props.sections[0].data[0]}).props.onPress();await flush();
  assert.equal(reads.length,1,'Viewing a read notification never creates or duplicates writes');
  console.log('PASS: server notification groups/unread commit and exact report destination, with no duplicate read write.');
}
async function protectedPhoto() {
  for(const status of [404,401,503]) {
    const model=harness('components/protected-report-photo.tsx',{'expo-image':{Image:'ExpoImage'},'@/services/farmerDataApi':{getAuthenticatedImageSource:async uri=>({uri:'https://controlled.invalid'+uri,headers:{Authorization:'Bearer controlled-test-token'}})},fetch:async(_url,options)=>{assert.equal(options.headers.Authorization,'Bearer controlled-test-token');return{status,ok:false};}});
    const props = { uri: '/reports/image.php?id=controlled' };
    assert(text(model.render(props)).includes('Loading protected photo'));await flush();
    const image=walk(model.render(props)).find(n=>n.type==='ExpoImage');
    assert.equal(image.props.cachePolicy,'none');
    await image.props.onError();await flush();
    assert(text(model.render(props)).includes(status===404?'saved photo file is unavailable':status===401?'requires your current account':'HTTP 503'));
    button(model.render(props),'Retry photo').props.onPress();model.render(props);await flush();
    assert(walk(model.render(props)).some(n=>n.type==='ExpoImage'));
    const compactProps = { ...props, compact: true, height: 76 };
    await walk(model.render(compactProps)).find(n=>n.type==='ExpoImage').props.onError(); await flush();
    assert(text(model.render(compactProps)).includes('Unavailable'),'List thumbnails show a truthful compact failure, never a stock photo');
  }
  const timeout=harness('components/protected-report-photo.tsx',{'expo-image':{Image:'ExpoImage'},'@/services/farmerDataApi':{getAuthenticatedImageSource:async()=>({uri:'https://controlled.invalid',headers:{}})}});
  timeout.render({ uri: '/reports/image.php?id=controlled' });await flush();timeout.expire(20000);assert(text(timeout.render({ uri: '/reports/image.php?id=controlled' })).includes('timed out'));
  console.log('PASS: protected-photo bearer source, no shared cache, loading, HTTP 404/401/503, timeout and retry states.');
}
module.exports = { harness, walk, text, loadMetadata, loadDamageDetails, flush };
if (require.main === module) (async()=>{
  for(const os of ['ios','android']) for(const width of [320,390,430]) await wizard(os,width);
  await viewer();await notifications();await protectedPhoto();
})().catch(error=>{console.error(error);process.exitCode=1;});
