// Read-only public Metro/bundle smoke test. No phone, database or app state mutations.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import dns from 'node:dns/promises';

const stateDir = new URL('../../var/cloudflare-tunnel/', import.meta.url);
const api = JSON.parse(fs.readFileSync(new URL('processes.json', stateDir), 'utf8'));
const expo = JSON.parse(fs.readFileSync(new URL('expo-process.json', stateDir), 'utf8'));
assert.equal(api.schema, 2);
assert.equal(expo.schema, 2);
assert.ok(api.verifiedAt && expo.verifiedAt, 'Start the full managed session before testing');
assert.equal(expo.publicApiUrl, api.publicApiUrl);

function get(url, address, headers = {}, timeout = 90000) {
  return new Promise((resolve, reject) => {
    const options = { headers, timeout };
    if (address) {
      options.lookup = (_host, options, callback) => options?.all
        ? callback(null, [{ address, family: 4 }])
        : callback(null, address, 4);
    }
    const req = (url.startsWith('https:') ? https : http).get(url, options, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('error', reject);
      res.on('end', () => resolve({ status: res.statusCode, body: Buffer.concat(chunks).toString('utf8') }));
    });
    req.on('timeout', () => req.destroy(new Error('Request timed out')));
    req.on('error', reject);
  });
}
async function addressesFor(host) {
  for (const servers of [null, ['1.1.1.1'], ['8.8.8.8']]) {
    const resolver = new dns.Resolver({ timeout: 2500, tries: 1 });
    if (servers) resolver.setServers(servers);
    try { const addresses = await resolver.resolve4(host); if (addresses.length) return addresses; } catch {}
  }
  throw new Error('Public Metro hostname could not be resolved');
}
const headers = { 'expo-platform': 'ios', accept: 'application/expo+json' };
const local = await get('http://127.0.0.1:' + expo.port + '/', null, headers);
assert.equal(local.status, 200);
const manifest = JSON.parse(local.body);
// The localhost manifest says http://; use the public transport the launcher verified
// (exps:// = HTTPS on the SDK 58 *.on.expo.app tunnel, exp:// = HTTP on the older ngrok tunnel).
const bundleUrl = new URL(manifest.launchAsset.url);
assert.equal(bundleUrl.host, new URL(expo.expoUrl).host);
bundleUrl.protocol = new URL(expo.expoUrl).protocol === 'exps:' ? 'https:' : 'http:';
assert.match(bundleUrl.hostname, /^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i);
assert.ok(!/^(localhost|127\.|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(bundleUrl.hostname));
const addresses = await addressesFor(bundleUrl.hostname);
async function publicGet(url, requestHeaders = {}) {
  let lastError;
  for (const address of addresses) {
    try { return await get(url, address, requestHeaders); } catch (error) { lastError = error; }
  }
  throw lastError;
}
const status = await publicGet(bundleUrl.origin + '/status');
assert.equal(status.status, 200);
assert.equal(status.body.trim(), 'packager-status:running');
const remote = await publicGet(bundleUrl.origin + '/', headers);
assert.equal(remote.status, 200);
assert.equal(JSON.parse(remote.body).extra.expoClient.hostUri, manifest.extra.expoClient.hostUri);
console.log('PASS: public Metro /status and Expo Go manifest (not localhost-only)');
console.log('Building/fetching current iOS development bundle through the public Expo tunnel...');
const bundle = await publicGet(bundleUrl.href);
assert.equal(bundle.status, 200);
assert.ok(bundle.body.includes(api.publicApiUrl), 'Bundle does not contain the verified current API environment value');
console.log('PASS: public bundle contains the verified current HTTPS API URL; no app source changed');
console.log('Physical-phone/mobile-data verification remains pending.');
