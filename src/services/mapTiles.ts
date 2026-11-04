/**
 * Slippy-map tile maths (Web Mercator, 256 px tiles) for the registration pin map.
 *
 * Why this exists: the Android registration map used the native Google Maps SDK, which draws NOTHING unless
 * the installed APK carries an authorised Maps SDK key. These helpers let plain React Native <Image> tiles
 * draw the map instead, so imagery and the pin do not depend on native configuration.
 * Pure functions only: no React, no network.
 */
export const TILE_SIZE = 256;
export const MIN_ZOOM = 5;
export const MAX_ZOOM = 19;
export const MAX_LATITUDE = 85.0511287798;

export type LatLng = { latitude: number; longitude: number };
export type TileRequest = { key: string; url: string; left: number; top: number; z: number; x: number; y: number };

export const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const TILE_ATTRIBUTION = '© OpenStreetMap contributors';
export const TILE_USER_AGENT = 'AgriReach-Capstone/1.0 (Cabadbaran City farmer registration map)';

/** A custom template is accepted only if it is https and has {z}, {x} and {y}; otherwise the default is used. */
export function resolveTileUrlTemplate(configured: string | undefined | null): string {
  const value = (configured ?? '').trim();
  return /^https:\/\/[^\s]+$/.test(value) && value.includes('{z}') && value.includes('{x}') && value.includes('{y}') ? value : DEFAULT_TILE_URL;
}

export function clampZoom(zoom: number): number {
  return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Math.round(zoom)));
}

/** Zoom level whose viewport of `widthPx` spans about `longitudeDelta` degrees (maps a native Region to a tile zoom). */
export function zoomForLongitudeDelta(longitudeDelta: number, widthPx: number): number {
  if (!(longitudeDelta > 0) || !(widthPx > 0)) return 13;
  return clampZoom(Math.log2((widthPx * 360) / (TILE_SIZE * longitudeDelta)));
}

export function isValidLatLng(point: { latitude: number; longitude: number } | null | undefined): point is LatLng {
  return !!point && Number.isFinite(point.latitude) && Number.isFinite(point.longitude)
    && Math.abs(point.latitude) <= MAX_LATITUDE && Math.abs(point.longitude) <= 180
    && !(point.latitude === 0 && point.longitude === 0);
}

function worldSize(zoom: number) {
  return TILE_SIZE * 2 ** zoom;
}

/** Longitude/latitude to world pixel coordinates at a zoom level (origin top-left of the world). */
export function pointToWorld(point: LatLng, zoom: number): { x: number; y: number } {
  const size = worldSize(zoom);
  const latitude = Math.max(-MAX_LATITUDE, Math.min(MAX_LATITUDE, point.latitude));
  const sin = Math.sin((latitude * Math.PI) / 180);
  return {
    x: ((point.longitude + 180) / 360) * size,
    y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * size,
  };
}

export function worldToPoint(world: { x: number; y: number }, zoom: number): LatLng {
  const size = worldSize(zoom);
  const longitude = (world.x / size) * 360 - 180;
  const n = Math.PI - (2 * Math.PI * world.y) / size;
  const latitude = (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
  return { latitude, longitude };
}

/** Pixel position of a point inside a viewport of `width` x `height` centred on `center`. */
export function pointToViewport(point: LatLng, center: LatLng, zoom: number, width: number, height: number): { x: number; y: number } {
  const p = pointToWorld(point, zoom);
  const c = pointToWorld(center, zoom);
  return { x: width / 2 + (p.x - c.x), y: height / 2 + (p.y - c.y) };
}

/** The point under a pixel of the viewport. This is how a tap becomes the exact selected coordinate. */
export function viewportToPoint(x: number, y: number, center: LatLng, zoom: number, width: number, height: number): LatLng {
  const c = pointToWorld(center, zoom);
  return worldToPoint({ x: c.x + (x - width / 2), y: c.y + (y - height / 2) }, zoom);
}

/** Move the centre by a screen drag (dx, dy in px). Dragging right moves the map right, so the centre moves left. */
export function panCenter(center: LatLng, zoom: number, dx: number, dy: number): LatLng {
  const c = pointToWorld(center, zoom);
  const size = worldSize(zoom);
  const next = { x: ((c.x - dx) % size + size) % size, y: Math.max(0, Math.min(size, c.y - dy)) };
  return worldToPoint(next, zoom);
}

export function tileUrl(template: string, z: number, x: number, y: number): string {
  return template.replace('{z}', String(z)).replace('{x}', String(x)).replace('{y}', String(y));
}

/** Tiles needed to cover the viewport, each with its pixel offset inside it. Wraps longitude; skips rows outside the world. */
export function visibleTiles(center: LatLng, zoom: number, width: number, height: number, template: string): TileRequest[] {
  if (!(width > 0) || !(height > 0)) return [];
  const c = pointToWorld(center, zoom);
  const originX = c.x - width / 2;
  const originY = c.y - height / 2;
  const count = 2 ** zoom;
  const tiles: TileRequest[] = [];
  for (let ty = Math.floor(originY / TILE_SIZE); ty <= Math.floor((originY + height) / TILE_SIZE); ty++) {
    if (ty < 0 || ty >= count) continue;
    for (let tx = Math.floor(originX / TILE_SIZE); tx <= Math.floor((originX + width) / TILE_SIZE); tx++) {
      const wrappedX = ((tx % count) + count) % count;
      tiles.push({ key: `${zoom}/${wrappedX}/${ty}@${tx}`, url: tileUrl(template, zoom, wrappedX, ty), left: tx * TILE_SIZE - originX, top: ty * TILE_SIZE - originY, z: zoom, x: wrappedX, y: ty });
    }
  }
  return tiles;
}

/** Exact-point links for the "map imagery could not load" state and the always-available review link. */
export function openStreetMapLink(point: LatLng, zoom = 18): string {
  return `https://www.openstreetmap.org/?mlat=${point.latitude}&mlon=${point.longitude}#map=${zoom}/${point.latitude}/${point.longitude}`;
}

export function googleMapsLink(point: LatLng): string {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${point.latitude},${point.longitude}`)}`;
}

export function formatCoordinates(point: LatLng): string {
  return `${point.latitude.toFixed(6)}, ${point.longitude.toFixed(6)}`;
}
