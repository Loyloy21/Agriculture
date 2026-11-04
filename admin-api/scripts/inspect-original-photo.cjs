// Read-only original-JPEG inspection. Does not print coordinates or the supplied path.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const ts = require('../frontend/mobile/node_modules/typescript');
function load(name, mocks = {}) {
  const exports = {};
  const source = fs.readFileSync(path.join(__dirname, '../frontend/mobile/src/services', name + '.ts'), 'utf8');
  vm.runInNewContext(ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
  }).outputText, { exports, require: (id) => {
    if (!(id in mocks)) throw new Error('Unsupported dependency');
    return mocks[id];
  }, Date });
  return exports;
}
try {
  if (process.argv.length !== 3) throw new Error('Usage: node scripts/inspect-original-photo.cjs <USB-original-JPG-path>');
  const stat = fs.statSync(process.argv[2]);
  if (!stat.isFile() || stat.size > 64 * 1024 * 1024) throw new Error('Select one original JPEG smaller than 64 MiB.');
  const bytes = fs.readFileSync(process.argv[2]);
  if (bytes[0] !== 0xff || bytes[1] !== 0xd8) throw new Error('The supplied file is not a JPEG.');
  const metadata = load('photoLocationMetadata');
  const parser = load('photoJpegExif', { './photoLocationMetadata': metadata });
  const result = parser.selectedJpegGps(bytes);
  console.log(JSON.stringify({
    bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'),
    embeddedGpsResult: result.state,
    validEmbeddedGpsWithReferences: metadata.validPoint(result.evidence.latitude, result.evidence.longitude),
    modifiedFile: false,
    note: 'Inspects supplied bytes only; cannot determine Gallery database/estimated location or prove how this copy was made.',
  }, null, 2));
} catch (error) {
  // Filesystem errors contain private paths; suppress those messages.
  console.error(error.code ? 'Original JPEG could not be opened. Verify its local path and read access.' : error.message);
  process.exitCode = 1;
}
