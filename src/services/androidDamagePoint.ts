import { validPoint } from './photoLocationMetadata';

/** User-entered point only, never photo evidence. No permissive Number('') coercion. */
export function parseAndroidDamagePoint(value: string): { latitude: number; longitude: number } | null {
  const parts = value.trim().split(',').map((part) => part.trim());
  if (parts.length !== 2 || parts.some((part) => !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(part))) return null;
  const [latitude, longitude] = parts.map(Number);
  return validPoint(latitude, longitude) ? { latitude, longitude } : null;
}
