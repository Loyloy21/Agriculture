// Offline capture of the shipping farmer screens (Weather Advice, Home, Notifications, advisory detail) through
// React Native Web and headless Chrome at 393 px wide, fed with REAL API responses saved by
// tests/mobile/capture_advisory_summary.php. Also asserts what the farmer sees on each screen.
// NOT a physical-phone screenshot: native fonts, safe areas and device behaviour are not simulated.
//
// usage: php tests/mobile/capture_advisory_summary.php   (local test database), then
//        node tests/mobile/render_advisory_summary.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawn } = require('node:child_process');
const modules = path.resolve(__dirname, '../../frontend/mobile/node_modules');
const React = require(path.join(modules, 'react'));
const jsx = require(path.join(modules, 'react/jsx-runtime'));
const RN = require(path.join(modules, 'react-native-web'));
const DOM = require(path.join(modules, 'react-dom/server'));
const ts = require(path.join(modules, 'typescript'));
const root = path.resolve(__dirname, '../../frontend/mobile/src');
const out = path.resolve(__dirname, '../.artifacts/advisory-summary');
const fileUrl = file => 'file:///' + file.replaceAll('\\', '/').replaceAll(' ', '%20');
const fontFile = fileUrl(path.join(modules, '@expo-google-fonts/material-symbols/400Regular/MaterialSymbols_400Regular.ttf'));
const assetUrl = file => fileUrl(path.resolve(__dirname, '../../frontend/mobile', file));
const captured = name => JSON.parse(fs.readFileSync(path.join(out, name + '.json'), 'utf8')).data;
const compile = file => ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2020 } }).outputText;
const flush = async () => { for (let i = 0; i < 50; i++) await Promise.resolve(); };
const ImageStub = ({ source, style }) => React.createElement('img', { src: typeof source === 'string' ? source : source?.uri, alt: '', style: { ...RN.StyleSheet.flatten(style), objectFit: 'cover' } });

let searchParams = {};
const routing = { router: { push() {}, replace() {}, back() {} }, usePathname: () => '/weather-advice', useLocalSearchParams: () => searchParams };
const shared = {};
vm.runInNewContext(compile('components/farm-ui.tsx'), { exports: shared, setTimeout, clearTimeout, require: name => {
  if (name.startsWith('@/assets/')) return assetUrl(name.slice(2));
  return ({ react: React, 'react/jsx-runtime': jsx, 'react-native': RN, 'expo-router': routing, 'expo-image': { Image: ImageStub }, 'expo-status-bar': { StatusBar: () => null },
    'react-native-safe-area-context': { SafeAreaView: RN.View, useSafeAreaInsets: () => ({ bottom: 24 }) },
    'expo-symbols': { SymbolView: ({ name, size, tintColor }) => React.createElement('span', { style: { fontFamily: 'Material', fontSize: size, color: tintColor, lineHeight: 1, width: size, height: size, display: 'inline-block', flexShrink: 0 }, 'aria-hidden': true }, name.web) },
  })[name];
} });

// Screen hooks run on a small store so the async loaders can settle before the single server render.
function hookStore() {
  const state = [], effects = []; let cursor = 0;
  const useState = value => { const id = cursor++; if (!(id in state)) state[id] = typeof value === 'function' ? value() : value; return [state[id], next => { state[id] = typeof next === 'function' ? next(state[id]) : next; }]; };
  const memo = (fn, deps) => { const id = cursor++; if (!state[id] || deps.some((v, i) => v !== state[id].deps[i])) state[id] = { deps, value: fn() }; return state[id].value; };
  const useEffect = (fn, deps = []) => { const id = cursor++; if (!state[id] || deps.some((v, i) => v !== state[id][i])) { state[id] = deps; effects.push(fn); } };
  return { react: { useState, useEffect, useRef: value => useState({ current: value })[0], useCallback: (fn, deps) => memo(() => fn, deps), useMemo: memo }, useFocusEffect: fn => useEffect(fn, [fn]), reset() { cursor = 0; }, run() { const pending = effects.splice(0); pending.forEach(fn => fn()); return pending.length; } };
}

function api(adviceName) {
  const advisories = captured('advisories');
  return {
    FarmerDataApiError: class extends Error {},
    getWeatherAdvice: async () => captured(adviceName),
    listWeatherAdvisories: async () => advisories,
    listFarmerFarms: async () => ({ farms: [{ id: captured(adviceName).farm?.id ?? 1, name: 'Tolosa rice field', agriculturalCategory: 'Crops', status: 'Active', isPrimary: true }] }),
    getCurrentWeather: async () => captured('current'),
    listFarmerNotifications: async () => captured('notifications'),
    markFarmerNotificationRead: async () => {},
    // Same mapping as services/farmerDataApi.ts getWeatherAdvisory().
    getWeatherAdvisory: async id => { const advisory = captured('advisory-' + id); const lines = advisory.message.split(/\r?\n/).map(item => item.replace(/^[-\u2022\s]+/, '').trim()).filter(Boolean); return { ...advisory, recommendations: lines.length ? lines : [advisory.message] }; },
  };
}

function loadScreen(file, adviceName) {
  const hooks = hookStore();
  const auth = { useFarmerAuth: () => ({ farmer: { id: 7, firstName: 'Juan', fullName: 'Juan Dela Cruz' }, refreshSession: async () => {} }) };
  const router = { ...routing, useFocusEffect: hooks.useFocusEffect };
  const services = api(adviceName);
  // useWindowDimensions is a real React hook; the settle passes call the screen outside React, so it is fixed at 393 px.
  const native = { ...RN, useWindowDimensions: () => ({ width: 393, height: 800, scale: 2, fontScale: 1 }) };
  const common = { react: hooks.react, 'react/jsx-runtime': jsx, 'react-native': native, 'expo-router': router, 'react-native-safe-area-context': { SafeAreaView: RN.View }, '@/components/farm-ui': shared, '@/services/farmerDataApi': services, '@/state/farmerAuthContext': auth, '@/services/apiFailure': { API_FAILURE_TITLES: {} } };
  const panel = {};
  vm.runInNewContext(compile('components/weather-advice-panel.tsx'), { exports: panel, setTimeout, clearTimeout, require: name => common[name] });
  const screen = {};
  vm.runInNewContext(compile(file), { exports: screen, setTimeout, clearTimeout, Date, require: name => ({ ...common, '@/components/weather-advice-panel': panel })[name] });
  return { Screen: screen.default, hooks };
}

async function settle(file, adviceName, params = {}) {
  searchParams = params;
  const { Screen, hooks } = loadScreen(file, adviceName);
  for (let pass = 0; pass < 6; pass++) { hooks.reset(); Screen({}); if (!hooks.run()) break; await flush(); }
  hooks.reset();
  return React.createElement(Screen);
}

function page(name, tree) {
  fs.mkdirSync(out, { recursive: true });
  const html = DOM.renderToString(React.createElement(RN.View, { style: { width: 393, minHeight: 760, backgroundColor: '#F7F5EE' } }, tree));
  const file = path.join(out, name + '.html');
  fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=393, initial-scale=1"><style>${RN.StyleSheet.getSheet().textContent}</style><style>@font-face{font-family:Material;src:url('${fontFile}')}html,body{margin:0;background:#F7F5EE}*{box-sizing:border-box}</style></head><body>${html}</body></html>`);
  const visible = html.replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ').replace(/&#x27;|&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\s+/g, ' ');
  return { file, text: visible };
}

async function screenshots(pages) {
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  const profile = fs.mkdtempSync(path.join(out, 'chrome-'));
  const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--allow-file-access-from-files', '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
  let socket, sequence = 0; const pending = new Map();
  try {
    const portFile = path.join(profile, 'DevToolsActivePort');
    for (let i = 0; i < 100 && !fs.existsSync(portFile); i++) await sleep(100);
    const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
    const tab = (await (await fetch('http://127.0.0.1:' + port + '/json/list')).json()).find(t => t.type === 'page');
    socket = new WebSocket(tab.webSocketDebuggerUrl); await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    socket.onmessage = event => { const m = JSON.parse(event.data); if (m.id) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(Error(m.error.message)) : p.resolve(m.result); } };
    const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })); });
    const evaluate = async expression => (await send('Runtime.evaluate', { expression, returnByValue: true })).result.value;
    await send('Emulation.setDeviceMetricsOverride', { width: 393, height: 800, deviceScaleFactor: 2, mobile: true });
    for (const [name, file] of pages) {
      await send('Page.navigate', { url: fileUrl(file) });
      for (let i = 0; i < 100 && await evaluate('document.readyState') !== 'complete'; i++) await sleep(100);
      await evaluate('document.fonts.ready.then(() => true)'); await sleep(400);
      const height = await evaluate('Math.ceil(document.documentElement.scrollHeight)');
      const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: 393, height: Math.min(height, 5000), scale: 1 } });
      fs.writeFileSync(path.join(out, name + '.png'), Buffer.from(shot.data, 'base64'));
      console.log('Wrote ' + path.join(out, name + '.png'));
    }
  } finally {
    socket?.close(); chrome.kill(); await sleep(300);
    fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
  }
}

(async () => {
  const advisories = captured('advisories').items;
  const byTitle = Object.fromEntries(advisories.map(item => [item.title, item]));
  const urgentAdmin = byTitle['URGENT: strong wind tonight'], longAdmin = byTitle['Bagyo: heavy rain expected'], shortAdmin = byTitle['Normal weather this week'], legacyAdmin = byTitle['Uwan advisory (older, no summary)'];
  const storm = captured('advice-urgent');
  const pages = [];
  const count = (text, phrase) => text.split(phrase).length - 1;

  // 1. Weather Advice: urgent weather summary + admin advisory cards (full advice collapsed).
  let shot = page('weather-advice-urgent', await settle('app/weather-advice.tsx', 'advice-urgent'));
  pages.push(['weather-advice-urgent', shot.file]);
  for (const point of storm.summary.points) assert(shot.text.includes(point.action), 'urgent action shown word for word: ' + point.action);
  assert(shot.text.includes('Urgent') && shot.text.includes('Also triggered: Rain building up'), 'urgent badge and the extra condition are shown');
  assert(!shot.text.includes('Air temperature (model)') && !shot.text.includes('Rule: '), 'full weather advice is behind "View full advice"');
  for (const item of [urgentAdmin, longAdmin]) assert(shot.text.includes(item.keyAdvice), 'admin Key advice shown on its card: ' + item.title);
  assert(shot.text.includes(shortAdmin.message), 'short admin advisory shows its whole message');
  assert(shot.text.includes(legacyAdmin.preview.text) && !shot.text.includes('Siguraduha nga limpyo'), 'long advisory without Key advice: pointer shown, long message not dumped on the card');
  assert(count(shot.text, 'View full advice') === 5, 'one "View full advice" for the weather advice and one per admin advisory');
  console.log('PASS: Weather Advice shows the urgent summary, Key advice previews and "View full advice" links.');

  // 2. The same screen opened from the home card: full weather advice expanded.
  shot = page('weather-advice-full', await settle('app/weather-advice.tsx', 'advice-urgent', { full: '1' }));
  pages.push(['weather-advice-full', shot.file]);
  assert(shot.text.includes('Hide full advice') && shot.text.includes('Air temperature (model)'), 'full advice expanded');
  for (const recommendation of storm.advice.recommendations) for (const action of recommendation.actions) assert(shot.text.includes(action), 'every full action listed: ' + action);
  console.log('PASS: "View full advice" shows every rule, measurement and action.');

  // 3. Missing weather data.
  shot = page('weather-advice-missing-data', await settle('app/weather-advice.tsx', 'advice-unavailable'));
  pages.push(['weather-advice-missing-data', shot.file]);
  assert(shot.text.includes('No advice is shown because no real weather reading was obtained') && !shot.text.includes('Urgent') && !shot.text.includes('View full weather advice'), 'missing weather data: honest unavailable card, nothing invented');
  shot = page('weather-advice-limited', await settle('app/weather-advice.tsx', 'advice-limited'));
  pages.push(['weather-advice-limited', shot.file]);
  assert(shot.text.includes('Limited check') && shot.text.includes(captured('advice-limited').summary.title) && !shot.text.includes('No listed threshold exceeded'), 'partly missing data: "Limited check" names what could not be checked');
  console.log('PASS: missing and partly missing weather data are stated, not guessed.');

  // 4. Home.
  shot = page('home', await settle('app/farmer-home.tsx', 'advice-urgent'));
  pages.push(['home', shot.file]);
  assert(shot.text.includes(storm.summary.points[0].action) && shot.text.includes('Urgent') && shot.text.includes('Latest advisory') && count(shot.text, 'View full advice') === 2 && shot.text.includes('Latest advisory'), 'home shows the urgent weather summary and the latest admin advisory preview');
  console.log('PASS: Home shows the short weather summary and the latest advisory preview.');

  // 5. Notifications.
  shot = page('notifications', await settle('app/notifications.tsx', 'advice-urgent'));
  pages.push(['notifications', shot.file]);
  for (const item of captured('notifications').filter(n => n.relatedAdvisoryId)) assert(shot.text.includes(item.shortMessage) && shot.text.includes(item.context), 'notification preview and context: ' + item.title);
  assert(!shot.text.includes('Admin-created rule-based advisory. Coverage') && count(shot.text, 'View full advice') === 4, 'notifications show the advice, not the stored prefix');
  console.log('PASS: Notifications show Key advice previews with condition, category and end date.');

  // 6. View full advice (admin advisory detail).
  for (const [name, item] of [['urgent', urgentAdmin], ['legacy', legacyAdmin]]) {
    shot = page('advisory-detail-' + name, await settle('app/weather/[id].tsx', 'advice-urgent', { id: name }));
    pages.push(['advisory-detail-' + name, shot.file]);
    const full = captured('advisory-' + name).message.split(/\r?\n/).map(line => line.replace(/^[-\u2022\s]+/, '').trim()).filter(Boolean);
    for (const line of full) assert(shot.text.includes(line), 'full message line shown: ' + line.slice(0, 40));
    assert(name === 'urgent' ? shot.text.includes('Key advice') && shot.text.includes(item.keyAdvice) : !shot.text.includes('Key advice'), name + ' detail Key advice block');
  }
  console.log('PASS: the detail screen shows the Key advice and the complete original message.');

  await screenshots(pages);
})().catch(error => { console.error('FAIL:', error.message); process.exit(1); });
