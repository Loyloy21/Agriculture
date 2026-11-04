import { evidenceFromExif, exifGpsState, type PhotoEvidence } from './photoLocationMetadata';

/** Read only GPS tags from a selected JPEG's APP1 EXIF block; never infer coordinates. */
export function selectedJpegGps(bytes: Uint8Array): { evidence: PhotoEvidence; state: string } {
  const none = () => ({ evidence: evidenceFromExif(null), state: 'missing_or_invalid_jpeg_exif' });
  if (bytes.length < 8 || bytes[0] !== 0xff || bytes[1] !== 0xd8) return none();
  for (let cursor = 2; cursor + 4 <= bytes.length;) {
    if (bytes[cursor] !== 0xff) return none();
    while (bytes[cursor] === 0xff) cursor++;
    const marker = bytes[cursor++];
    if (marker === 0xda || marker === 0xd9 || cursor + 2 > bytes.length) break;
    if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) continue;
    const length = (bytes[cursor] << 8) | bytes[cursor + 1];
    if (length < 2 || cursor + length > bytes.length) return none();
    const start = cursor + 2;
    const end = cursor + length;
    if (marker === 0xe1 && end - start >= 14 && bytes[start] === 0x45 && bytes[start + 1] === 0x78
      && bytes[start + 2] === 0x69 && bytes[start + 3] === 0x66 && bytes[start + 4] === 0 && bytes[start + 5] === 0) {
      const tags = readGpsIfd(bytes, start + 6, end);
      if (tags) return { evidence: evidenceFromExif(tags), state: exifGpsState(tags) };
    }
    cursor = end;
  }
  return none();
}

function readGpsIfd(bytes: Uint8Array, tiff: number, end: number): Record<string, unknown> | null {
  if (tiff + 8 > end) return null;
  const little = bytes[tiff] === 0x49 && bytes[tiff + 1] === 0x49;
  if (!little && !(bytes[tiff] === 0x4d && bytes[tiff + 1] === 0x4d)) return null;
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const within = (at: number, count: number) => Number.isSafeInteger(at) && at >= tiff && at + count <= end;
  const u16 = (at: number) => within(at, 2) ? view.getUint16(at, little) : null;
  const u32 = (at: number) => within(at, 4) ? view.getUint32(at, little) : null;
  if (u16(tiff + 2) !== 42) return null;
  const ifdEntries = (offset: number) => {
    const at = tiff + offset;
    const count = u16(at);
    if (count === null || count > 512 || !within(at + 2, count * 12 + 4)) return null;
    return Array.from({ length: count }, (_, index) => at + 2 + index * 12);
  };
  const ifd0Offset = u32(tiff + 4);
  const ifd0 = ifd0Offset === null ? null : ifdEntries(ifd0Offset);
  if (!ifd0) return null;
  const gpsEntry = ifd0.find((entry) => u16(entry) === 0x8825 && u16(entry + 2) === 4 && u32(entry + 4) === 1);
  const gpsOffset = gpsEntry === undefined ? null : u32(gpsEntry + 8);
  const gpsIfd = gpsOffset === null ? null : ifdEntries(gpsOffset);
  if (!gpsIfd) return null;
  const entryFor = (tag: number) => gpsIfd.find((entry) => u16(entry) === tag);
  const reference = (tag: number) => {
    const entry = entryFor(tag);
    if (entry === undefined || u16(entry + 2) !== 2) return null;
    const count = u32(entry + 4);
    if (count === null || count < 2 || count > 16) return null;
    const at = count <= 4 ? entry + 8 : tiff + (u32(entry + 8) ?? -1);
    return within(at, count) && bytes[at] !== 0 ? String.fromCharCode(bytes[at]) : null;
  };
  const dms = (tag: number) => {
    const entry = entryFor(tag);
    if (entry === undefined || u16(entry + 2) !== 5 || u32(entry + 4) !== 3) return null;
    const offset = u32(entry + 8);
    const at = offset === null ? -1 : tiff + offset;
    if (!within(at, 24)) return null;
    return [0, 1, 2].map((index) => {
      const numerator = u32(at + index * 8);
      const denominator = u32(at + index * 8 + 4);
      return numerator === null || denominator === null || denominator === 0 ? Number.NaN : numerator / denominator;
    });
  };
  return { GPSLatitude: dms(2), GPSLatitudeRef: reference(1), GPSLongitude: dms(4), GPSLongitudeRef: reference(3) };
}
