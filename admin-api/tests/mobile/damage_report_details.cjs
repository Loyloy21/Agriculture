// Category-specific damage details: pure rules (mobile copy), parity with the PHP API rules, and the
// real report screen driven through the controlled harness. No phone/native rendering claims.
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { harness, walk, text, loadMetadata, loadDamageDetails, flush } = require('./mobile_redesign.cjs');

const D = loadDamageDetails();
const blank = () => D.emptyDamageDetails();
const draft = (values) => ({ ...blank(), ...values });
const json = (value) => JSON.stringify(value);

function rules() {
  // Every cause of every category is accepted; Other needs an explanation; Unsure needs nothing more.
  for (const [category, config] of Object.entries(D.DAMAGE_DETAILS_CONFIG)) {
    assert(config.causes.includes('Other') && config.causes.includes('Unsure'), category + ' offers Other and Unsure');
    for (const cause of config.causes) {
      const errors = D.damageDetailsErrors(category, draft({ subjectType: config.subjects[0], suspectedCause: cause }));
      assert.equal(errors.suspectedCause, undefined, category + ' accepts ' + cause);
      assert.equal(Boolean(errors.suspectedCauseOther), cause === 'Other', category + ' ' + cause + ' explanation rule');
    }
    const other = D.damageDetailsErrors(category, draft({ subjectType: 'Other', subjectTypeOther: ' ', suspectedCause: 'Other', suspectedCauseOther: 'ok' }));
    assert(other.subjectTypeOther && other.suspectedCauseOther, category + ' Other subject and cause need text');
    assert(D.damageDetailsErrors(category, blank()).suspectedCause, category + ' cause is required');
  }
  assert.equal(D.causeLabel('Unsure', ''), 'Unsure (cause not known)');
  assert.equal(D.causeLabel('Other', '  Neighbouring spray drift '), 'Other: Neighbouring spray drift');

  // Crops: farmer's 0-100% estimate for this crop/plot; areas optional, in hectares.
  const crop = (values) => D.damageDetailsErrors('Crops', draft({ subjectType: 'Corn', suspectedCause: 'Flooding', ...values }));
  for (const ok of ['0', '40', '99.5', '100', '100.00']) assert.equal(crop({ cropDamagePercent: ok }).cropDamagePercent, undefined, ok + '% accepted');
  for (const bad of ['100.01', '101', '1.234', '.5', '']) assert(crop({ cropDamagePercent: bad }).cropDamagePercent, bad + '% rejected');
  assert.equal(D.cleanNumberInput('-40%', true), '40', 'Negative sign and symbols cannot be typed');
  assert.equal(D.cleanNumberInput('1.2.3', true), '1.23');
  assert.equal(D.cleanNumberInput('12.5', false), '125', 'Whole-number fields drop decimal points');
  assert(crop({ cropDamagePercent: '40', plantedArea: '1', affectedArea: '2' }).affectedArea, 'Affected area cannot exceed planted');
  assert.equal(json(crop({ cropDamagePercent: '40', plantedArea: '', affectedArea: '0.8' })), '{}', 'Areas are optional');
  assert.equal(D.damageSummary('Crops', draft({ subjectType: 'Corn', cropDamagePercent: '40' })), 'Corn: 40% of this crop damaged');

  // Livestock and poultry: whole nonnegative counts, deaths <= total, calculated percentage.
  for (const [category, unit] of [['Livestock', 'head'], ['Poultry', 'birds']]) {
    const count = (values) => D.damageDetailsErrors(category, draft({ subjectType: D.DAMAGE_DETAILS_CONFIG[category].subjects[0], suspectedCause: 'Disease', ...values }));
    assert.equal(json(count({ totalBefore: '100', amountLost: '0' })), '{}', category + ' zero deaths accepted');
    assert.equal(json(count({ totalBefore: '100', amountLost: '100' })), '{}', category + ' all died accepted');
    assert(count({ totalBefore: '100', amountLost: '101' }).amountLost, category + ' deaths cannot exceed total');
    assert(count({ totalBefore: '10.5', amountLost: '1' }).totalBefore, category + ' total must be whole');
    assert(count({ totalBefore: '10', amountLost: '1.5' }).amountLost, category + ' deaths must be whole');
    assert(count({ totalBefore: '0', amountLost: '0' }).totalBefore, category + ' total must be more than 0');
    assert(count({ totalBefore: '', amountLost: '3' }).totalBefore, category + ' total is required');
    assert(count({ totalBefore: '-5', amountLost: '1' }).totalBefore, category + ' negative rejected');
    assert.equal(D.measureFor(category, ''), category === 'Poultry' ? 'bird_count' : 'head_count', category + ' keeps its own measure');
    assert.equal(D.unitFor(D.measureFor(category, '')), unit);
  }
  assert.equal(D.damageSummary('Livestock', draft({ totalBefore: '100', amountLost: '30' })), '30 of 100 head died · 30%');
  assert.equal(D.damageSummary('Poultry', draft({ totalBefore: '500', amountLost: '120' })), '120 of 500 birds died · 24%');
  assert.equal(D.damageSummary('Poultry', draft({ totalBefore: '3', amountLost: '1' })), '1 of 3 birds died · 33.3%');
  assert.equal(D.damageSummary('Livestock', draft({ totalBefore: '10', amountLost: '11' })), '', 'No summary for impossible figures');

  // Fishpond: one unit per report; total optional; no invented percentage.
  const fish = (values) => D.damageDetailsErrors('Fishpond', draft({ subjectType: 'Tilapia', suspectedCause: 'Low oxygen / fish kill', ...values }));
  assert(fish({ amountLost: '5' }).fishUnit, 'A fish unit must be chosen');
  assert.equal(json(fish({ fishUnit: 'kg', amountLost: '50', totalBefore: '200' })), '{}');
  assert.equal(json(fish({ fishUnit: 'kg', amountLost: '12.75', totalBefore: '' })), '{}', 'kg allows two decimals and an unknown total');
  assert(fish({ fishUnit: 'kg', amountLost: '1.255' }).amountLost, 'kg allows at most two decimals');
  assert(fish({ fishUnit: 'count', amountLost: '2.5' }).amountLost, 'Fish counts are whole numbers');
  assert(fish({ fishUnit: 'count', amountLost: '300', totalBefore: '200' }).amountLost, 'Loss cannot exceed known stock');
  assert(fish({ fishUnit: 'kg', amountLost: '' }).amountLost, 'Amount lost is required');
  assert.equal(D.damageSummary('Fishpond', draft({ fishUnit: 'kg', amountLost: '50', totalBefore: '200' })), '50 of 200 kg lost · 25%');
  assert.equal(D.damageSummary('Fishpond', draft({ fishUnit: 'count', amountLost: '50', totalBefore: '' })), '50 fish lost · total stock not provided');
  assert.equal(D.lossPercent(null, 50), null, 'Unknown total gives no percentage');
  assert.equal(D.measureFor('Fishpond', 'kg'), 'fish_weight_kg');
  assert.equal(D.measureFor('Fishpond', 'count'), 'fish_count');

  // Only the current category's figures are sent; never a peso amount.
  const sent = D.damageDetailsFields('Livestock', draft({ subjectType: 'Pigs', suspectedCause: 'Other', suspectedCauseOther: ' Feed poisoning ', cropDamagePercent: '40', totalBefore: '100', amountLost: '30' }));
  assert.equal(json(sent), json({ damage_details_version: '1', subject_type: 'Pigs', subject_type_other: '', suspected_cause: 'Other', suspected_cause_other: 'Feed poisoning', total_before: '100', amount_lost: '30' }));
  assert.equal(D.damageDetailsFields('Fishpond', draft({ fishUnit: 'kg', amountLost: '5' })).fish_unit, 'kg');
  assert(!Object.keys(D.damageDetailsFields('Crops', draft({ totalBefore: '5' }))).includes('total_before'));
  console.log('PASS: all four category rules, every cause incl. Other/Unsure, crop limits, whole counts, kg decimals, percentages, unknown fish total and category-only payload.');
}

function phpParity() {
  const projectRoot = process.env.AGRIREACH_PHP_ROOT || path.resolve(__dirname, '../..');
  const lib = path.join(projectRoot, 'backend/lib/damage_report_details.php').replaceAll('\\', '/');
  const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
  const code = `require '${lib}'; $c = damage_details_config(); $s = [
    damage_details_summary('head_count', 'Pigs', ['total_before' => '100', 'amount_lost' => '30']),
    damage_details_summary('bird_count', 'Chickens', ['total_before' => '500', 'amount_lost' => '120']),
    damage_details_summary('fish_weight_kg', 'Tilapia', ['total_before' => '200', 'amount_lost' => '50']),
    damage_details_summary('fish_count', 'Tilapia', ['total_before' => null, 'amount_lost' => '50']),
    damage_details_summary('bird_count', 'Chickens', ['total_before' => '3', 'amount_lost' => '1'])];
    echo json_encode(['config' => $c, 'summaries' => $s], JSON_UNESCAPED_UNICODE);`;
  const result = JSON.parse(execFileSync(php, ['-r', code], { encoding: 'utf8' }));
  for (const [category, config] of Object.entries(D.DAMAGE_DETAILS_CONFIG)) {
    const server = result.config[category];
    assert(server, category + ' exists in PHP');
    for (const key of ['measure', 'subjectLabel', 'subjects', 'causes', 'unit']) assert.equal(json(server[key]), json(config[key]), category + ' ' + key + ' matches PHP');
  }
  assert.equal(json(Object.keys(result.config)), json(Object.keys(D.DAMAGE_DETAILS_CONFIG)));
  assert.equal(json(result.summaries), json([
    D.damageSummary('Livestock', draft({ totalBefore: '100', amountLost: '30' })),
    D.damageSummary('Poultry', draft({ totalBefore: '500', amountLost: '120' })),
    D.damageSummary('Fishpond', draft({ fishUnit: 'kg', totalBefore: '200', amountLost: '50' })),
    D.damageSummary('Fishpond', draft({ fishUnit: 'count', amountLost: '50' })),
    D.damageSummary('Poultry', draft({ totalBefore: '3', amountLost: '1' })),
  ]), 'Mobile and server summaries are identical');
  console.log('PASS: mobile option lists, measures, units and summaries match the PHP API (' + lib.replace(/^.*\/(htdocs|scratchpad)\//, '$1/') + ').');
}

async function screen() {
  const metadata = loadMetadata();
  const farms = [
    { id: 1, name: 'Rice field', status: 'Active', agriculturalCategory: 'Crops', barangayName: 'Controlled A' },
    { id: 2, name: 'Piggery', status: 'Active', agriculturalCategory: 'Livestock', barangayName: 'Controlled B' },
    { id: 3, name: 'Layer house', status: 'Active', agriculturalCategory: 'Poultry', barangayName: 'Controlled C' },
    { id: 4, name: 'Tilapia pond', status: 'Active', agriculturalCategory: 'Fishpond', barangayName: 'Controlled D' },
  ];
  let submissions = [];
  const model = harness('app/reports/new.tsx', {
    '@/services/photoLocationMetadata': metadata,
    '@/services/galleryOperation': { galleryEvent: () => {}, galleryOperation: async (_stage, fn) => fn(), GalleryTimeoutError: class extends Error {} },
    '@/services/androidSystemPhotoPicker': { androidOriginalPickerAvailable: () => false },
    '@/services/photoLocation': {},
    '@/services/galleryPhotoEvidence': { galleryPhotoEvidence: async (asset) => ({ ...metadata.emptyPhotoEvidence(), latitude: asset.lat, longitude: asset.lon, locationSource: 'photo_exif', locationStatus: 'available' }) },
    '@/services/uploadImages': { MAX_DAMAGE_PHOTOS: 10, MAX_DAMAGE_IMAGE_BYTES: 5242880, MAX_DAMAGE_TOTAL_BYTES: 33554432, UploadImagePreparationError: class extends Error {}, preparePickedImage: async (a) => ({ uri: a.uri + '-prepared', size: 100, fileName: 'controlled.jpg', mimeType: 'image/jpeg' }) },
    'expo-image-picker': { UIImagePickerPreferredAssetRepresentationMode: { Current: 'current' }, launchImageLibraryAsync: async () => ({ canceled: false, assets: [{ uri: 'controlled-A', lat: 9.12, lon: 125.53 }] }) },
    '@/services/farmerDataApi': { FarmerDataApiError: class extends Error {}, listFarmerFarms: async () => ({ farms }), validateDamagePoint: async () => {}, submitDamageReport: async (payload) => { submissions.push(payload); } },
  }, 'android', 390);
  model.render(); await flush();
  const tree = () => model.render();
  const field = (label) => walk(tree()).find((n) => n.props?.label === label);
  // Step 1 only (later steps stay mounted while hidden).
  const stepOne = () => walk(tree()).find((n) => n.type === 'View' && text(n).startsWith('Affected Farm *'));
  const labels = () => walk(stepOne()).filter((n) => n.props?.label && n.props.onChangeText).map((n) => n.props.label);
  const pick = (title, value) => walk(tree()).find((n) => n.props?.title === title && n.props.onSelect).props.onSelect(value);
  const chooseFarm = (name) => {
    const sheet = walk(tree()).find((n) => n.type === 'Modal' && text(n).includes('Choose affected farm'));
    walk(sheet).find((n) => n.type === 'Pressable' && text(n).includes(name)).props.onPress();
  };
  const next = () => walk(tree()).find((n) => n.type === 'Pressable' && text(n) === 'Next: Photos & location').props.onPress();
  const lossText = () => walk(tree()).find((n) => typeof n.props?.accessibilityLabel === 'string' && n.props.accessibilityLabel.startsWith("Farmer's reported loss")).props.accessibilityLabel;

  assert(!labels().includes('Estimated crop damage'), 'No category fields before a farm is chosen');
  chooseFarm('Rice field');
  assert(text(tree()).includes('CROPS'));
  assert.deepEqual(labels(), ['What happened?', 'Estimated crop damage', 'Planted area', 'Affected area']);
  assert.equal(field('Planted area').props.unit, 'hectares');
  assert.equal(field('Planted area').props.optional, true);
  pick('Select crop', 'Corn'); pick('Suspected cause', 'Other');
  assert(labels().includes('Explain the other cause'), 'Other cause asks for an explanation');
  next();
  assert(walk(tree()).some((n) => n.props?.error === 'Explain the other cause (3 to 255 characters).'), 'Other explanation required');
  pick('Suspected cause', 'Unsure');
  assert(!labels().includes('Explain the other cause'), 'Changing cause hides and clears the explanation');
  field('Estimated crop damage').props.onChangeText('140');
  next();
  assert(walk(tree()).some((n) => n.props?.error === 'Enter a percentage from 0 to 100.'));
  field('Estimated crop damage').props.onChangeText('40');
  assert.equal(lossText(), "Farmer's reported loss: Corn: 40% of this crop damaged");

  chooseFarm('Piggery');
  assert(text(tree()).includes('LIVESTOCK'));
  assert.deepEqual(labels(), ['What happened?', 'Total head before the incident', 'Number that died'], 'Switching category shows only livestock fields');
  assert.equal(field('Number that died').props.value, '', 'No stale values after switching');
  assert(!text(tree()).includes('Corn'), 'Previous crop is cleared');
  assert(walk(tree()).some((n) => n.props?.title === 'Select animal type'));
  pick('Select animal type', 'Pigs'); pick('Suspected cause', 'Disease');
  field('Total head before the incident').props.onChangeText('100'); field('Number that died').props.onChangeText('130');
  next();
  assert(walk(tree()).some((n) => n.props?.error === 'The number that died cannot be more than the total before the incident.'));
  field('Number that died').props.onChangeText('30');
  assert.equal(lossText(), "Farmer's reported loss: 30 of 100 head died · 30%");

  chooseFarm('Layer house');
  assert(text(tree()).includes('POULTRY'));
  assert.deepEqual(labels(), ['What happened?', 'Total birds before the incident', 'Number that died']);
  assert.equal(field('Total birds before the incident').props.value, '');
  assert.equal(field('Number that died').props.unit, 'birds');
  pick('Select bird type', 'Chickens'); pick('Suspected cause', 'Heat stress');
  field('Total birds before the incident').props.onChangeText('500'); field('Number that died').props.onChangeText('120');
  assert.equal(lossText(), "Farmer's reported loss: 120 of 500 birds died · 24%");

  chooseFarm('Tilapia pond');
  assert(text(tree()).includes('FISHPOND'));
  assert.deepEqual(labels(), ['What happened?'], 'Fish amount fields wait for a unit');
  const unit = (label) => walk(tree()).find((n) => n.props?.accessibilityRole === 'radio' && n.props.accessibilityLabel === label);
  unit('Kilograms (kg)').props.onPress();
  assert.deepEqual(labels(), ['What happened?', 'Amount lost', 'Total stock before the incident']);
  assert.equal(field('Amount lost').props.unit, 'kg'); assert.equal(field('Amount lost').props.decimal, true);
  field('Amount lost').props.onChangeText('50'); field('Total stock before the incident').props.onChangeText('200');
  assert.equal(lossText(), "Farmer's reported loss: 50 of 200 kg lost · 25%");
  unit('Count (fish)').props.onPress();
  assert.equal(field('Amount lost').props.value, '', 'Switching unit clears the kilogram figures');
  assert.equal(field('Amount lost').props.unit, 'fish'); assert.equal(field('Amount lost').props.decimal, false);
  pick('Select fish type', 'Other');
  field('Type the fish type').props.onChangeText('Pangasius');
  pick('Suspected cause', 'Low oxygen / fish kill');
  field('Amount lost').props.onChangeText('50');
  assert.equal(lossText(), "Farmer's reported loss: 50 fish lost · total stock not provided");
  next();
  assert(text(tree()).includes('2 of 3'), 'Valid fishpond details move to the unchanged photo step');

  // Photo/location step stays as it was; then review and submit.
  await walk(tree()).find((n) => n.type === 'Pressable' && text(n) === 'Choose from Gallery').props.onPress(); await flush();
  field('Address / Landmark').props.onChangeText('Controlled Cabadbaran landmark');
  await walk(tree()).find((n) => n.type === 'Pressable' && text(n) === 'This is the damaged location').props.onPress(); await flush();
  walk(tree()).find((n) => n.type === 'Pressable' && text(n) === 'Next: Review report').props.onPress();
  const review = text(tree());
  for (const expected of ['Pangasius', 'Low oxygen / fish kill', '50 fish lost · total stock not provided', 'Not provided']) assert(review.includes(expected), 'Review shows ' + expected);
  assert(!review.includes('₱'), 'Review shows no peso estimate');
  await walk(tree()).find((n) => n.type === 'Pressable' && text(n) === 'Submit Report').props.onPress(); await flush();
  assert.equal(submissions.length, 1);
  assert.equal(submissions[0].farmId, 4);
  assert.equal(json(submissions[0].detailFields), json({ damage_details_version: '1', subject_type: 'Other', subject_type_other: 'Pangasius', suspected_cause: 'Low oxygen / fish kill', suspected_cause_other: '', fish_unit: 'count', total_before: '', amount_lost: '50' }));
  assert.equal(submissions[0].photos.length, 1); assert.equal(submissions[0].locationConfirmed, true);
  console.log('PASS: screen shows only the selected category, clears stale values on category and fish-unit switches, validates Other/limits/deaths, live summaries, unchanged photo step and category-only submit payload.');
}

async function detailScreen() {
  const metadata = loadMetadata();
  const base = { id: 'controlled-report', status: 'Resolved', photos: [], farmType: 'Livestock', farmName: 'Piggery', latitude: null, longitude: null, location: 'Controlled', calamityType: 'Disease', description: '' };
  const details = { version: 1, measureType: 'head_count', unit: 'head', subjectLabel: 'Animal type', subjectType: 'Pigs', suspectedCause: 'Disease', suspectedCauseOther: null, suspectedCauseLabel: 'Disease', cropDamagePercent: null, plantedAreaHa: null, affectedAreaHa: null, totalBefore: 100, amountLost: 30, lossPercent: 30, summary: '30 of 100 head died · 30%',
    verified: { cause: 'Disease', causeOther: null, causeLabel: 'Disease', cropDamagePercent: null, plantedAreaHa: null, affectedAreaHa: null, totalBefore: 100, amountLost: 28, lossPercent: 28, summary: '28 of 100 head died · 28%', reviewNotes: null, verifiedBy: 'Controlled admin', verifiedAt: '2026-10-23T08:00:00+08:00' } };
  const render = async (report) => {
    const model = harness('app/reports/[id].tsx', { '@/services/photoLocationMetadata': metadata, '@/services/farmerDataApi': { FarmerDataApiError: class extends Error {}, getDamageReport: async () => report } });
    model.render(); await flush();
    // Detail/SectionHeading are not rendered by the harness, so include their label/value/title props.
    const tree = model.render();
    return text(tree) + ' ' + walk(tree).flatMap((n) => [n.props?.label, n.props?.value, n.props?.title]).filter((v) => typeof v === 'string').join(' | ');
  };
  const current = await render({ ...base, estimatedCost: null, damageDetails: details });
  for (const expected of ['Pigs', 'Disease', '30 of 100 head died · 30%', 'Not provided', 'Verified by the Department of Agriculture', '28 of 100 head died · 28%']) assert(current.includes(expected), 'Detail shows ' + expected);
  assert(!current.includes('₱') && !current.includes('Estimated damage cost'), 'No peso value for category reports');
  const legacy = await render({ ...base, estimatedCost: 1200, description: 'Older report', damageDetails: null });
  assert(legacy.includes('Estimated damage cost') && legacy.includes('₱') && legacy.includes('Older report'), 'Older reports keep cost and description');
  console.log('PASS: report detail shows farmer-reported figures, verified figures, no peso value for new reports, and older reports unchanged.');
}

(async () => { rules(); phpParity(); await screen(); await detailScreen(); })().catch((error) => { console.error(error); process.exitCode = 1; });
