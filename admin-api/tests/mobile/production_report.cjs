// Production report (mobile): pure rules and their parity with the PHP API rules, the commodity catalog search
// (run against the real catalog in the 0007 migration), the Reports choice screen, the Production Report screen,
// the Add Production Record form (farm area -> category -> commodity -> date -> quantity -> unit -> remarks;
// dependent and searchable selections, units, review, submit lock, possible duplicate, server errors, success),
// the searchable picker, shared production components and the Reports-tab navigation.
// Controlled harness only: no phone/native rendering claims and no API writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const ts = require('../../frontend/mobile/node_modules/typescript');
const { harness, walk, text, flush } = require('./mobile_redesign.cjs');

const root = path.join(__dirname, '../../frontend/mobile/src');
const jsx = (type, props) => ({ type, props });
function loadModule(file, mocks = {}) {
  const exports = {};
  const js = ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  vm.runInNewContext(js, { exports, require: (name) => {
    if (name.startsWith('@/assets/')) return name;
    if (!(name in mocks)) throw Error('Unexpected import ' + name);
    return mocks[name];
  } });
  return exports;
}
const P = loadModule('utils/production.ts');
const button = (tree, title) => walk(tree).find((n) => n.type === 'Action' && n.props.title === title) || walk(tree).find((n) => n.type === 'Pressable' && walk(n).some((c) => c.type === 'Text' && text(c) === title));
const labelled = (tree, prefix) => walk(tree).find((n) => typeof n.props?.accessibilityLabel === 'string' && n.props.accessibilityLabel.startsWith(prefix));
// Objects built inside the VM realm have other prototypes, so compare them as JSON.
const sameJson = (actual, expected, message) => assert.equal(JSON.stringify(actual), JSON.stringify(expected), message);
class ApiError extends Error { constructor(message, status, errors = {}) { super(message); this.status = status; this.errors = errors; } }
function routerMock() {
  const calls = { push: [], replace: [], back: 0 };
  let focused = false;
  return { calls, module: {
    router: { push: (route) => calls.push.push(route), replace: (route) => calls.replace.push(route), back: () => { calls.back += 1; }, canGoBack: () => true },
    useLocalSearchParams: () => ({}),
    // Runs the focus effect once, like the first focus of the screen.
    useFocusEffect: (fn) => { if (!focused) { focused = true; fn(); } },
  } };
}
const productionUiMock = { productionStyles: {}, CropBreakdown: 'CropBreakdown', OtherMeasures: 'OtherMeasures', ProductionRecordCard: 'ProductionRecordCard', ProductionStatusBadge: 'ProductionStatusBadge' };

// The real catalog, read from the PostgreSQL migration (same rows as the MySQL mirror, checked below).
const migration = (file) => fs.readFileSync(path.join(__dirname, '../../database', file), 'utf8');
function catalogRows(sql) {
  const block = sql.match(/INSERT INTO (?:public\.)?commodities \(name, agricultural_category, commodity_group, product_type, default_unit_code, search_names, status\) VALUES\n([\s\S]*?)\n(?:ON CONFLICT|ON DUPLICATE)/);
  assert(block, 'Catalog block found');
  return block[1].split('\n').map((line) => {
    const values = [...line.matchAll(/'((?:[^']|'')*)'/g)].map((m) => m[1].replace(/''/g, "'"));
    assert.equal(values.length, 7, 'Catalog row has 7 values: ' + line);
    const [name, category, group, productType, defaultUnit, searchNames, status] = values;
    return { name, category, group, productType, defaultUnit, searchNames, status };
  });
}
const pgRows = catalogRows(migration('pgsql/20261024_0007_commodity_catalog_and_units.sql'));
const myRows = catalogRows(migration('migrations/20261024_commodity_catalog_and_units.sql'));
const unitsFor = (row) => row.productType === 'crop' || row.productType === 'aquaculture' ? (row.name === 'Coconut' ? ['piece', 'kg', 'mt'] : ['kg', 'mt'])
  : row.productType === 'animal_product' ? (row.category === 'Livestock' ? ['liter'] : ['piece', 'tray']) : ['head', 'kg'];
const catalogCommodities = pgRows.filter((row) => row.status === 'Active').map((row, index) => ({ id: index + 1, ...row, units: unitsFor(row) }));

function rules() {
  assert.equal(P.manilaToday(new Date('2026-10-23T15:59:59Z')), '2026-10-23');
  assert.equal(P.manilaToday(new Date('2026-10-23T16:00:00Z')), '2026-10-24', 'Manila date rolls over at 16:00 UTC');
  assert.equal(P.previousMonthKey('2027-01'), '2026-12', 'January 2027 -> December 2026');
  assert.equal(P.previousMonthKey('2026-10'), '2026-09');
  assert.equal(P.monthLabel('2026-10'), 'October 2026');
  assert.equal(P.formatHarvestDate('2026-10-23'), 'October 23, 2026');
  assert.equal(P.formatHarvestDate('2026-02-30'), 'Not recorded');
  assert.equal(P.dateKey(P.dateFromKey('2024-02-29')), '2024-02-29');
  const kg = { code: 'kg', symbol: 'kg', decimals: 2, kgPerUnit: 1 }, mt = { code: 'mt', symbol: 'MT', decimals: 3, kgPerUnit: 1000 };
  const head = { code: 'head', symbol: 'head', decimals: 0, kgPerUnit: null }, liter = { code: 'liter', symbol: 'L', decimals: 2, kgPerUnit: null };
  assert.equal(P.cleanQuantityInput('12a5.5.55', 2), '125.55', 'Letters and extra points removed, two decimals kept');
  assert.equal(P.cleanQuantityInput('1.23456', 3), '1.234');
  assert.equal(P.cleanQuantityInput('12.5', 0), '125', 'Whole-number units drop the decimal point');
  assert.equal(P.cleanQuantityInput('-40 kg', 2), '40', 'A minus sign cannot be typed');
  for (const ok of ['1', '0.01', '125.5', '1000000']) assert.equal(P.quantityError(ok, kg), undefined, ok + ' kg accepted');
  for (const bad of ['', '0', '0.00', '.5', '1.234', '1000000.01', '12kg']) assert(P.quantityError(bad, kg), bad + ' kg rejected');
  assert.equal(P.quantityError('1.234', mt), undefined, 'MT allows three decimals');
  assert.match(P.quantityError('1000.001', mt), /1,000 MT/, 'MT respects the kilogram limit');
  assert.match(P.quantityError('2.5', head), /whole number/, 'Head counts are whole numbers');
  assert.equal(P.quantityError('30.5', liter), undefined);
  assert.equal(P.formatKg(1250), '1,250 kg');
  assert.equal(P.formatKg(0.1 + 0.2), '0.3 kg', 'No float artefacts');
  assert.equal(P.formatQuantity(1200, 'pcs'), '1,200 pcs');
  assert.equal(P.formatQuantity(0.2, 'MT'), '0.2 MT');
  assert.equal(P.formatQuantity(1234567.125, 'kg'), '1,234,567.125 kg');
  assert.equal(P.otherTotalsLine([{ total: 1200, unitSymbol: 'pcs' }, { total: 5, unitSymbol: 'head' }]), '1,200 pcs · 5 head');
  assert.equal(P.formatPercent(48), '48%');
  const today = '2026-10-23';
  const rice = { id: 1, name: 'Palay (Rice)', category: 'Crops', group: 'Rice and Cereals', searchNames: 'palay', units: ['kg', 'mt'] };
  const valid = { farmId: 7, category: 'Crops', commodityId: 1, harvestDate: today, quantity: '125.5', unitCode: 'kg', remarks: '' };
  assert.equal(JSON.stringify(P.productionDraftErrors(valid, today, rice, kg)), '{}');
  const empty = P.productionDraftErrors({ farmId: null, category: null, commodityId: null, harvestDate: '', quantity: '', unitCode: null, remarks: '' }, today, null, null);
  assert.deepEqual(Object.keys(empty), ['farmId', 'category', 'commodityId', 'harvestDate', 'unitCode', 'quantity'], 'Every required field, in form order');
  assert(P.productionDraftErrors({ ...valid, category: 'Poultry' }, today, rice, kg).commodityId, 'A commodity of another category is rejected');
  assert(P.productionDraftErrors({ ...valid, unitCode: 'liter' }, today, rice, liter).unitCode, 'A unit the commodity does not use is rejected');
  assert.equal(P.productionDraftErrors({ ...valid, harvestDate: '2026-10-24' }, today, rice, kg).harvestDate, 'The date cannot be in the future.');
  assert(P.productionDraftErrors({ ...valid, remarks: 'bell\u0007' }, today, rice, kg).remarks);

  // Parity: for each unit's decimals, the mobile rule accepts exactly what the PHP API accepts.
  const inputs = ['1', '0.01', '125.5', '125.50', '1.234', '1.2345', '1000000', '0', '0.00', '-5', 'abc', '1e3', '1000000.01', '12 kg', '.5', '5.', '0007', '2.5'];
  const lib = path.join(__dirname, '../../backend/lib/production_records.php').replace(/\\/g, '/');
  const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
  const server = JSON.parse(execFileSync(php, ['-r', `require '${lib}'; $in = json_decode($argv[1]); $out = []; foreach ([0, 2, 3] as $d) $out[$d] = array_map(fn($v) => production_quantity($v, $d) !== null, $in); echo json_encode(['max' => PRODUCTION_MAX_QUANTITY, 'maxKg' => PRODUCTION_MAX_QUANTITY_KG, 'remarks' => PRODUCTION_REMARKS_MAX_LENGTH, 'earliest' => PRODUCTION_EARLIEST_HARVEST_DATE, 'categories' => PRODUCTION_CATEGORIES, 'accepted' => $out]);`, JSON.stringify(inputs)], { encoding: 'utf8' }));
  for (const decimals of [0, 2, 3]) {
    const unit = { code: 'x', symbol: 'x', decimals, kgPerUnit: null };
    inputs.forEach((input, index) => assert.equal(P.quantityError(input, unit) === undefined, server.accepted[decimals][index], `"${input}" with ${decimals} decimals: mobile and PHP agree`));
  }
  assert.equal(server.max, P.PRODUCTION_MAX_QUANTITY);
  assert.equal(server.maxKg, P.PRODUCTION_MAX_QUANTITY_KG);
  assert.equal(server.remarks, P.PRODUCTION_REMARKS_MAX_LENGTH);
  assert.equal(server.earliest, P.PRODUCTION_EARLIEST_HARVEST_DATE);
  sameJson(server.categories, { Crops: 'Crops', Livestock: 'Livestock', Poultry: 'Poultry', Fishpond: 'Aquaculture / Fishpond' });
  console.log('PASS: Manila date, month keys (Jan 2027 -> Dec 2026), per-unit quantity rules and formatting, draft validation in form order, PHP parity for ' + inputs.length + ' quantities x 3 decimal rules');
}

function catalog() {
  sameJson(myRows, pgRows, 'PostgreSQL and MySQL migrations seed identical catalog rows');
  const keys = new Set();
  for (const row of pgRows) {
    const key = row.category + '|' + row.name;
    assert(!keys.has(key), 'No duplicate commodity ' + key);
    keys.add(key);
    assert(['Crops', 'Livestock', 'Poultry', 'Fishpond'].includes(row.category) && row.group && row.searchNames, 'Complete row ' + key);
    assert(unitsFor(row).includes(row.defaultUnit), 'Default unit allowed for ' + key);
  }
  const active = catalogCommodities;
  const count = (category) => active.filter((row) => row.category === category).length;
  assert(active.length >= 140 && count('Crops') >= 90 && count('Livestock') >= 15 && count('Poultry') >= 10 && count('Fishpond') >= 15, 'A broad catalog in every category');
  const names = (category, query) => P.searchCommodities(active, category, query).map((item) => item.name);
  sameJson(names('Crops', 'banana'), ['Banana (Saba)', 'Banana (Lakatan)', 'Banana (Latundan)', 'Cavendish Banana'], 'Crops + "banana": bananas only');
  sameJson(names('Livestock', 'goat'), ['Native Goat', 'Meat Goat', 'Dairy Goat', "Goat's Milk"], 'Livestock + "goat": goat choices');
  sameJson(names('Poultry', 'egg'), ['Chicken Eggs', 'Duck Eggs', 'Quail Eggs'], 'Poultry + "egg": egg products only');
  sameJson(names('Fishpond', 'tilapia'), ['Tilapia'], 'Aquaculture + "tilapia"');
  // Filipino and local names, any case, partial words.
  sameJson(names('Crops', 'HUMAY'), ['Palay (Rice)'], 'Bisaya "humay" finds palay');
  sameJson(names('Crops', 'saging'), ['Banana (Saba)', 'Banana (Lakatan)', 'Banana (Latundan)', 'Cavendish Banana']);
  sameJson(names('Crops', 'kamote'), ['Sweet Potato (Kamote)', 'Cassava (Kamoteng Kahoy)'], 'Partial match, displayed-name matches first');
  sameJson(names('Crops', 'talong'), ['Eggplant (Talong)']);
  sameJson(names('Crops', 'kamunggay'), ['Malunggay (Moringa)']);
  sameJson(names('Poultry', 'itlog'), ['Chicken Eggs', 'Duck Eggs', 'Quail Eggs']);
  sameJson(names('Poultry', 'itik'), ['Native Duck (Itik)', 'Duck Eggs']);
  sameJson(names('Fishpond', 'bangus'), ['Milkfish (Bangus)']);
  sameJson(names('Fishpond', 'sugpo'), ['Tiger Prawn (Sugpo)']);
  sameJson(names('Livestock', 'baboy'), ['Pig / Hog (Fattener)', 'Native Pig', 'Piglets (Weanlings)', 'Breeder Sow (Sold or Culled)', 'Boar (Sold or Culled)']);
  sameJson(names('Livestock', 'gatas kalabaw'), ["Carabao's Milk"], 'Several words must all match');
  // The selected category is always respected.
  sameJson(names('Crops', 'tilapia'), [], 'No aquaculture item under Crops');
  sameJson(names('Poultry', 'banana'), []);
  sameJson(names(null, 'banana'), [], 'No category -> no commodities');
  sameJson(names('Crops', 'zzzz'), [], 'No match -> empty (the picker shows "No matching commodity found")');
  assert.equal(names('Crops', '').length, count('Crops'), 'Empty search lists the whole category');
  assert(!names('Crops', 'corn').includes('Corn'), 'Inactive generic commodities are not offered');
  const started = Date.now();
  const large = Array.from({ length: 10 }, (_, copy) => active.map((item) => ({ ...item, id: copy * 1000 + item.id }))).flat();
  for (const query of ['b', 'ba', 'ban', 'bana', 'banan', 'banana']) P.searchCommodities(large, 'Crops', query);
  assert(Date.now() - started < 500, 'Searching ' + large.length + ' commodities stays fast while typing');
  console.log(`PASS: catalog ${pgRows.length} rows (${active.length} active; identical in both migrations, no duplicates, default units allowed); search by English/Filipino/Bisaya names, case-insensitive, partial, within the category`);
}

const categories = [{ code: 'Crops', label: 'Crops' }, { code: 'Livestock', label: 'Livestock' }, { code: 'Poultry', label: 'Poultry' }, { code: 'Fishpond', label: 'Aquaculture / Fishpond' }];
const units = [
  { code: 'kg', name: 'Kilograms', symbol: 'kg', measure: 'weight', kgPerUnit: 1, decimals: 2 }, { code: 'mt', name: 'Metric tons', symbol: 'MT', measure: 'weight', kgPerUnit: 1000, decimals: 3 },
  { code: 'head', name: 'Head', symbol: 'head', measure: 'count', kgPerUnit: null, decimals: 0 }, { code: 'piece', name: 'Pieces', symbol: 'pcs', measure: 'count', kgPerUnit: null, decimals: 0 },
  { code: 'tray', name: 'Trays', symbol: 'trays', measure: 'count', kgPerUnit: null, decimals: 0 }, { code: 'liter', name: 'Liters', symbol: 'L', measure: 'volume', kgPerUnit: null, decimals: 2 },
];
const commodityCatalog = { categories, units, commodities: catalogCommodities };
const byName = (name) => catalogCommodities.find((item) => item.name === name);
const farms = [
  { id: 7, name: 'Main Farm', status: 'Active', agriculturalCategory: 'Crops', barangayName: 'Controlled barangay', address: '', mainProduct: 'rice' },
  { id: 8, name: 'Old Farm', status: 'Archived', agriculturalCategory: 'Crops', barangayName: 'Old barangay', address: '', mainProduct: '' },
];
const savedRecord = { id: '41', commodityId: 1, commodityName: 'Chicken Eggs', category: 'Poultry', categoryLabel: 'Poultry', commodityGroup: 'Poultry Products', farmId: 7, farmName: 'Main Farm', farmBarangay: null,
  harvestDate: P.manilaToday(), quantity: 1200, unitCode: 'piece', unitName: 'Pieces', unitSymbol: 'pcs', quantityKg: null, remarks: 'Note', status: 'Submitted', counted: true, submittedAt: '2026-10-23 10:00:00', updatedAt: '2026-10-23 10:00:00' };
function formModel(os, width, farmList, submitProductionRecord) {
  const nav = routerMock();
  const model = harness('app/production/new.tsx', {
    'expo-router': nav.module,
    '@react-native-community/datetimepicker': { __esModule: true, default: 'DateTimePicker' },
    '@/components/production-ui': productionUiMock,
    '@/components/searchable-picker': { __esModule: true, default: 'SearchablePicker' },
    '@/utils/production': P,
    '@/services/farmerDataApi': { FarmerDataApiError: ApiError, getProductionCatalog: async () => commodityCatalog, listFarmerFarms: async () => ({ farms: farmList, barangays: [] }), submitProductionRecord },
  }, os, width);
  const picker = (titlePrefix) => walk(model.render()).find((n) => n.type === 'SearchablePicker' && n.props.title.startsWith(titlePrefix));
  const titles = (sections) => sections.flatMap((section) => section.data.map((item) => item.title));
  return { model, nav, picker, titles };
}

async function form(os, width) {
  const submissions = [];
  const responses = [];
  const { model, nav, picker, titles } = formModel(os, width, farms, (payload) => { submissions.push(payload); return new Promise((resolve, reject) => responses.push({ resolve, reject })); });
  model.render(); await flush();
  let tree = model.render();
  // Field order: farm area, category, commodity, date, quantity, unit, remarks.
  const order = walk(tree).filter((n) => n.type === 'Text' && /^\d\. /.test(text(n))).map((n) => text(n).replace(/ \*$/, '').replace(/ \(optional\)$/, ''));
  sameJson(order, ['1. Farm Area / Farm Location', '2. Production Category', '3. Commodity / Agricultural Product', '4. Date Harvested / Produced', '5. Quantity Produced', '6. Unit of Measurement', '7. Remarks / Notes'], 'Exact field order');
  assert(labelled(tree, 'Farm area: Main Farm'), 'The only active farm area is preselected');
  assert(!JSON.stringify(picker('Select your farm area').props.getSections('')).includes('Old Farm'), 'Archived farms are not offered');
  assert.equal(picker('Select your farm area').props.searchable, false, 'One farm area: no search needed');
  assert(labelled(tree, 'Production category: Crops'), "The farm area's registered activity is suggested");
  assert(labelled(tree, 'Commodity: not selected').props.disabled === false, 'Commodity enabled once a category is set');
  assert(labelled(tree, 'Date harvested or produced: ' + P.formatHarvestDate(P.manilaToday())), 'Date defaults to today (Manila)');
  labelled(tree, 'Date harvested or produced').props.onPress();
  tree = model.render();
  const datePicker = walk(tree).find((n) => n.type === 'DateTimePicker');
  assert(datePicker && P.dateKey(datePicker.props.maximumDate) === P.manilaToday(), 'Future dates cannot be picked');
  if (os === 'ios') button(tree, 'Cancel').props.onPress(); else datePicker.props.onChange({ type: 'dismissed' });

  // Category search and mixed farming.
  const categoryPicker = picker('Select production category');
  sameJson(titles(categoryPicker.props.getSections('')), ['Crops', 'Livestock', 'Poultry', 'Aquaculture / Fishpond']);
  sameJson(titles(categoryPicker.props.getSections('aqua')), ['Aquaculture / Fishpond'], 'Category search');
  assert.equal(categoryPicker.props.getSections('')[0].data[0].badge, 'Registered activity of this farm area');
  // Commodity choices depend on the category; search respects it.
  labelled(model.render(), 'Commodity').props.onPress();
  let commodityPicker = picker('Crops commodities');
  assert.equal(commodityPicker.props.visible, true, 'Commodity search opens');
  assert.equal(commodityPicker.props.emptyText, 'No matching commodity found.');
  sameJson(titles(commodityPicker.props.getSections('banana')), ['Banana (Saba)', 'Banana (Lakatan)', 'Banana (Latundan)', 'Cavendish Banana']);
  sameJson(commodityPicker.props.getSections('banana').map((section) => section.title), ['Fruits'], 'Results grouped by commodity group');
  sameJson(titles(commodityPicker.props.getSections('egg')), ['Eggplant (Talong)'], 'Crops search never shows poultry eggs');
  commodityPicker.props.onSelect(String(byName('Palay (Rice)').id));
  tree = model.render();
  assert(labelled(tree, 'Commodity: Palay (Rice)'));
  const unitOptions = () => walk(model.render()).filter((n) => n.props?.accessibilityRole === 'radio').map((n) => n.props.accessibilityLabel);
  sameJson(unitOptions(), ['Kilograms', 'Metric tons'], 'Palay: kg or MT');
  assert(walk(model.render()).find((n) => n.props?.accessibilityRole === 'radio' && n.props.accessibilityLabel === 'Kilograms').props.accessibilityState.checked, 'Recommended unit selected automatically');

  // Changing the category clears the commodity and unit.
  picker('Select production category').props.onSelect('Poultry');
  tree = model.render();
  assert(labelled(tree, 'Production category: Poultry') && labelled(tree, 'Commodity: not selected'), 'New category clears the commodity');
  sameJson(unitOptions(), [], 'No units before a commodity');
  commodityPicker = picker('Poultry commodities');
  sameJson(titles(commodityPicker.props.getSections('egg')), ['Chicken Eggs', 'Duck Eggs', 'Quail Eggs'], 'Poultry + egg');
  commodityPicker.props.onSelect(String(byName('Chicken Eggs').id));
  sameJson(unitOptions(), ['Pieces', 'Trays'], 'Eggs: pieces or trays (never kg)');
  const quantity = () => labelled(model.render(), 'Quantity produced');
  quantity().props.onChangeText('12.5');
  assert.equal(quantity().props.value, '125', 'Pieces are whole numbers');
  assert.equal(quantity().props.keyboardType, 'number-pad');
  quantity().props.onChangeText('');
  button(model.render(), 'Review Submission').props.onPress();
  assert(text(model.render()).includes('Enter the quantity produced.'), 'Quantity is required');
  quantity().props.onChangeText('1200');
  labelled(model.render(), 'Remarks or notes').props.onChangeText('  Note  ');
  button(model.render(), 'Review Submission').props.onPress();
  tree = model.render();
  assert(text(tree).includes('Review Submission') && button(tree, 'Confirm & Submit'), 'Review step shows Confirm & Submit');
  const review = (label) => walk(tree).find((n) => n.props?.label === label)?.props.value;
  assert.equal(review('Farm area'), 'Main Farm · Controlled barangay');
  assert.equal(review('Category'), 'Poultry');
  assert.equal(review('Commodity'), 'Chicken Eggs (Poultry Products)');
  assert.equal(review('Quantity'), '1,200 pcs');
  assert.equal(review('Unit'), 'Pieces');
  assert(text(tree).includes('separately from the kilogram total'), 'Counts are shown apart from kg');

  // Double tap while saving sends one request.
  const confirm = button(tree, 'Confirm & Submit');
  confirm.props.onPress(); confirm.props.onPress(); await flush();
  assert.equal(submissions.length, 1, 'Repeated taps while saving send one submission');
  const payload = submissions[0];
  sameJson({ ...payload, clientSubmissionId: undefined }, { farmId: 7, category: 'Poultry', commodityId: byName('Chicken Eggs').id, harvestDate: P.manilaToday(), quantity: '1200', unitCode: 'piece', remarks: 'Note' });
  assert.match(payload.clientSubmissionId, /^production_[A-Za-z0-9_]{16,}$/);
  assert.equal(payload.confirmDuplicate, undefined, 'No duplicate confirmation unless asked');

  // A matching record needs explicit confirmation; the same form identifier is reused.
  responses.shift().reject(new ApiError('A matching production record already exists.', 409, { duplicate: 'You already recorded 1,200 pcs of Chicken Eggs.' }));
  await flush();
  tree = model.render();
  assert(text(tree).includes('Possible duplicate') && !button(tree, 'Confirm & Submit'));
  button(tree, 'Yes, this is a separate harvest').props.onPress(); await flush();
  assert(submissions.length === 2 && submissions[1].confirmDuplicate === true && submissions[1].clientSubmissionId === payload.clientSubmissionId);
  responses.shift().resolve({ record: savedRecord, alreadySaved: false });
  await flush();
  tree = model.render();
  assert(text(tree).includes('Production record saved') && text(tree).includes('1,200 pcs of Chicken Eggs'), 'Clear confirmation');
  button(tree, 'View Production Report').props.onPress();
  assert.equal(nav.calls.back, 1, 'Returns to the Production Report, which reloads on focus');

  // Add another: fresh form; a server field error returns to the form with that message.
  button(model.render(), 'Add another record').props.onPress();
  tree = model.render();
  assert(button(tree, 'Review Submission') && labelled(tree, 'Commodity: not selected') && labelled(tree, 'Production category: Crops'), 'A fresh form with the farm suggestion again');
  picker('Crops commodities').props.onSelect(String(byName('White Corn').id));
  quantity().props.onChangeText('80');
  button(model.render(), 'Review Submission').props.onPress();
  button(model.render(), 'Confirm & Submit').props.onPress(); await flush();
  assert.notEqual(submissions[2].clientSubmissionId, payload.clientSubmissionId, 'A new form gets a new identifier');
  responses.shift().reject(new ApiError('Please correct the highlighted production fields.', 422, { commodityId: 'The selected commodity does not belong to the Crops category.' }));
  await flush();
  tree = model.render();
  assert(button(tree, 'Review Submission') && text(tree).includes('does not belong to the Crops category'), 'Server validation is shown on the form');
  console.log(`PASS: ${os} ${width}px form — exact field order, farm first, suggested category, category/commodity search, category change clears commodity, units per commodity (eggs: pieces/trays), review, submit lock, duplicate confirmation, success, server errors`);
}

async function farmChanges() {
  const twoFarms = [farms[0], { id: 9, name: 'Fishpond Area', status: 'Active', agriculturalCategory: 'Fishpond', barangayName: 'Coastal barangay', address: '', mainProduct: 'bangus' }];
  const { model, picker, titles } = formModel('android', 320, twoFarms, async () => { throw new Error('must not submit'); });
  model.render(); await flush();
  let tree = model.render();
  assert(labelled(tree, 'Farm area: not selected'), 'Several farm areas: the farmer chooses');
  assert.equal(labelled(tree, 'Production category').props.disabled, true, 'Category disabled until a farm area is selected');
  assert.equal(labelled(tree, 'Commodity').props.disabled, true, 'Commodity disabled until a category is selected');
  const farmPicker = picker('Select your farm area');
  assert.equal(farmPicker.props.searchable, true);
  sameJson(titles(farmPicker.props.getSections('fish')), ['Fishpond Area'], 'Farm area search by name');
  sameJson(titles(farmPicker.props.getSections('coastal')), ['Fishpond Area'], 'Farm area search by barangay');
  farmPicker.props.onSelect('7');
  picker('Crops commodities').props.onSelect(String(byName('Palay (Rice)').id));
  // The suggested category follows another farm area, and its commodity is cleared.
  picker('Select your farm area').props.onSelect('9');
  tree = model.render();
  assert(labelled(tree, 'Production category: Aquaculture / Fishpond') && labelled(tree, 'Commodity: not selected'), 'Suggested category follows the farm area');
  // A category the farmer chose stays when the farm area changes (mixed farming), with its commodity.
  picker('Select production category').props.onSelect('Poultry');
  picker('Poultry commodities').props.onSelect(String(byName('Duck Eggs').id));
  picker('Select your farm area').props.onSelect('7');
  tree = model.render();
  assert(labelled(tree, 'Farm area: Main Farm') && labelled(tree, 'Production category: Poultry') && labelled(tree, 'Commodity: Duck Eggs'), 'Chosen category and commodity kept');
  console.log('PASS: several farm areas — searchable, category/commodity disabled until chosen, suggested category follows the farm area, a chosen category stays (mixed farming)');
}

async function formStates() {
  const nav = routerMock();
  const noFarm = harness('app/production/new.tsx', {
    'expo-router': nav.module, '@react-native-community/datetimepicker': { __esModule: true, default: 'DateTimePicker' }, '@/components/production-ui': productionUiMock,
    '@/components/searchable-picker': { __esModule: true, default: 'SearchablePicker' }, '@/utils/production': P,
    '@/services/farmerDataApi': { FarmerDataApiError: ApiError, getProductionCatalog: async () => commodityCatalog, listFarmerFarms: async () => ({ farms: [farms[1]], barangays: [] }), submitProductionRecord: async () => { throw new Error('must not submit'); } },
  });
  noFarm.render(); await flush();
  const tree = noFarm.render();
  assert(walk(tree).some((n) => n.type === 'EmptyState' && n.props.title === 'No active farm area'));
  button(tree, 'Go to My Farms').props.onPress();
  sameJson(nav.calls.push, ['/my-farms']);
  const failing = harness('app/production/new.tsx', {
    'expo-router': routerMock().module, '@react-native-community/datetimepicker': { __esModule: true, default: 'DateTimePicker' }, '@/components/production-ui': productionUiMock,
    '@/components/searchable-picker': { __esModule: true, default: 'SearchablePicker' }, '@/utils/production': P,
    '@/services/farmerDataApi': { FarmerDataApiError: ApiError, getProductionCatalog: async () => { throw new ApiError('Unable to reach the AgriReach server.', undefined); }, listFarmerFarms: async () => ({ farms, barangays: [] }) },
  });
  failing.render(); await flush();
  const failed = walk(failing.render()).find((n) => n.type === 'EmptyState');
  assert(failed.props.title === 'Form unavailable' && failed.props.message && failed.props.retry, 'Load failure offers a retry');
  console.log('PASS: no active farm area guides to My Farms; catalog load failure shows a message and a retry');
}

function picker() {
  const selected = [];
  let closed = 0;
  const sections = [{ title: 'Fruits', data: [{ key: '1', title: 'Banana (Saba)' }, { key: '2', title: 'Mango (Carabao)' }] }, { title: 'Vegetables', data: [{ key: '3', title: 'Okra' }] }];
  const model = harness('components/searchable-picker.tsx', { '@/utils/production': P });
  const props = { visible: true, title: 'Crops commodities', searchPlaceholder: 'Search', emptyText: 'No matching commodity found.', selectedKey: '2',
    getSections: (query) => sections.map((section) => ({ ...section, data: section.data.filter((item) => P.matchesSearch(item.title, query)) })),
    onSelect: (key) => selected.push(key), onClose: () => { closed += 1; } };
  let tree = model.render(props);
  const list = () => walk(model.render(props)).find((n) => n.type === 'SectionList');
  assert.equal(list().props.sections.length, 2);
  assert.equal(list().props.keyboardShouldPersistTaps, 'handled', 'Rows stay tappable while the keyboard is open');
  walk(tree).find((n) => n.type === 'TextInput').props.onChangeText('BAN');
  tree = model.render(props);
  sameJson(list().props.sections.map((section) => section.data.map((item) => item.title)), [['Banana (Saba)']], 'Typing filters, empty groups disappear');
  assert.equal(text(walk(tree).find((n) => n.type === 'Text' && /match/.test(text(n)))), '1 match');
  const row = list().props.renderItem({ item: { key: '1', title: 'Banana (Saba)' } });
  row.props.onPress();
  sameJson(selected, ['1']);
  walk(model.render(props)).find((n) => n.type === 'TextInput').props.onChangeText('durian');
  assert.equal(list().props.sections.length, 0);
  assert(text(list().props.ListEmptyComponent).includes('No matching commodity found.'), 'Clear no-match message');
  walk(model.render(props)).find((n) => n.props?.accessibilityLabel === 'Clear search').props.onPress();
  assert.equal(list().props.sections.length, 2, 'Clearing the search shows every item again');
  button(model.render(props), 'Cancel').props.onPress();
  assert.equal(closed, 1);
  const noSearch = harness('components/searchable-picker.tsx', { '@/utils/production': P });
  assert(!walk(noSearch.render({ ...props, searchable: false })).some((n) => n.type === 'TextInput'), 'Search bar can be hidden');
  console.log('PASS: searchable picker — search bar, live filtering, match count, empty groups hidden, "No matching commodity found", clear, select, cancel');
}

const month = (key, label, totalKg, crops, recordCount = crops.length, otherMeasures = [], otherTotals = []) => ({ month: key, label, totalKg, recordCount, crops, otherMeasures, otherTotals });
const overview = {
  today: '2026-10-23',
  currentMonth: month('2026-10', 'October 2026', 1250, [
    { commodityId: 3, name: 'Palay (Rice)', category: 'Crops', totalKg: 600, recordCount: 2, percent: 48 },
    { commodityId: 2, name: 'White Corn', category: 'Crops', totalKg: 350, recordCount: 1, percent: 28 },
    { commodityId: 9, name: 'Banana (Saba)', category: 'Crops', totalKg: 300, recordCount: 1, percent: 24 }], 5,
    [{ commodityId: 50, name: 'Chicken Eggs', category: 'Poultry', unitCode: 'piece', unitSymbol: 'pcs', total: 1200, recordCount: 1 }],
    [{ unitCode: 'piece', unitName: 'Pieces', unitSymbol: 'pcs', total: 1200, recordCount: 1 }]),
  previousMonth: month('2026-09', 'September 2026', 100, [{ commodityId: 2, name: 'White Corn', category: 'Crops', totalKg: 100, recordCount: 2, percent: 100 }], 2),
  recent: [savedRecord, { ...savedRecord, id: '40', commodityName: 'White Corn', quantity: 350, unitCode: 'kg', unitSymbol: 'kg', quantityKg: 350 }],
  years: [2026],
};

async function mainScreen() {
  const nav = routerMock();
  const model = harness('app/production/index.tsx', { 'expo-router': nav.module, '@/components/production-ui': productionUiMock, '@/utils/production': P,
    '@/services/farmerDataApi': { FarmerDataApiError: ApiError, getProductionOverview: async () => overview } });
  model.render(); await flush();
  let tree = model.render();
  assert(text(tree).includes('Production Report'));
  // MonthTotal is a local component (not expanded by the harness): check what the screen passes to it.
  const total = (caption) => walk(model.render()).find((n) => n.props?.caption === caption);
  assert(total('This month').props.summary === overview.currentMonth && total('This month').props.active, 'Current month total selected by default');
  assert(total('Previous month').props.summary === overview.previousMonth && !total('Previous month').props.active, 'Previous completed month shown');
  assert.equal(walk(tree).find((n) => n.type === 'CropBreakdown').props.crops, overview.currentMonth.crops, 'kg breakdown');
  assert.equal(walk(tree).find((n) => n.type === 'OtherMeasures').props.measures, overview.currentMonth.otherMeasures, 'Other units listed separately');
  total('Previous month').props.onPress();
  tree = model.render();
  assert.equal(walk(tree).find((n) => n.type === 'CropBreakdown').props.monthLabel, 'September 2026', 'Selecting the previous month switches the breakdown');
  assert.equal(walk(tree).filter((n) => n.type === 'ProductionRecordCard').length, 2, 'Recent records listed');
  button(tree, 'Add Production Record').props.onPress();
  button(tree, 'View Production History').props.onPress();
  button(tree, 'View September 2026 details').props.onPress();
  sameJson(nav.calls.push, ['/production/new', '/production/history', { pathname: '/production/month/[month]', params: { month: '2026-09' } }]);
  const failing = harness('app/production/index.tsx', { 'expo-router': routerMock().module, '@/components/production-ui': productionUiMock, '@/utils/production': P,
    '@/services/farmerDataApi': { FarmerDataApiError: ApiError, getProductionOverview: async () => { throw new ApiError('The request timed out.', undefined); } } });
  failing.render(); await flush();
  const failed = walk(failing.render()).find((n) => n.type === 'EmptyState');
  assert(failed.props.title === 'Production unavailable' && failed.props.message && failed.props.retry, 'Load failure offers a retry');
  console.log('PASS: Production Report — kg totals, other units apart, month switch, recent records, navigation, error state');
}

async function reportsScreen() {
  const nav = routerMock();
  const model = harness('app/reports/index.tsx', { 'expo-router': nav.module, '@/components/production-ui': productionUiMock, '@/utils/production': P,
    '@/services/farmerDataApi': { FarmerDataApiError: ApiError, getProductionOverview: async () => overview, listDamageReports: async () => [{ status: 'Pending' }, { status: 'Resolved' }, { status: 'Under Review' }] } });
  model.render(); await flush();
  const option = (tree, title) => walk(tree).find((n) => n.props?.title === title && n.props.tone);
  const tree = model.render();
  assert.equal(option(tree, 'Production Report').props.detail, 'October 2026: 1,250 kg');
  assert.equal(option(tree, 'Damage Report').props.detail, '2 awaiting review');
  option(tree, 'Production Report').props.onPress(); option(tree, 'Damage Report').props.onPress();
  sameJson(nav.calls.push, ['/production', '/my-reports'], 'Damage Report still opens the existing damage reports');
  console.log('PASS: Reports screen offers Production Report and the existing Damage Report');
}

function components() {
  const nav = routerMock();
  const ui = loadModule('components/production-ui.tsx', {
    'expo-router': nav.module, 'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { Pressable: 'Pressable', StyleSheet: { create: (v) => v }, Text: 'Text', View: 'View' },
    '@/components/farm-ui': { Badge: 'Badge', categoryIcon: () => 'leaf', Icon: 'Icon', palette: {}, ui: {} },
    '@/utils/production': P,
  });
  const rows = walk(ui.CropBreakdown({ crops: overview.currentMonth.crops, monthLabel: 'October 2026' })).filter((n) => n.props?.accessible);
  sameJson(rows.map((n) => n.props.accessibilityLabel), ['Palay (Rice), 600 kg, 48% of October 2026', 'White Corn, 350 kg, 28% of October 2026', 'Banana (Saba), 300 kg, 24% of October 2026']);
  assert.equal(text(ui.CropBreakdown({ crops: [], monthLabel: 'June 2005' })), 'No production in kilograms recorded for June 2005.');
  const other = ui.OtherMeasures({ measures: overview.currentMonth.otherMeasures });
  assert(text(other).includes('not added to the kg total') && text(other).includes('1,200 pcs'));
  assert.equal(ui.OtherMeasures({ measures: [] }), null);
  const card = ui.ProductionRecordCard({ record: { ...savedRecord, status: 'Rejected', counted: false } });
  assert(text(card).includes('1,200 pcs') && text(card).includes('Not counted in your totals.'), 'Record card shows its own unit');
  card.props.onPress();
  sameJson(nav.calls.push, [{ pathname: '/production/record/[id]', params: { id: '41' } }]);
  sameJson(['Submitted', 'Verified', 'Rejected'].map((status) => ui.ProductionStatusBadge({ status }).props.tone), ['blue', 'green', 'red']);
  console.log('PASS: crop breakdown, other-unit list, record card in its own unit, status badges');
}

function tabs() {
  let pathname = '/my-reports';
  const nav = routerMock();
  const farmUi = loadModule('components/farm-ui.tsx', {
    'expo-router': { ...nav.module, usePathname: () => pathname },
    'expo-symbols': { SymbolView: 'SymbolView' }, 'expo-image': { Image: 'Image' }, 'expo-status-bar': { StatusBar: 'StatusBar' },
    react: { useEffect: () => {}, useRef: (value) => ({ current: value }), useState: (value) => [value, () => {}] },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { AccessibilityInfo: {}, ActivityIndicator: 'ActivityIndicator', Animated: { Value: class {}, View: 'AnimatedView', timing: () => ({ start() {}, stop() {} }) }, Pressable: 'Pressable', StyleSheet: { create: (v) => v, absoluteFill: {} }, Text: 'Text', View: 'View' },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView', useSafeAreaInsets: () => ({ bottom: 0 }) },
  });
  const press = (active, label) => walk(farmUi.BottomTabs({ active })).find((n) => n.props?.accessibilityLabel === label).props.onPress();
  press('reports', 'Reports');
  pathname = '/production';
  press('reports', 'Reports');
  pathname = '/reports';
  press('reports', 'Reports');
  pathname = '/farmer-home';
  press('home', 'Home');
  press('home', 'Reports');
  sameJson(nav.calls.replace, ['/reports', '/reports', '/reports'], 'Reports tab returns to Reports from Damage/Production; tapping the current tab root stays put');
  console.log('PASS: Reports tab opens the Reports screen (also from Damage Reports and Production Report); other tabs unchanged');
}

(async () => {
  rules();
  catalog();
  for (const os of ['android', 'ios']) for (const width of [320, 390]) await form(os, width);
  await farmChanges();
  await formStates();
  picker();
  await mainScreen();
  await reportsScreen();
  components();
  tabs();
})().catch((error) => { console.error(error); process.exitCode = 1; });
