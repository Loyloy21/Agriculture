// Write the verified Expo Go QR as a local, temporary PNG for scanning.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';

const runtime = new URL('../var/cloudflare-tunnel/', import.meta.url);
const api = JSON.parse(fs.readFileSync(new URL('processes.json', runtime), 'utf8'));
const expo = JSON.parse(fs.readFileSync(new URL('expo-process.json', runtime), 'utf8'));
const env = fs.readFileSync(new URL('../frontend/mobile/.env.local', import.meta.url), 'utf8');
const entries = env.split(/\r?\n/).filter((line) => /^EXPO_PUBLIC_API_URL=/.test(line));
assert.equal(entries.length, 1);
assert.equal(entries[0], 'EXPO_PUBLIC_API_URL=' + api.publicApiUrl);
assert.equal(expo.publicApiUrl, api.publicApiUrl);
assert.ok(expo.verifiedAt && api.verifiedAt);
// exps:// = Expo Go over HTTPS (SDK 58 *.on.expo.app tunnel); exp:// = over HTTP (older *.exp.direct ngrok tunnel).
assert.match(expo.expoUrl, /^(exps:\/\/[a-z0-9-]+\.on\.expo\.app|exp:\/\/[a-z0-9-]+\.exp\.direct)$/i);

const requireFromMobile = createRequire(new URL('../frontend/mobile/package.json', import.meta.url));
const { toQR } = requireFromMobile('toqr');
const { PNG } = requireFromMobile('pngjs');
const matrix = toQR(expo.expoUrl);
const count = Math.sqrt(matrix.length);
assert.ok(Number.isInteger(count) && count > 0);
const quiet = 4;
const scale = 12;
const width = (count + quiet * 2) * scale;
const png = new PNG({ width, height: width, colorType: 2 });
for (let y = 0; y < width; y++) {
  for (let x = 0; x < width; x++) {
    const col = Math.floor(x / scale) - quiet;
    const row = Math.floor(y / scale) - quiet;
    const white = row < 0 || col < 0 || row >= count || col >= count || matrix[row * count + col] === 1;
    const offset = (y * width + x) * 4;
    png.data[offset] = png.data[offset + 1] = png.data[offset + 2] = white ? 255 : 0;
    png.data[offset + 3] = 255;
  }
}
fs.writeFileSync(new URL('expo-qr.png', runtime), PNG.sync.write(png));
console.log('Fresh Expo Go QR saved to var/cloudflare-tunnel/expo-qr.png');
