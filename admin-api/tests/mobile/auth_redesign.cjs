// Runs the actual screen handlers with controlled native/API mocks; no phone claims.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const { harness, walk, text, flush } = require('./mobile_redesign.cjs');
const root = path.join(__dirname, '../../frontend/mobile/src');
function load(file) {
  const exports = {};
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports });
  return exports;
}
const phone = load('utils/phone.ts');
const locations = load('data/cabadbaranBarangays.ts');
const rsbsaUtil = load('utils/rsbsaReference.ts');
const nameUtil = load('utils/farmerName.ts');
class RegistrationApiError extends Error { constructor(message, fields = {}) { super(message); this.fields = fields; } }
const authUI = { AuthBrand: 'AuthBrand', AuthIcon: 'AuthIcon', AuthScenery: 'AuthScenery', AuthLandscape: 'AuthLandscape', authColors: { forest: '#174D36', muted: '#637068', border: '#E2E5DA', ink: '#172B24', ivory: '#F7F5EE' }, authStyles: {} };
const shared = { '@/components/auth-ui': authUI, 'expo-symbols': { SymbolView: 'SymbolView' }, 'expo-status-bar': { StatusBar: 'StatusBar' } };
const press = (model, title) => { const actual = ({Next:'Continue',Submit:'Submit for verification'})[title] || title; const n = walk(model.render()).find(n => n.type === 'Pressable' && walk(n).some(child => child.type === 'Text' && text(child) === actual)); assert(n, 'Missing button: ' + actual); return n.props.onPress(); };
const field = (model, label) => { const n = walk(model.render()).find(n => n.props?.label === label && n.props.onChangeText); assert(n, 'Missing field: ' + label); return n; };
const byLabel = (model, label) => { const n = walk(model.render()).find(n => n.props?.accessibilityLabel === label); assert(n, 'Missing control: ' + label); return n; };
// The prepared URI records the slot and the picker source, so front/back ordering is checked end to end.
const defaultUploads = { MAX_ID_IMAGE_BYTES: 5242880, UploadImagePreparationError: class extends Error {}, preparePickedImage: async (asset, side) => ({ uri: 'fixture-' + side + '-' + asset.uri, fileName: side + '.jpg', mimeType: 'image/jpeg', size: 120 }) };
function registration(os, width, presentation = {}, api = {}, uploads = defaultUploads) {
  const submitted = [], attempts = [], routes = [], boundary = [], failures = [];
  let cancelled = false;
  const model = harness('app/register.tsx', {
    ...shared,
    '@/components/auth-ui': { ...authUI, ...presentation },
    '@react-native-community/datetimepicker': { __esModule: true, default: 'DateTimePicker' },
    '@/components/registration-pin-map': { __esModule: true, default: 'RegistrationPinMap' }, '@/components/registration-pin-review': { PinReview: 'PinReview' },
    '@/data/cabadbaranBarangays': locations, '@/utils/phone': phone, '@/utils/rsbsaReference': rsbsaUtil, '@/utils/farmerName': nameUtil,
    '@/services/authApi': { RegistrationApiError, registerFarmer: async data => { attempts.push(data); const failure = failures.shift(); if (failure) throw failure; submitted.push(data); }, validateFarmPin: async (lat, lon, kind = 'farm') => { boundary.push({ lat, lon, kind }); }, ...api },
    '@/services/uploadImages': uploads,
    'expo-location': { reverseGeocodeAsync: async () => [] },
    'expo-image-picker': { requestCameraPermissionsAsync: async () => ({ status: 'granted' }), requestMediaLibraryPermissionsAsync: async () => ({ status: 'granted' }), launchCameraAsync: async () => ({ canceled: cancelled, assets: [{ uri: 'fixture-camera' }] }), launchImageLibraryAsync: async () => ({ canceled: cancelled, assets: [{ uri: 'fixture-gallery' }] }) },
    'expo-router': { router: { replace: route => routes.push(route) } },
  }, os, width);
  return { model, submitted, attempts, routes, boundary, fail: error => { failures.push(error); }, cancel: value => { cancelled = value; } };
}
/** Step 1: front from the gallery (via the upload sheet), back from the camera, and the printed number. */
/** The grouped RSBSA reference field (2-2-2-3-6 boxes) and its five inputs. Refs record which box gets focus. */
const rsbsaField = model => { const n = walk(model.render()).find(n => n.type?.name === 'RsbsaReferenceField'); assert(n, 'Missing RSBSA reference field'); return n; };
function rsbsaBoxes(model, focused = []) {
  const node = rsbsaField(model); const inputs = walk(node.type(node.props)).filter(n => n.type === 'TextInput');
  inputs.forEach((input, index) => input.props.ref?.({ focus: () => focused.push(index) }));
  return inputs;
}
/** Types digits one at a time into whichever box has focus, as a farmer would. Returns the focus moves. */
function typeRsbsa(model, digits, start = 0) {
  const focused = []; let group = start;
  for (const digit of digits) { const inputs = rsbsaBoxes(model, focused); inputs[group].props.onChangeText(inputs[group].props.value + digit); if (focused.length) group = focused.at(-1); }
  return focused;
}
const pasteRsbsa = (model, text, group = 0) => { const focused = []; rsbsaBoxes(model, focused)[group].props.onChangeText(text); return focused; };
const backspaceRsbsa = (model, group) => { const focused = []; rsbsaBoxes(model, focused)[group].props.onKeyPress({ nativeEvent: { key: 'Backspace' } }); return focused; };
/** Step 1: front from the gallery (via the upload sheet), back from the camera, and the reference number (pasted). */
async function fillRsbsa(model, number = '16-02-03-005-000123') {
  await press(model, 'Add Front of RSBSA ID'); await press(model, 'Choose from Gallery'); await flush();
  await byLabel(model, 'Take photo for Back of RSBSA ID').props.onPress(); await flush();
  pasteRsbsa(model, number);
}
function fillAccount(model, password = 'TypedPass9!') {
  field(model, 'Password').props.onChangeText(password); field(model, 'Confirm Password').props.onChangeText(password);
}
/** Step 3 farm fields with a manual farm address. */
function fillFarm(model) {
  field(model,'Farm Name').props.onChangeText('Controlled Farm'); field(model,'Farm Size (hectares)').props.onChangeText('1.5'); field(model,'Main Product').props.onChangeText('Rice');
  select(model,'Select farm type','Crops'); select(model,'Select farm barangay',locations.CABADBARAN_BARANGAYS[1]); field(model,'Purok / Sitio').props.onChangeText('Purok 4');
}
function select(model, title, value) { const n = walk(model.render()).find(n => n.props?.title === title && n.props.onSelect); assert(n, title); n.props.onSelect(value); }
function birth(model, os) {
  const button = walk(model.render()).find(n => n.props?.accessibilityLabel === 'Choose date of birth'); assert(button); button.props.onPress();
  const picker = walk(model.render()).find(n => n.type === 'DateTimePicker'); assert(picker);
  picker.props.onChange({ type: 'set' }, new Date(1994, 6, 15));
  if (os === 'ios') press(model, 'Confirm Date');
}
function fillPersonal(model, os) {
  for (const [label, value] of [['First Name','Maria'], ['Last Name','Santos'], ['Email',' QA@example.test '], ['Purok / Sitio','Purok 2'], ['House / Street / Landmark','Near school']]) field(model,label).props.onChangeText(value);
  const number = walk(model.render()).find(n => n.type?.name === 'PhoneField'); assert(number); number.props.onChangeText('+63 912 345 6789');
  select(model,'Select gender','Female'); select(model,'Select residential barangay',locations.CABADBARAN_BARANGAYS[0]); birth(model,os);
}
async function wizard(os, width) {
  const test = registration(os,width), model = test.model;
  const stepOf = () => (text(model.render()).match(/Step (\d) of 3/) || [])[1];
  // Step 1 - RSBSA ID: both sides and the printed number are required before Continue.
  assert.equal(stepOf(),'1'); assert(text(model.render()).includes('RSBSA ID'));
  press(model,'Next'); assert.equal(stepOf(),'1');
  let screen = text(model.render());
  assert(screen.includes('Add a photo of the front of your RSBSA ID.')); assert(screen.includes('Add a photo of the back of your RSBSA ID.')); assert.equal(rsbsaField(model).props.error,'Enter the 15-digit RSBSA reference number printed on your card.');
  assert(screen.includes('does not verify your RSBSA registration or approve your account'));
  await press(model,'Add Front of RSBSA ID'); await press(model,'Choose from Gallery'); await flush();
  press(model,'Next'); assert.equal(stepOf(),'1'); assert(!text(model.render()).includes('Add a photo of the front of your RSBSA ID.'));
  await byLabel(model,'Take photo for Back of RSBSA ID').props.onPress(); await flush();
  assert(text(model.render()).includes('Retake photo'));
  // The direct Back buttons must target Back even while the upload sheet last chose Front.
  await press(model,'Replace Front of RSBSA ID');
  await byLabel(model,'Choose from gallery for Back of RSBSA ID').props.onPress(); await flush();
  // Cancelling a picker keeps the existing photos in their slots.
  test.cancel(true); await press(model,'Replace Front of RSBSA ID'); await press(model,'Choose from Gallery'); await byLabel(model,'Take photo for Back of RSBSA ID').props.onPress(); await flush(); test.cancel(false);
  const previews = walk(model.render()).filter(n => n.type?.name === 'ImagePreview').map(n => n.props.uri);
  assert.deepEqual(previews,['fixture-rsbsa-id-front-fixture-gallery','fixture-rsbsa-id-back-fixture-gallery']);
  // Any printed format is accepted: no assumed RSBSA pattern, only the 100-character storage limit.
  // Reference number: typed box by box with automatic moves, incomplete is refused, Backspace crosses boxes.
  const boxes = rsbsaBoxes(model); assert.equal(boxes.length,5); assert.deepEqual(boxes.map(b => b.props.placeholder),['00','00','00','000','000000']);
  assert(boxes.every(b => b.props.keyboardType === 'number-pad' && b.props.inputMode === 'numeric'), 'numeric keypad in every box');
  assert.deepEqual(typeRsbsa(model,'0012034'),[1,2,3]); assert.equal(rsbsaField(model).props.value,'00-12-03-4-');
  press(model,'Next'); assert.equal(stepOf(),'1'); assert.equal(rsbsaField(model).props.error,'Enter all 15 digits of the RSBSA reference number (00-00-00-000-000000).');
  // Backspace in an empty box removes the previous box's last digit and moves focus back, box after box.
  assert.deepEqual(backspaceRsbsa(model,4),[3]); assert.equal(rsbsaField(model).props.value,'00-12-03--');
  assert.deepEqual(backspaceRsbsa(model,3),[2]); assert.equal(rsbsaField(model).props.value,'00-12-0--');
  assert.deepEqual(backspaceRsbsa(model,0),[]); assert.equal(rsbsaField(model).props.value,'00-12-0--','Backspace in a filled box is left to the input itself');
  assert.deepEqual(typeRsbsa(model,'3045000678',2),[3,4]); assert.equal(rsbsaField(model).props.value,'00-12-03-045-000678');
  press(model,'Next'); assert.equal(stepOf(),'2');
  // Step 2 - personal information, with the home-pin action before the manual address fields.
  press(model,'Next'); assert.equal(field(model,'First Name').props.error,'Please enter a valid name.'); assert.equal(stepOf(),'2');
  const order = walk(model.render()).map(n => n.props?.accessibilityLabel || n.props?.label).filter(Boolean);
  assert(order.indexOf('Pin home location on map') < order.indexOf('Residential Barangay'), 'home pin action comes before Barangay');
  assert(order.indexOf('Residential Barangay') < order.indexOf('Purok / Sitio'));
  assert(order.indexOf('First Name') < order.indexOf('Choose date of birth') && order.indexOf('Choose date of birth') < order.indexOf('Email'));
  assert(!walk(model.render()).some(n => n.props?.label === 'Password'), 'passwords are not on the personal step');
  fillPersonal(model,os);
  const avoider = walk(model.render()).find(n=>n.type==='KeyboardAvoidingView');
  assert.equal(avoider.props.behavior,os==='ios'?'padding':'height');
  assert.equal(walk(model.render()).find(n=>n.type==='ScrollView').props.keyboardShouldPersistTaps,'handled');
  press(model,'Next'); assert.equal(stepOf(),'3');
  // Back and forward keep typed text, photos and the number.
  press(model,'Back'); assert.equal(stepOf(),'2'); assert.equal(field(model,'First Name').props.value,'Maria');
  press(model,'Back'); assert.equal(stepOf(),'1'); assert.equal(rsbsaField(model).props.value,'00-12-03-045-000678','Back keeps the reference number and its leading zeroes');
  assert.equal(walk(model.render()).filter(n => n.type?.name === 'ImagePreview').length,2);
  press(model,'Next'); press(model,'Next'); assert.equal(stepOf(),'3');
  // Step 3 - all four farm categories, the separate farm location, then the account section.
  const categories = walk(model.render()).filter(n => n.props?.accessibilityRole === 'radio' && ['Crops','Livestock','Poultry','Fishpond'].includes(n.props.accessibilityLabel)).map(n => n.props.accessibilityLabel);
  assert.deepEqual(categories,['Crops','Livestock','Poultry','Fishpond']);
  const step3 = walk(model.render()).map(n => n.props?.label || n.props?.accessibilityLabel).filter(Boolean);
  assert(step3.indexOf('Main Product') < step3.indexOf('Password'), 'account section follows the farm fields');
  await press(model,'Submit'); assert.equal(stepOf(),'3'); assert.equal(field(model,'Farm Name').props.error,'Please enter a valid farm name.'); assert.equal(field(model,'Password').props.error,'Password is required.'); assert.equal(test.attempts.length,0);
  field(model,'Farm Name').props.onChangeText('Controlled Farm'); field(model,'Farm Size (hectares)').props.onChangeText('1.5'); field(model,'Main Product').props.onChangeText('Rice');
  select(model,'Select farm type','Crops'); select(model,'Select farm barangay',locations.CABADBARAN_BARANGAYS[1]); field(model,'Purok / Sitio').props.onChangeText('Purok 4');
  // Confirm the manually selected farm pin, never auto-confirm the phone position or reuse the home location.
  const radio = walk(model.render()).find(n => n.props?.accessibilityRole === 'radio' && text(n).includes('Pin farm location on map')); assert(radio); radio.props.onPress();
  const maps = walk(model.render()).filter(n => n.type === 'RegistrationPinMap'); assert.equal(maps.length,2);
  maps[1].props.onPick({ latitude: 9.1234, longitude: 125.5345 });
  await press(model,'Confirm Farm Pin'); await flush(); assert.equal(test.boundary.at(-1).kind,'farm');
  assert.equal(field(model,'Password').props.secureTextEntry,true); field(model,'Password').props.onTogglePasswordVisibility(); assert.equal(field(model,'Password').props.secureTextEntry,false);
  fillAccount(model); field(model,'Confirm Password').props.onChangeText('Mismatch9');
  await press(model,'Submit'); assert.equal(field(model,'Confirm Password').props.error,'Passwords do not match.'); assert.equal(test.attempts.length,0);
  field(model,'Confirm Password').props.onChangeText('TypedPass9!');
  assert(text(model.render()).includes('An administrator reviews your registration before you can log in.'));
  await press(model,'Submit'); await flush(); assert.equal(test.submitted.length,1);
  const data = test.submitted[0];
  assert.equal(data.idType,'RSBSA ID'); assert.equal(data.idNumber,'00-12-03-045-000678');
  assert.equal(data.idFrontImage.uri,'fixture-rsbsa-id-front-fixture-gallery'); assert.equal(data.idBackImage.uri,'fixture-rsbsa-id-back-fixture-gallery');
  assert.equal(data.dateOfBirth,'1994-07-15'); assert.equal(data.phone,'+639123456789'); assert.equal(data.password,'TypedPass9!'); assert.equal(data.confirmPassword,'TypedPass9!');
  assert.equal(data.residentialBarangayCode,locations.CABADBARAN_BARANGAYS[0].code); assert.equal(data.farmBarangayCode,locations.CABADBARAN_BARANGAYS[1].code);
  assert.equal(data.residentialLatitude,null); assert.equal(data.latitude,9.1234); assert.equal(data.longitude,125.5345);
  model.render(); model.expire(2500); assert(test.routes.includes('/verification-pending'), 'success opens the pending-status screen');
  console.log(`PASS: ${os} ${width}px RSBSA ID -> personal -> farm & account: per-step errors, front/back slots and sources, retake/replace/cancel, grouped 15-digit reference typed/incomplete/Backspace, preserved values on Back, all farm categories, separate farm pin, typed password, single submission, pending screen.`);
}
async function submissionSafety() {
  // A fast double tap starts one request only.
  let release; const gate = new Promise(resolve => { release = resolve; }); const calls = [];
  const tap = registration('android',393,{},{ registerFarmer: async data => { calls.push(data); await gate; } }), m = tap.model;
  await fillRsbsa(m); press(m,'Next'); fillPersonal(m,'android'); press(m,'Next'); fillFarm(m); fillAccount(m);
  const submit = walk(m.render()).find(n => n.type === 'Pressable' && text(n) === 'Submit for verification').props.onPress;
  submit(); submit(); await flush(); assert.equal(calls.length,1,'double tap must submit once'); release(); await flush();
  // Interrupted, then retried: no response first, then the server reports the email as taken (the first
  // request had been saved). Values stay, the farmer is pointed to the pending status, then success.
  const retry = registration('ios',393), r = retry.model;
  await fillRsbsa(r); press(r,'Next'); fillPersonal(r,'ios'); press(r,'Next'); fillFarm(r); fillAccount(r);
  retry.fail(new RegistrationApiError('Could not connect to the registration server. The request did not receive an HTTP response.'));
  await press(r,'Submit'); await flush();
  assert(text(r.render()).includes('did not receive an HTTP response')); assert.equal(field(r,'Password').props.value,'TypedPass9!');
  const taken = new RegistrationApiError('An account already uses that email address or contact number.', { email: 'Email or contact number is already registered.', phone: 'Email or contact number is already registered.' }); taken.status = 409;
  retry.fail(taken); await press(r,'Submit'); await flush();
  assert(/Step 2 of 3/.test(text(r.render())), '409 opens the step with the email field');
  assert(text(r.render()).includes('log in with this email to check its status')); assert.equal(field(r,'Email').props.error,'Email or contact number is already registered.');
  // A server field error on the RSBSA number reopens step 1.
  retry.fail(new RegistrationApiError('Please correct the highlighted registration fields.', { idNumber: 'The ID number must be 100 characters or fewer.' }));
  press(r,'Next'); await press(r,'Submit'); await flush();
  assert(/Step 1 of 3/.test(text(r.render()))); assert.equal(rsbsaField(r).props.error,'The ID number must be 100 characters or fewer.');
  press(r,'Next'); press(r,'Next'); await press(r,'Submit'); await flush();
  assert.equal(retry.attempts.length,4); assert.equal(retry.submitted.length,1); assert.equal(retry.submitted[0].idBackImage.uri,'fixture-rsbsa-id-back-fixture-camera');
  // Removing a photo after step 1 is caught again before the farmer can continue.
  const removed = registration('android',320), x = removed.model;
  await fillRsbsa(x); press(x,'Next'); fillPersonal(x,'android'); press(x,'Next'); fillFarm(x); fillAccount(x);
  press(x,'Back'); press(x,'Back'); await press(x,'Remove Back of RSBSA ID'); press(x,'Next'); assert(/Step 1 of 3/.test(text(x.render())));
  assert(text(x.render()).includes('Add a photo of the back of your RSBSA ID.')); assert.equal(removed.attempts.length,0);
  console.log('PASS: one request per double tap; interrupted then retried submission keeps values, explains a 409 as a possibly saved registration, routes server errors to their step, then submits once.');
}
async function homePin() {
  const test=registration('ios',393),model=test.model;await fillRsbsa(model);press(model,'Next');fillPersonal(model,'ios');
  const maps=walk(model.render()).filter(n=>n.type==='RegistrationPinMap');
  maps[0].props.onPick({latitude:9.125,longitude:125.536});
  await press(model,'Confirm Home Pin');await flush();
  assert.equal(test.boundary[0].kind,'residential');assert(text(model.render()).includes('Home pin confirmed'));
  const remove=walk(model.render()).find(n=>n.props?.accessibilityLabel==='Remove confirmed home pin');assert(remove);remove.props.onPress();
  assert(!text(model.render()).includes('Home pin confirmed — tap to review'));
  console.log('PASS: explicit independent home pin validation and removal; manual address remains available.');
}
async function login() {
  for (const status of ['authenticated','pending','rejected','invalid']) {
    const calls = [], routes = [];
    const model = harness('app/login.tsx',{ ...shared, '@/services/farmerAuthApi': { FarmerAuthApiError: class extends Error {} }, '@/state/farmerAuthContext': { useFarmerAuth: () => ({ login: async (email,password) => { calls.push({ email,password }); return { status, message:'Controlled account status', rejectionReason:'Controlled reason' }; } }) }, 'expo-router': { router: { replace: route => routes.push(route), push: route => routes.push(route) } } });
    press(model,'Login'); assert.equal(calls.length,0);
    const inputs = walk(model.render()).filter(n => n.type === 'TextInput'); inputs[0].props.onChangeText(' QA@example.test '); inputs[1].props.onChangeText(' ExactTyped9! ');
    await press(model,'Login'); assert.equal(calls[0].email,'qa@example.test'); assert.equal(calls[0].password,' ExactTyped9! ');
    if (status === 'authenticated') assert(routes.includes('/farmer-home')); else if (status === 'pending') assert(routes.includes('/verification-pending')); else assert(text(model.render()).includes('Controlled account status'));
  }
  console.log('PASS: login status routes/validation and exact password.');
}
class PasswordResetApiError extends Error { constructor(message, code, status, fields = {}, retryAfterSeconds = null) { super(message); this.code = code; this.status = status; this.fields = fields; this.retryAfterSeconds = retryAfterSeconds; } }
function recoveryScreen(os, failures = {}) {
  const calls = [], routes = [];
  const next = name => { const queue = failures[name] || []; const failure = queue.shift(); if (failure) throw failure; };
  const api = {
    PasswordResetApiError,
    requestPasswordResetCode: async email => { calls.push(['request', email]); next('request'); return { message: 'If an AgriReach farmer account uses this email, a 6-digit code was sent to it. The code expires in 10 minutes.', codeLength: 6, expiresInSeconds: 600, resendAfterSeconds: 60 }; },
    verifyPasswordResetCode: async (email, code) => { calls.push(['verify', email, code]); next('verify'); return { resetToken: 'controlled-reset-token', expiresInSeconds: 900 }; },
    completePasswordReset: async (token, password, confirm) => { calls.push(['reset', token, password, confirm]); next('reset'); return 'Your password was changed and you were signed out on every device. Log in with your new password.'; },
  };
  const model = harness('app/forgot-password.tsx', { ...shared, '@/services/passwordResetApi': api, 'expo-router': { router: { replace: route => routes.push(route), push: route => routes.push(route), back: () => routes.push('back') } } }, os, 393);
  return { model, calls, routes };
}
const input = (model, label) => { const n = walk(model.render()).find(n => n.type === 'TextInput' && n.props.accessibilityLabel === label); assert(n, 'Missing input: ' + label); return n; };
const passwordField = (model, label) => { const n = walk(model.render()).find(n => n.type?.name === 'PasswordField' && n.props.label === label); assert(n, 'Missing password field: ' + label); return n; };
async function recovery() {
  for (const os of ['ios', 'android']) {
    const { model, calls, routes } = recoveryScreen(os, { verify: [new PasswordResetApiError('That code is incorrect or has expired. Check the newest AgriReach email, or request a new code.', 'invalid_code', 400)] });
    await press(model, 'Send code'); assert(text(model.render()).includes('Email is required.')); assert.equal(calls.length, 0);
    input(model, 'Email').props.onChangeText(' QA@Example.test ');
    await press(model, 'Send code'); await flush();
    assert.deepEqual(calls.at(-1), ['request', 'qa@example.test']);
    assert(text(model.render()).includes('a 6-digit code was sent to it'));
    // Code entry: numeric keypad and one-time-code autofill on both platforms, no deep link.
    const code = input(model, '6-digit code');
    assert.equal(code.props.keyboardType, 'number-pad'); assert.equal(code.props.textContentType, 'oneTimeCode'); assert.equal(code.props.autoComplete, 'one-time-code'); assert.equal(code.props.maxLength, 6);
    assert.equal(walk(model.render()).find(n => n.type === 'KeyboardAvoidingView').props.behavior, os === 'ios' ? 'padding' : 'height');
    assert(text(model.render()).includes('Resend code in 60s'));
    model.expire(1000); assert(text(model.render()).includes('Resend code in 59s'));
    code.props.onChangeText('12 3a4'); assert.equal(input(model, '6-digit code').props.value, '1234');
    await press(model, 'Verify code'); assert(text(model.render()).includes('Enter the 6-digit code from the email.')); assert.equal(calls.length, 1);
    input(model, '6-digit code').props.onChangeText('123456');
    await press(model, 'Verify code'); await flush();
    assert(text(model.render()).includes('That code is incorrect or has expired.'));
    await press(model, 'Verify code'); await flush();
    assert.deepEqual(calls.at(-1), ['verify', 'qa@example.test', '123456']);
    // New password: same rule as registration, exact typed value, no autofill substitution.
    const rendered = walk(passwordField(model, 'New Password').type(passwordField(model, 'New Password').props)).find(n => n.type === 'TextInput');
    assert.equal(rendered.props.textContentType, 'none'); assert.equal(rendered.props.autoComplete, 'off'); assert.equal(rendered.props.secureTextEntry, true);
    passwordField(model, 'New Password').props.onChangeText('weakpass'); passwordField(model, 'Confirm New Password').props.onChangeText('weakpass');
    await press(model, 'Set new password'); assert.equal(passwordField(model, 'New Password').props.error, 'Use at least 8 characters with uppercase, lowercase, and a number.');
    passwordField(model, 'New Password').props.onChangeText(' NewTyped9! '); passwordField(model, 'Confirm New Password').props.onChangeText('Mismatch9A');
    await press(model, 'Set new password'); assert.equal(passwordField(model, 'Confirm New Password').props.error, 'Passwords do not match.');
    passwordField(model, 'Confirm New Password').props.onChangeText(' NewTyped9! ');
    await press(model, 'Set new password'); await flush();
    assert.deepEqual(calls.at(-1), ['reset', 'controlled-reset-token', ' NewTyped9! ', ' NewTyped9! ']);
    assert(text(model.render()).includes('Password changed')); assert(text(model.render()).includes('signed out on every device'));
    await press(model, 'Back to login'); assert(routes.includes('/login'));
  }
  // An expired reset token sends the farmer back to request a new code.
  const expired = recoveryScreen('android', { reset: [new PasswordResetApiError('This password reset has expired or was already used. Request a new code.', 'invalid_token', 400)] });
  input(expired.model, 'Email').props.onChangeText('qa@example.test'); await press(expired.model, 'Send code'); await flush();
  input(expired.model, '6-digit code').props.onChangeText('654321'); await press(expired.model, 'Verify code'); await flush();
  passwordField(expired.model, 'New Password').props.onChangeText('NewTyped9!'); passwordField(expired.model, 'Confirm New Password').props.onChangeText('NewTyped9!');
  await press(expired.model, 'Set new password'); await flush();
  assert(input(expired.model, 'Email')); assert(text(expired.model.render()).includes('Request a new code.'));
  // A recent request (cooldown) still lets the farmer enter the code that was already sent.
  const cooldown = recoveryScreen('ios', { request: [new PasswordResetApiError('Please wait 42 seconds before requesting another code.', 'cooldown', 429, {}, 42)] });
  input(cooldown.model, 'Email').props.onChangeText('qa@example.test'); await press(cooldown.model, 'Send code'); await flush();
  assert(input(cooldown.model, '6-digit code')); assert(text(cooldown.model.render()).includes('Resend code in 42s'));
  // Server field errors (for example "same as current password") are shown on the field.
  const reused = recoveryScreen('ios', { reset: [new PasswordResetApiError('Choose a password different from your current password.', 'invalid', 422, { password: 'Choose a password different from your current password.' })] });
  input(reused.model, 'Email').props.onChangeText('qa@example.test'); await press(reused.model, 'Send code'); await flush();
  input(reused.model, '6-digit code').props.onChangeText('111111'); await press(reused.model, 'Verify code'); await flush();
  passwordField(reused.model, 'New Password').props.onChangeText('SameOld9!'); passwordField(reused.model, 'Confirm New Password').props.onChangeText('SameOld9!');
  await press(reused.model, 'Set new password'); await flush();
  assert.equal(passwordField(reused.model, 'New Password').props.error, 'Choose a password different from your current password.');
  // Codes, tokens and passwords are never logged by the screen or its service.
  for (const file of ['app/forgot-password.tsx', 'services/passwordResetApi.ts']) assert(!/console\./.test(fs.readFileSync(path.join(root, file), 'utf8')), file + ' must not log');
  console.log('PASS: forgot password on iOS/Android: email -> 6-digit code (numeric, one-time-code autofill, resend countdown) -> new password with registration rule, expired token, cooldown, server field errors, nothing logged.');
}
function rsbsaUnit() {
  const r = rsbsaUtil;
  // Values come from the module's own VM context, so compare them as plain data.
  const same = (actual, expected) => assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected);
  // Pasted into any box, with or without hyphens, the whole number fills all five boxes.
  for (const [text, group] of [['160203005000123', 0], ['16-02-03-005-000123', 3], [' 16-02-03-005-000123 ', 4]]) {
    same(r.rsbsaApplyInput('', group, text), { value: '16-02-03-005-000123', focus: 4 });
  }
  // Leading zeroes are kept: the value is a string, never a number.
  same(r.rsbsaApplyInput('', 0, '000000000000001'), { value: '00-00-00-000-000001', focus: 4 });
  assert.equal(r.isCompleteRsbsaReference('00-00-00-000-000001'), true); assert.equal(r.rsbsaReferenceError('00-00-00-000-000001'), '');
  // Typing: a full box moves on; deleting inside a box does not.
  same(r.rsbsaApplyInput('', 0, '1'), { value: '1----', focus: null });
  same(r.rsbsaApplyInput('1----', 0, '16'), { value: '16----'.slice(0, 6), focus: 1 });
  same(r.rsbsaApplyInput('16----'.slice(0, 6), 0, '1'), { value: '1----', focus: null });
  // A partial paste spills into the following boxes; non-digits are ignored.
  same(r.rsbsaApplyInput('', 1, '02-03-0'), { value: '-02-03-0-', focus: 3 });
  same(r.rsbsaApplyInput('', 0, 'ab1c6'), { value: '16----'.slice(0, 6), focus: 1 });
  // Groups round-trip through the stored value, including bare digits from older data.
  same(r.rsbsaGroups('16-0--000-'), ['16', '0', '', '000', '']);
  same(r.rsbsaGroups('160203005000123'), ['16', '02', '03', '005', '000123']);
  assert.equal(r.rsbsaValue(['', '', '', '', '']), '');
  // Backspace: only an empty box moves back; the first box never does.
  same(r.rsbsaApplyBackspace('16-02---', 2), { value: '16-0---', focus: 1 });
  assert.equal(r.rsbsaApplyBackspace('16-02---', 1), null); assert.equal(r.rsbsaApplyBackspace('', 0), null);
  // Missing, incomplete and too-short values each get a clear message.
  assert.equal(r.rsbsaReferenceError(''), 'Enter the 15-digit RSBSA reference number printed on your card.');
  for (const value of ['16-02-03-005-00012', '16-02--005-000123', '1602030050001']) assert.equal(r.rsbsaReferenceError(value), 'Enter all 15 digits of the RSBSA reference number (00-00-00-000-000000).');
  console.log('PASS: RSBSA reference groups 2-2-2-3-6: paste with/without hyphens into any box, leading zeroes kept as a string, auto-advance, spill-over, Backspace across boxes, missing/incomplete errors.');
}
async function names() {
  const cases = [
    ['both', 'Dela Cruz', 'Jr.'],
    ['middle only', 'Ma. Luisa', ''],
    ['extension only', '', 'III'],
    ['neither', '', ''],
    ['placeholders', ' N/A ', 'none'],
  ];
  for (const [label, middle, extension] of cases) {
    const test = registration('android', 393), model = test.model;
    await fillRsbsa(model, '000102003000045'); press(model, 'Next');
    // Visual order: First, Middle, Last, Extension.
    const order = walk(model.render()).filter(n => n.props?.label && n.props.onChangeText).map(n => n.props.label);
    assert.deepEqual(order.slice(0, 4), ['First Name', 'Middle Name', 'Last Name', 'Extension Name']);
    assert.equal(field(model, 'Middle Name').props.required, false); assert.equal(field(model, 'Extension Name').props.required, false);
    fillPersonal(model, 'android');
    field(model, 'First Name').props.onChangeText('Maria  Clara'); field(model, 'Middle Name').props.onChangeText(middle);
    field(model, 'Last Name').props.onChangeText('Santos'); field(model, 'Extension Name').props.onChangeText(extension);
    press(model, 'Next'); assert(/Step 3 of 3/.test(text(model.render())), `${label}: optional names pass validation`);
    // Back keeps every name part; the placeholders were cleared, never kept as text.
    press(model, 'Back');
    const kept = label === 'placeholders' ? ['', ''] : [middle, extension];
    assert.equal(field(model, 'Middle Name').props.value, kept[0]); assert.equal(field(model, 'Extension Name').props.value, kept[1]);
    press(model, 'Next'); fillFarm(model); fillAccount(model); await press(model, 'Submit'); await flush();
    const data = test.submitted[0];
    assert.equal(data.firstName, 'Maria Clara'); assert.equal(data.lastName, 'Santos');
    assert.equal(data.middleName, kept[0], `${label}: middle name sent`); assert.equal(data.extensionName, kept[1], `${label}: extension sent`);
    assert(![data.middleName, data.extensionName].some(v => /^(n\/a|null|undefined)$/i.test(v)), 'no placeholder text is sent');
    assert.equal(data.idNumber, '00-01-02-003-000045');
    assert.equal(nameUtil.composeFullName(data.firstName, data.middleName, data.lastName, data.extensionName), ['Maria Clara', kept[0], 'Santos', kept[1]].filter(Boolean).join(' '));
  }
  // Invalid optional values get field-level errors and stop Continue.
  const bad = registration('ios', 393), m = bad.model;
  await fillRsbsa(m); press(m, 'Next'); fillPersonal(m, 'ios');
  field(m, 'Middle Name').props.onChangeText('Ma..ria'); field(m, 'Extension Name').props.onChangeText('Jr!!');
  press(m, 'Next'); assert(/Step 2 of 3/.test(text(m.render())));
  assert.equal(field(m, 'Middle Name').props.error, 'Enter a valid middle name, or leave it blank.');
  assert.equal(field(m, 'Extension Name').props.error, 'Enter a valid extension such as Jr., Sr., II or III, or leave it blank.');
  for (const ok of ['Sr.', 'II', 'IV', '3rd', 'Jr']) assert.equal(nameUtil.extensionNameError(ok), '', ok);
  for (const ok of ['D.', "O'Neil", 'Santos-Reyes', 'Dela Cruz', 'Ma. Luisa']) assert.equal(nameUtil.middleNameError(ok), '', ok);
  console.log('PASS: names First/Middle/Last/Extension in order; both, middle only, extension only, neither and N/A placeholders; values kept on Back, sent separately, composed without double spaces; invalid optional values refused.');
}
module.exports = { registration, fillRsbsa, fillPersonal, fillFarm, fillAccount, select, birth, press, field, byLabel, rsbsaField, rsbsaBoxes, authUI };
if (require.main === module) (async()=>{ for (const os of ['ios','android']) for (const width of [320,393,768]) await wizard(os,width); rsbsaUnit();await names();await submissionSafety();await homePin();await login();await recovery(); })().catch(error=>{console.error(error);process.exitCode=1;});
