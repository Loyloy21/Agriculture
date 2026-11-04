export type PhotoLocationSource = 'photo_exif' | 'asset_location' | 'device_capture' | 'none' | 'legacy_unknown';
export type PhotoEvidence = {
  latitude: number | null;
  longitude: number | null;
  locationSource: PhotoLocationSource;
  accuracy: number | null;
  locationTimestamp: string | null;
  capturedAt: string | null;
  captureTimeSource: 'exif' | 'asset' | 'camera_return' | 'none' | 'legacy_unknown';
  locationIssue: string | null;
  locationStatus: 'available' | 'no_gps' | 'permission_denied' | 'services_disabled' | 'timeout' | 'poor_accuracy' | 'stale_fix' | 'unavailable';
};

export const MAX_CAPTURE_ACCURACY_METERS = 100;
export const MAX_CAPTURE_FIX_AGE_MS = 30_000;
export const CAPTURE_FIX_TIMEOUT_MS = 20_000;

export function validPoint(latitude: unknown, longitude: unknown): boolean {
  return typeof latitude === 'number' && typeof longitude === 'number'
    && Number.isFinite(latitude) && Number.isFinite(longitude)
    && latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180
    && !(latitude === 0 && longitude === 0);
}

export function damageMapRegion(latitude: number, longitude: number) {
  return validPoint(latitude, longitude)
    ? { latitude, longitude, latitudeDelta: 0.006, longitudeDelta: 0.006 }
    : null;
}

function numeric(value: unknown): number | null {
  if (typeof value === 'number') return Number.isFinite(value) ? value : null;
  if (typeof value !== 'string' || !value.trim()) return null;
  const parts = value.trim().split('/');
  const number = parts.length === 2 ? Number(parts[0]) / Number(parts[1]) : Number(value);
  return Number.isFinite(number) ? number : null;
}

function coordinate(value: unknown, reference: unknown, allowed: readonly string[]): number | null {
  const direction = typeof reference === 'string' ? reference.trim().toUpperCase() : '';
  // GPSLatitude/GPSLongitude without their hemisphere tags are ambiguous.
  if (!allowed.includes(direction)) return null;
  let result: number | null;
  if (Array.isArray(value)) {
    const components = value.map(numeric);
    if (components.length !== 3 || components.some((part) => part === null)
      || components[1]! < 0 || components[1]! >= 60 || components[2]! < 0 || components[2]! >= 60) return null;
    result = Math.abs(components[0]!) + components[1]! / 60 + components[2]! / 3600;
    if (components[0]! < 0) result *= -1;
  } else result = numeric(value);
  if (result === null) return null;
  if (result < 0 && (direction === 'N' || direction === 'E')) return null;
  return direction === 'S' || direction === 'W' ? -Math.abs(result) : result;
}

/** Safe to log: describes GPS tag quality without exposing the photo's coordinates. */
export function exifGpsState(input: unknown): 'missing' | 'missing_references' | 'invalid_references' | 'invalid_coordinates' | 'zero_zero' | 'valid' {
  if (!input || typeof input !== 'object') return 'missing';
  const exif = input as Record<string, unknown>;
  const nested = exif['{GPS}'];
  const gps = nested && typeof nested === 'object' ? nested as Record<string, unknown> : exif;
  const lat = gps.GPSLatitude ?? gps.Latitude;
  const lon = gps.GPSLongitude ?? gps.Longitude;
  if (lat == null || lon == null || lat === '' || lon === '') return 'missing';
  const latRef = gps.GPSLatitudeRef ?? gps.LatitudeRef;
  const lonRef = gps.GPSLongitudeRef ?? gps.LongitudeRef;
  if (latRef == null || lonRef == null || latRef === '' || lonRef === '') return 'missing_references';
  const latDirection = String(latRef).trim().toUpperCase();
  const lonDirection = String(lonRef).trim().toUpperCase();
  if (!['N', 'S'].includes(latDirection) || !['E', 'W'].includes(lonDirection)) return 'invalid_references';
  const latitude = coordinate(lat, latRef, ['N', 'S']);
  const longitude = coordinate(lon, lonRef, ['E', 'W']);
  if (latitude === null || longitude === null || !Number.isFinite(latitude) || !Number.isFinite(longitude)
    || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) return 'invalid_coordinates';
  return latitude === 0 && longitude === 0 ? 'zero_zero' : 'valid';
}

export function emptyPhotoEvidence(issue = 'This photo has no accessible location data.'): PhotoEvidence {
  return { latitude: null, longitude: null, locationSource: 'none', accuracy: null, locationTimestamp: null,
    capturedAt: null, captureTimeSource: 'none', locationIssue: issue, locationStatus: 'no_gps' };
}

/** Read the original picker/asset EXIF before image conversion. Never fall back to device GPS. */
export function evidenceFromExif(input: unknown): PhotoEvidence {
  const evidence = emptyPhotoEvidence();
  if (!input || typeof input !== 'object') return evidence;
  const exif = input as Record<string, unknown>;
  const nested = exif['{GPS}'];
  const gps = nested && typeof nested === 'object' ? nested as Record<string, unknown> : exif;
  const latitude = coordinate(gps.GPSLatitude ?? gps.Latitude, gps.GPSLatitudeRef ?? gps.LatitudeRef, ['N', 'S']);
  const longitude = coordinate(gps.GPSLongitude ?? gps.Longitude, gps.GPSLongitudeRef ?? gps.LongitudeRef, ['E', 'W']);
  if (validPoint(latitude, longitude)) {
    Object.assign(evidence, { latitude, longitude, locationSource: 'photo_exif', locationIssue: null, locationStatus: 'available' });
    const accuracy = numeric(gps.GPSHPositioningError ?? gps.HPositioningError);
    if (accuracy !== null && accuracy >= 0) evidence.accuracy = accuracy;
  }
  // EXIF without an offset does not identify an unambiguous instant. Keep it unavailable.
  const date = exif.DateTimeOriginal;
  const offset = exif.OffsetTimeOriginal;
  if (typeof date === 'string' && typeof offset === 'string' && /^[+-]\d{2}:\d{2}$/.test(offset)) {
    const parsed = Date.parse(date.replace(/^(\d{4}):(\d{2}):(\d{2}) /, '$1-$2-$3T') + offset);
    if (Number.isFinite(parsed)) { evidence.capturedAt = new Date(parsed).toISOString(); evidence.captureTimeSource = 'exif'; }
  }
  return evidence;
}

export function photoLocationLabel(source?: string | null, hasCoordinates = false): string {
  if (hasCoordinates && source === 'device_capture') return 'Captured with device location';
  if (hasCoordinates && source === 'photo_exif') return 'Selected photo EXIF GPS';
  if (hasCoordinates && source === 'asset_location') return 'Original library asset location';
  return hasCoordinates ? 'Location source not recorded' : 'No accessible GPS';
}

export function photoLocationStatusLabel(status?: string | null): string {
  return ({ available: 'Location available (not independently verified)', no_gps: 'This photo has no accessible location data.',
    permission_denied: 'Location permission was denied at capture.', services_disabled: 'Location services were off at capture.',
    timeout: 'A fresh location fix timed out.', poor_accuracy: 'GPS accuracy was insufficient for automatic confirmation.',
    stale_fix: 'A fresh capture-time location could not be established.', unavailable: 'Device location could not be read.',
  } as Record<string, string>)[status || ''] || 'Location availability was not recorded for this older photo.';
}

export function pointDistanceMeters(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const rad = (degrees: number) => degrees * Math.PI / 180;
  const value = Math.sin(rad(b.latitude - a.latitude) / 2) ** 2
    + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(rad(b.longitude - a.longitude) / 2) ** 2;
  return 6371000 * 2 * Math.asin(Math.sqrt(Math.min(1, Math.max(0, value))));
}
