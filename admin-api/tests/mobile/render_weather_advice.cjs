// Offline layout capture of the shipping WeatherAdvicePanel / WeatherAdviceSummary JSX through
// React Native Web and headless Chrome at 393 px wide. NOT a physical-phone screenshot: native fonts,
// safe areas and device behaviour are not simulated. Data comes from JSON files you pass in
// (real API responses captured from /weather/advice.php, or clearly labelled controlled variants).
//
// usage: node tests/mobile/render_weather_advice.cjs <name>=<response.json> [<name>=<response.json> ...]
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const modules = path.resolve(__dirname, '../../frontend/mobile/node_modules');
const React = require(path.join(modules, 'react'));
const jsx = require(path.join(modules, 'react/jsx-runtime'));
const RN = require(path.join(modules, 'react-native-web'));
const DOM = require(path.join(modules, 'react-dom/server'));
const ts = require(path.join(modules, 'typescript'));
const root = path.resolve(__dirname, '../../frontend/mobile/src');
const out = path.resolve(__dirname, '../.artifacts/weather-advice');
const fontFile = 'file:///' + path.resolve(__dirname, '../../frontend/mobile/node_modules/@expo-google-fonts/material-symbols/400Regular/MaterialSymbols_400Regular.ttf').replaceAll('\\', '/').replaceAll(' ', '%20');
const assetUrl = file => 'file:///' + path.resolve(__dirname, '../../frontend/mobile', file).replaceAll('\\', '/').replaceAll(' ', '%20');
const routing = { router: { push() {}, replace() {}, back() {} } };
const compile = file => ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2020 } }).outputText;
const ImageStub = ({ source, style }) => React.createElement('img', { src: typeof source === 'string' ? source : source?.uri, alt: '', style: { ...RN.StyleSheet.flatten(style), objectFit: 'cover' } });

const shared = {};
vm.runInNewContext(compile('components/farm-ui.tsx'), { exports: shared, setTimeout, clearTimeout, require: name => {
  if (name.startsWith('@/assets/')) return assetUrl(name.slice(2));
  return ({ react: React, 'react/jsx-runtime': jsx, 'react-native': RN, 'expo-router': routing, 'expo-image': { Image: ImageStub }, 'expo-status-bar': { StatusBar: () => null },
    'react-native-safe-area-context': { SafeAreaView: RN.View, useSafeAreaInsets: () => ({ bottom: 24 }) },
    'expo-symbols': { SymbolView: ({ name, size, tintColor }) => React.createElement('span', { style: { fontFamily: 'Material', fontSize: size, color: tintColor, lineHeight: 1, width: size, height: size, display: 'inline-block', flexShrink: 0 }, 'aria-hidden': true }, name.web) },
  })[name];
} });
const panel = {};
vm.runInNewContext(compile('components/weather-advice-panel.tsx'), { exports: panel, setTimeout, clearTimeout, require: name => ({
  react: React, 'react/jsx-runtime': jsx, 'react-native': RN, 'expo-router': routing, '@/components/farm-ui': shared,
  '@/services/farmerDataApi': { FarmerDataApiError: class extends Error {}, getWeatherAdvice: async () => { throw new Error('not used in layout capture'); } },
  '@/state/farmerAuthContext': { useFarmerAuth: () => ({ refreshSession() {} }) },
})[name] });

function capture(name, tree, height) {
  fs.mkdirSync(out, { recursive: true });
  const html = DOM.renderToString(React.createElement(RN.View, { style: { width: 393, padding: 16, gap: 12, backgroundColor: '#F7F5EE' } }, tree));
  const sheet = RN.StyleSheet.getSheet();
  const page = path.join(out, name + '.html');
  fs.writeFileSync(page, `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${sheet.textContent}</style><style>@font-face{font-family:Material;src:url('${fontFile}')}html,body{margin:0;background:#F7F5EE}*{box-sizing:border-box}</style></head><body>${html}</body></html>`);
  const profile = fs.mkdtempSync(path.join(out, 'chrome-render-'));
  const png = path.join(out, name + '.png');
  const result = spawnSync('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', `--user-data-dir=${profile}`, '--allow-file-access-from-files', '--force-device-scale-factor=2', `--window-size=393,${height}`, '--virtual-time-budget=2500', `--screenshot=${png}`, 'file:///' + page.replaceAll('\\', '/').replaceAll(' ', '%20')], { windowsHide: true, timeout: 40000, stdio: 'pipe' });
  fs.rmSync(profile, { recursive: true, force: true });
  if (result.status !== 0 || !fs.existsSync(png)) throw new Error('Screenshot failed for ' + name + ': ' + String(result.stderr).slice(-400));
  console.log('Wrote ' + png);
}

const inputs = process.argv.slice(2).map(arg => arg.split('='));
if (!inputs.length) { console.error('usage: node tests/mobile/render_weather_advice.cjs <name>=<response.json> ...'); process.exit(2); }
for (const [name, file] of inputs) {
  const response = JSON.parse(fs.readFileSync(file, 'utf8'));
  const data = response.data;
  const tree = React.createElement(React.Fragment, null,
    React.createElement(RN.Text, { style: shared.ui.cardTitle }, 'Weather-based advice'),
    React.createElement(panel.WeatherAdvicePanel, { data, loading: false, error: '', onRetry() {} }),
    React.createElement(RN.Text, { style: shared.ui.cardTitle }, 'Home screen summary card'),
    React.createElement(panel.WeatherAdviceSummary, { data, loading: false, error: '', onRetry() {} }));
  capture(name, tree, data.status === 'unavailable' ? 900 : 1900);
}
