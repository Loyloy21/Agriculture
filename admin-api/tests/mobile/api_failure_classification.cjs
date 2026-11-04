// Verifies the mobile failure classifier and the weather-advice shape guard (pure functions, no network).
// node tests/api_failure_classification.cjs
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require(path.resolve(__dirname, '../../frontend/mobile/node_modules/typescript'));
const source = fs.readFileSync(path.resolve(__dirname, '../../frontend/mobile/src/services/apiFailure.ts'), 'utf8');
const mod = { exports: {} };
vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText, { exports: mod.exports });
const { classifyHttpFailure, weatherAdviceShapeProblem, API_FAILURE_TITLES } = mod.exports;
let passed = 0;
const eq = (actual, expected, label) => { if (actual !== expected) { console.error(`FAIL: ${label}: expected ${expected}, got ${actual}`); process.exit(1); } passed++; console.log('PASS: ' + label); };

const gatewayNotFound = classifyHttpFailure({ status: 404, bodyText: '{"success":false,"message":"Not found."}', gateway: 'api-only' });
eq(gatewayNotFound.code, 'route_unavailable', 'gateway 404 "Not found." => route_unavailable (the physical-phone bug)');
eq(classifyHttpFailure({ status: 404, bodyText: '{"success":false,"message":"This farm was not found."}', gateway: 'api-only' }).code, 'rejected', 'an API 404 about a farm is a normal rejection, not a blocked route');
eq(classifyHttpFailure({ status: 404, bodyText: '{"message":"Not found."}', gateway: null }).code, 'rejected', '404 without the gateway header is not blamed on the gateway');
eq(classifyHttpFailure({ status: 530, bodyText: '<html>error code: 1033</html>', gateway: null }).code, 'tunnel_unavailable', 'Cloudflare 530/1033 page => expired tunnel URL');
eq(classifyHttpFailure({ status: 502, bodyText: '', gateway: null }).code, 'tunnel_unavailable', '502 with empty body => tunnel/proxy down');
eq(classifyHttpFailure({ status: 200, bodyText: '<html>captive portal</html>', gateway: null }).code, 'bad_response', 'HTML with HTTP 200 => bad_response');
eq(classifyHttpFailure({ status: 401, bodyText: '{"message":"Your session is invalid or has expired."}', gateway: 'api-only' }).code, 'auth', '401 => auth');
eq(classifyHttpFailure({ status: 500, bodyText: '{"success":false,"message":"The authentication service is temporarily unavailable."}', gateway: 'api-only' }).code, 'server', '500 JSON => server');
eq(classifyHttpFailure({ status: 422, bodyText: '{"message":"Select a valid farm."}', gateway: 'api-only' }).code, 'rejected', '422 => rejected');
for (const code of ['network', 'timeout', 'tunnel_unavailable', 'route_unavailable', 'auth', 'server', 'bad_response', 'rejected', 'config']) eq(typeof API_FAILURE_TITLES[code], 'string', `a user-facing title exists for ${code}`);

const reading = { observedAt: '2026-10-12T18:00:00+08:00', units: { temperature: '°C' } };
const advice = { assessment: 'no_threshold_exceeded', headline: 'No listed threshold exceeded', matched: [], recommendations: [], notEvaluated: [], limitations: [] };
eq(weatherAdviceShapeProblem({ status: 'current', weather: reading, advice }), null, 'a complete current response is accepted');
eq(weatherAdviceShapeProblem({ status: 'stale', weather: reading, advice }), null, 'a complete stale response is accepted');
eq(weatherAdviceShapeProblem({ status: 'unavailable', weather: null, advice: null, reasonCode: 'provider_timeout' }), null, 'an unavailable response with no weather is accepted');
eq(weatherAdviceShapeProblem({ status: 'current', weather: null, advice }) !== null, true, 'current without a weather reading is flagged as unreadable');
eq(weatherAdviceShapeProblem({ status: 'current', weather: reading, advice: { matched: [] } }) !== null, true, 'current with incomplete advice lists is flagged');
eq(weatherAdviceShapeProblem({ status: 'sunny' }) !== null, true, 'an unknown status is flagged');
eq(weatherAdviceShapeProblem({ status: 'current', weather: reading, advice: { matched: [], recommendations: [], notEvaluated: [], normal: true } }) !== null, true, 'an older server response that only says "normal" is refused instead of shown as an all-clear');
for (const assessment of ['thresholds_exceeded', 'no_threshold_exceeded', 'limited', 'unclear']) eq(weatherAdviceShapeProblem({ status: 'current', weather: reading, advice: { ...advice, assessment } }), null, `assessment "${assessment}" is accepted`);
eq(weatherAdviceShapeProblem({ status: 'current', weather: reading, advice: { ...advice, assessment: 'normal' } }) !== null, true, 'an unknown assessment value is flagged');
eq(weatherAdviceShapeProblem(null) !== null, true, 'a null body is flagged');
console.log(`\nALL ${passed} CHECKS PASSED`);
