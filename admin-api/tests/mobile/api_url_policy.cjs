// API address policy of the farmer app (src/config/api.ts): development builds may use local/tunnel addresses;
// release builds (__DEV__ false) only public HTTPS. Pure check; no network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const source = fs.readFileSync(path.join(__dirname, '../../frontend/mobile/src/config/api.ts'), 'utf8');
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
function load(apiUrl, dev) {
  const exports = {};
  vm.runInNewContext(js, { exports, URL, __DEV__: dev, process: { env: { EXPO_PUBLIC_API_URL: apiUrl } } });
  return exports;
}
const attempt = (api, route) => { try { return api.getApiUrl(route); } catch (error) { return error.message; } };

const local = ['http://localhost/api', 'http://127.0.0.1:8197/api', 'http://192.168.1.55/CAPSTONE%20AGRICULTURE/api', 'https://10.0.0.5/api', 'https://172.20.1.1/api', 'https://agrireach-pc.local/api', 'https://agrireach/api', 'https://reflect-sunrise.trycloudflare.com/CAPSTONE%20AGRICULTURE/api'];
for (const url of local) {
  assert.equal(attempt(load(url, false), 'auth/me.php'), 'API_NOT_CONFIGURED', 'release build refuses ' + url);
  assert.equal(attempt(load(url, true), 'auth/me.php'), url.replace(/\/+$/, '') + '/auth/me.php', 'development build allows ' + url);
}
assert.equal(attempt(load('http://agrireach.example.org/api', false), 'auth/me.php'), 'API_NOT_CONFIGURED', 'release build refuses plain HTTP on a public host');
assert.equal(attempt(load('https://agrireach.example.org/agri/api/', false), '/auth/me.php'), 'https://agrireach.example.org/agri/api/auth/me.php', 'release build accepts public HTTPS with a deployment path');
assert.equal(attempt(load('', false), 'auth/me.php'), 'API_NOT_CONFIGURED', 'missing address');
assert.equal(attempt(load('ftp://agrireach.example.org/api', true), 'x'), 'API_INVALID_URL', 'non-http scheme');
assert.equal(attempt(load('not a url', true), 'x'), 'API_INVALID_URL', 'invalid URL');
console.log(`PASS: API address policy (${local.length * 2 + 5} cases): release builds only call public HTTPS; development keeps local and tunnel addresses.`);
