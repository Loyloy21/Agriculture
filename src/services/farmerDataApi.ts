import { fetch as expoFetch } from 'expo/fetch';
import { File } from 'expo-file-system';

import { getApiUrl } from '@/config/api';
import { classifyHttpFailure, weatherAdviceShapeProblem, type ApiFailureCode } from '@/services/apiFailure';
import { readFarmerToken } from '@/services/secureFarmerSession';
import { MAX_DAMAGE_IMAGE_BYTES, MAX_DAMAGE_PHOTOS, MAX_DAMAGE_TOTAL_BYTES } from '@/services/uploadImages';
import type { PhotoEvidence } from '@/services/photoLocationMetadata';

export type ReportStatus = 'Pending' | 'Received' | 'Under Review' | 'Approved' | 'Rejected' | 'Resolved';

export type DamagePhoto = {
  id: string;
  uri: string;
  capturedAt: string | null;
  latitude: number | null;
  longitude: number | null;
  mimeType?: string;
  fileName?: string;
  size?: number;
  locationSource?: PhotoEvidence['locationSource'];
  accuracy?: number | null;
  locationTimestamp?: string | null;
  captureTimeSource?: PhotoEvidence['captureTimeSource'];
  locationStatus?: PhotoEvidence['locationStatus'];
  locationIssue?: string | null;
};

export type DamageReport = {
  id: string;
  farmId?: number | null;
  farmName?: string | null;
  farmType: string;
  date: string;
  description: string;
  calamityType: string;
  customDamageType?: string;
  /** Null for reports made with the category-specific form (no peso estimate is asked of the farmer). */
  estimatedCost: number | null;
  /** Category-specific details; null for older reports. Admin-verified figures appear once resolved. */
  damageDetails?: DamageReportDetails | null;
  status: ReportStatus;
  photos: DamagePhoto[];
  location: string;
  latitude: number | null;
  longitude: number | null;
  timestamp: string;
  adminResponse: string | null;
  statusUpdatedAt: string | null;
  locationSource?: string | null;
  locationPhotoOrder?: number | null;
  locationConfirmedAt?: string | null;
};

export type DamageReportFigures = {
  cropDamagePercent: number | null;
  plantedAreaHa: number | null;
  affectedAreaHa: number | null;
  totalBefore: number | null;
  amountLost: number | null;
  lossPercent: number | null;
  summary: string;
};

export type DamageReportDetails = DamageReportFigures & {
  version: number;
  measureType: 'crop_percent' | 'head_count' | 'bird_count' | 'fish_count' | 'fish_weight_kg';
  unit: string;
  subjectLabel: string;
  subjectType: string;
  suspectedCause: string;
  suspectedCauseOther: string | null;
  suspectedCauseLabel: string;
  verified: (DamageReportFigures & { cause: string; causeOther: string | null; causeLabel: string; reviewNotes: string | null; verifiedBy: string | null; verifiedAt: string | null }) | null;
};

export type NewDamageReport = {
  farmId: number;
  farmType: string;
  /** "What happened?" (optional). */
  description: string;
  /** Category-specific multipart fields from damageDetailsFields(). */
  detailFields: Record<string, string>;
  photos: DamagePhoto[];
  damageAddress: string;
  damageLatitude: number | null;
  damageLongitude: number | null;
  clientSubmissionId: string;
  locationConfirmed: boolean;
  locationSource: 'photo' | 'device' | 'manual';
  locationPhotoId: string | null;
};

export type FarmerNotificationType = 'success' | 'info' | 'warning' | 'error';
export type FarmerNotification = {
  id: string;
  type: FarmerNotificationType;
  title: string;
  shortMessage: string;
  fullMessage: string;
  createdAt: string;
  isRead: boolean;
  relatedReportId: string | null;
  relatedAdvisoryId: string | null;
  /** Advisory notifications only: condition · category · end date. */
  context?: string | null;
};

/** Farmer-facing preview: the admin's Key advice, the whole message when already short, or a pointer to it (never a cut-off). */
export type WeatherAdvisoryPreview = { text: string; source: 'key_advice' | 'message' | 'none' };

export type WeatherAdvisory = {
  id: string;
  title: string;
  category: 'Crops' | 'Livestock' | 'Poultry' | 'Fishpond';
  weatherCondition: string;
  locationCoverage: string;
  /** The complete original admin message. */
  message: string;
  keyAdvice?: string | null;
  preview?: WeatherAdvisoryPreview;
  context?: string;
  coverageScope?: 'City' | 'Barangays';
  effectiveDate: string;
  expirationDate: string | null;
  publishedAt: string | null;
  updatedAt: string;
  advisoryKind: string;
  recommendations: string[];
};

export type WeatherAdvisoryEligibility = {
  eligible: boolean;
  code: string;
  message: string;
};

export type WeatherAdvisoryListResult = {
  items: WeatherAdvisory[];
  eligibility: WeatherAdvisoryEligibility;
};

export type CurrentWeather = {
  location: string;
  provider: string;
  observationType: string;
  observedAt: string;
  fetchedAt: string;
  condition: string;
  weatherCode: number;
  isDay: boolean | null;
  temperature: number | null;
  temperatureUnit: string;
  humidity: number | null;
  humidityUnit: string;
  precipitation: number | null;
  precipitationUnit: string;
  rain: number | null;
  rainUnit: string;
  windSpeed: number | null;
  windSpeedUnit: string;
  windDirection: number | null;
  windDirectionUnit: string;
  isStale: boolean;
  cacheAgeMinutes: number;
  statusMessage: string;
};

export type WeatherAdviceStatus = 'current' | 'stale' | 'unavailable';
export type WeatherAdviceAssessment = 'thresholds_exceeded' | 'no_threshold_exceeded' | 'limited' | 'unclear';
export type WeatherAdviceMatch = { code: string; label: string; measured: string; threshold: string; basisType: 'reference' | 'project'; basisLabel: string; severity?: string };
export type WeatherAdviceLimitation = { code: string; label: string; reason: string; kind: 'not_checked' | 'conflict' | 'not_current' };
export type WeatherProviderGrid = { representsModelGridCell: boolean; distanceKm: number | null; elevationM: number | null; notes: string[] };
export type WeatherAdviceRecommendation = {
  conditionCode: string;
  conditionLabel: string;
  category: string;
  actions: string[];
  observedAt: string;
  source: string;
};
export type WeatherPointObservation = {
  provider: string;
  observationType: string;
  endpoint: string;
  requestedLatitude: number;
  requestedLongitude: number;
  providerLatitude: number | null;
  providerLongitude: number | null;
  providerElevation?: number | null;
  observedAt: string;
  fetchedAt: string;
  condition: string;
  weatherCode: number;
  temperature: number | null;
  apparentTemperature: number | null;
  humidity: number | null;
  precipitation: number | null;
  precipitationLast6h: number | null;
  windSpeed: number | null;
  windGusts: number | null;
  windDirection: number | null;
  units: { temperature: string; humidity: string; precipitation: string; windSpeed: string; windDirection: string };
  isStale: boolean;
  observationAgeMinutes: number | null;
  cacheAgeMinutes: number | null;
};
export type WeatherAdviceResult = {
  status: WeatherAdviceStatus;
  statusMessage: string;
  reasonCode: string | null;
  reason: string | null;
  ruleSetVersion: string;
  generatedAt: string;
  farm: { id: number; name: string; category: 'Crops' | 'Livestock' | 'Poultry' | 'Fishpond'; barangayName: string | null; address: string } | null;
  location: { latitude: number; longitude: number; basis: 'farm_pin' | 'city_centre'; label: string; providerGrid?: WeatherProviderGrid } | null;
  weather: WeatherPointObservation | null;
  advice: {
    source: string;
    method: string;
    ruleSetVersion: string;
    category: string;
    observedAt: string;
    dataSource: string;
    assessment: WeatherAdviceAssessment;
    headline: string;
    statement: string;
    disclaimer: string;
    guidanceReview: string;
    /** Kept for older clients; true only for a complete, current reading with no rule triggered. */
    normal: boolean;
    basedOnStaleReading: boolean;
    matched: WeatherAdviceMatch[];
    notEvaluated: { code: string; label: string; reason: string }[];
    conflicts: { code: string; label: string; reason: string }[];
    limitations: WeatherAdviceLimitation[];
    calculations: { latestHourMm: number | null; latestHourEndsAt: string | null; last3hMm: number | null; last6hMm: number | null; heatIndexC: number | null; heatIndexCategory: string | null; heatIndexCategoryLabel: string | null };
    recommendations: WeatherAdviceRecommendation[];
  } | null;
  /** Card summary of `advice` (same wording); null when no reading was obtained. Older servers omit it. */
  summary?: WeatherAdviceCardSummary | null;
};

export type WeatherAdviceCardSummary = {
  level: 'urgent' | 'caution' | 'normal' | 'limited' | 'unclear';
  title: string;
  stale: boolean;
  points: { conditionCode: string; condition: string; detail: string | null; action: string | null; urgency: 'urgent' | 'caution' | null }[];
  also: string[];
};

export type ProfileUpdate = {
  firstName: string;
  middleName: string;
  lastName: string;
  extensionName: string;
  gender: string;
  dateOfBirth: string;
  email: string;
  contactNumber: string;
  address: string;
};

export type FarmUpdate = {
  name: string;
  type: string;
  agriculturalCategory: string;
  location: string;
  sizeHectares: number;
  mainProduct: string;
  latitude?: number | null;
  longitude?: number | null;
};

export type FarmerFarm = {
  id: number;
  name: string;
  type: 'Crops' | 'Livestock' | 'Poultry' | 'Fishpond';
  agriculturalCategory: 'Crops' | 'Livestock' | 'Poultry' | 'Fishpond';
  barangayCode: string | null;
  barangayName: string | null;
  address: string;
  cityName: string | null;
  provinceName: string | null;
  landArea: number | null;
  landAreaUnit: 'hectares';
  mainProduct: string;
  latitude: number | null;
  longitude: number | null;
  status: 'Active' | 'Archived';
  isPrimary: boolean;
  locationReviewStatus: 'Needs Review' | 'Verified' | 'Outside Coverage';
};

export type CabadbaranBarangay = { code: string; name: string };
export type FarmListResult = { farms: FarmerFarm[]; barangays: CabadbaranBarangay[] };
export type FarmInput = {
  name: string;
  type: FarmerFarm['type'];
  agriculturalCategory: FarmerFarm['agriculturalCategory'];
  barangayCode: string;
  address: string;
  landArea: number;
  landAreaUnit: 'hectares';
  mainProduct?: string;
  latitude: number | null;
  longitude: number | null;
};

type ApiPayload<T> = { success?: boolean; message?: string; errors?: Record<string, string>; data?: T };

type RequestOptions = {
  timeoutMs?: number;
  useExpoFetch?: boolean;
};

export class FarmerDataApiError extends Error {
  status?: number;
  errors: Record<string, string>;
  code?: ApiFailureCode;

  constructor(message: string, status?: number, errors: Record<string, string> = {}, code?: ApiFailureCode) {
    super(message);
    this.name = 'FarmerDataApiError';
    this.status = status;
    this.errors = errors;
    this.code = code;
  }
}

async function authenticatedRequest<T>(path: string, init: RequestInit = {}, options: RequestOptions = {}): Promise<T> {
  const token = await readFarmerToken();
  if (!token) throw new FarmerDataApiError('Session expired. Please log in again.', 401, {}, 'auth');

  let requestUrl: string;
  try {
    requestUrl = getApiUrl(path);
  } catch (error) {
    const errorType = error instanceof Error ? error.message : typeof error;
    if (__DEV__) console.warn('[AgriReach API] request configuration failed', { path, errorType });
    if (errorType === 'API_NOT_CONFIGURED') {
      throw new FarmerDataApiError('The AgriReach server is not configured on this device.', undefined, {}, 'config');
    }
    throw new FarmerDataApiError('The AgriReach server address is invalid. Restart Expo after checking the API configuration.', undefined, {}, 'config');
  }

  let response: Response;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? 20000);
  try {
    if (__DEV__) console.info('[AgriReach API] request', { url: requestUrl, method: init.method || 'GET', transport: options.useExpoFetch ? 'expo-fetch' : 'fetch' });
    const request = {
      ...init,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        ...init.headers,
      },
    };
    response = options.useExpoFetch
      ? await expoFetch(requestUrl, request)
      : await fetch(requestUrl, request);
    if (__DEV__) console.info('[AgriReach API] response', { url: requestUrl, status: response.status, contentType: response.headers.get('content-type') || 'not-provided' });
  } catch (error) {
    const errorType = error instanceof Error ? error.name : typeof error;
    if (__DEV__) console.warn('[AgriReach API] request failed', { url: requestUrl, stage: 'fetch', errorType });
    if (errorType === 'AbortError') {
      throw new FarmerDataApiError('The request timed out before the server responded. Please try again.', undefined, {}, 'timeout');
    }
    throw new FarmerDataApiError('Unable to reach the AgriReach server. Check your phone connection, and that the AgriReach API tunnel is running, then try again.', undefined, {}, 'network');
  } finally {
    clearTimeout(timeout);
  }

  let payload: ApiPayload<T>;
  let responseText = '';
  const gateway = response.headers.get('X-AgriReach-Gateway');
  try {
    responseText = await response.text();
    payload = JSON.parse(responseText) as ApiPayload<T>;
  } catch (error) {
    const failure = classifyHttpFailure({ status: response.status, bodyText: responseText, gateway });
    if (__DEV__) console.warn('[AgriReach API] response parse failed', { path: path.split('?')[0], status: response.status, code: failure.code, gateway, errorType: error instanceof Error ? error.name : typeof error });
    throw new FarmerDataApiError(failure.message, response.status, {}, failure.code);
  }

  if (!response.ok || payload.data === undefined) {
    const failure = classifyHttpFailure({ status: response.status, bodyText: responseText, gateway });
    if (__DEV__) console.warn('[AgriReach API] request not accepted', { path: path.split('?')[0], status: response.status, code: failure.code, gateway });
    if (response.status === 401) {
      throw new FarmerDataApiError('Session expired. Please log in again.', 401, {}, 'auth');
    }
    if (failure.code === 'route_unavailable') {
      throw new FarmerDataApiError(failure.message, response.status, {}, 'route_unavailable');
    }
    if (response.status === 413) {
      throw new FarmerDataApiError(payload.message || 'The selected photo upload is too large.', 413, payload.errors);
    }
    if (response.status >= 500) {
      throw new FarmerDataApiError(payload.message || 'The AgriReach server could not save the report. Please try again.', response.status, {}, 'server');
    }
    throw new FarmerDataApiError(payload.message || 'The request could not be completed.', response.status, payload.errors, 'rejected');
  }
  return payload.data;
}

export function listDamageReports() {
  return authenticatedRequest<DamageReport[]>('/reports/index.php');
}

export function getDamageReport(id: string) {
  return authenticatedRequest<DamageReport>(`/reports/show.php?id=${encodeURIComponent(id)}`);
}

export function validateDamagePoint(latitude: number, longitude: number) {
  return authenticatedRequest<{ inside: boolean; source: string }>('/location/validate.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ context: 'damage', latitude, longitude }),
  });
}

export async function submitDamageReport(report: NewDamageReport) {
  if (report.photos.length < 1 || report.photos.length > MAX_DAMAGE_PHOTOS) {
    throw new FarmerDataApiError(`Attach between 1 and ${MAX_DAMAGE_PHOTOS} damage photos.`, 422, { photos: `Attach between 1 and ${MAX_DAMAGE_PHOTOS} damage photos.` });
  }
  let totalPhotoBytes = 0;
  const preparedPhotos = report.photos.map((photo, index) => {
    let file: File;
    try {
      file = new File(photo.uri);
    } catch {
      throw new FarmerDataApiError('A selected damage photo is no longer available. Please choose it again.', undefined, { photos: 'Choose the missing damage photo again.' });
    }
    if (!file.exists || file.size <= 0) {
      throw new FarmerDataApiError('A selected damage photo is no longer available. Please choose it again.', undefined, { photos: 'Choose the missing damage photo again.' });
    }
    if (file.size > MAX_DAMAGE_IMAGE_BYTES) {
      throw new FarmerDataApiError('A selected damage photo is too large.', 413, { photos: 'Use prepared JPG photos no larger than 5MB each.' });
    }
    totalPhotoBytes += file.size;

    const candidate = `${photo.mimeType || ''} ${photo.fileName || ''} ${photo.uri}`.toLowerCase();
    const isPng = candidate.includes('image/png') || /\.png(?:$|[?#])/.test(candidate);
    const isJpeg = candidate.includes('image/jpeg') || candidate.includes('image/jpg') || /\.jpe?g(?:$|[?#])/.test(candidate);
    if (!isPng && !isJpeg) {
      throw new FarmerDataApiError('Only JPG and PNG damage photos can be uploaded.', 422, { photos: 'Choose a JPG or PNG damage photo.' });
    }
    return { file, name: `damage-${index + 1}.${isPng ? 'png' : 'jpg'}` };
  });
  if (totalPhotoBytes > MAX_DAMAGE_TOTAL_BYTES) {
    throw new FarmerDataApiError('The combined photo upload is too large.', 413, { photos: 'Keep the combined damage photos at or below 32MB.' });
  }

  const form = new FormData();
  form.append('farm_profile_id', String(report.farmId));
  form.append('farm_type', report.farmType);
  form.append('damage_description', report.description);
  for (const [name, value] of Object.entries(report.detailFields)) form.append(name, value);
  form.append('submitted_address', report.damageAddress);
  form.append('submitted_latitude', report.damageLatitude === null ? '' : String(report.damageLatitude));
  form.append('submitted_longitude', report.damageLongitude === null ? '' : String(report.damageLongitude));
  form.append('client_submission_id', report.clientSubmissionId);
  form.append('location_confirmed', report.locationConfirmed ? '1' : '0');
  form.append('location_source', report.locationSource);
  const locationPhotoIndex = report.photos.findIndex((photo) => photo.id === report.locationPhotoId);
  form.append('location_photo_order', locationPhotoIndex < 0 ? '' : String(locationPhotoIndex + 1));
  report.photos.forEach((photo, index) => {
    form.append('photos[]', preparedPhotos[index].file, preparedPhotos[index].name);
    form.append(`photo_captured_at[${index}]`, photo.capturedAt || '');
    form.append(`photo_latitude[${index}]`, photo.latitude === null ? '' : String(photo.latitude));
    form.append(`photo_longitude[${index}]`, photo.longitude === null ? '' : String(photo.longitude));
    form.append(`photo_gps_source[${index}]`, photo.locationSource || 'none');
    form.append(`photo_gps_accuracy[${index}]`, photo.accuracy == null ? '' : String(photo.accuracy));
    form.append(`photo_location_timestamp[${index}]`, photo.locationTimestamp || '');
    form.append(`photo_capture_time_source[${index}]`, photo.captureTimeSource || 'none');
    form.append(`photo_location_status[${index}]`, photo.locationStatus || 'no_gps');
  });
  return authenticatedRequest<DamageReport>('/reports/index.php', { method: 'POST', body: form }, { timeoutMs: 90000, useExpoFetch: true });
}

export function listFarmerNotifications() {
  return authenticatedRequest<FarmerNotification[]>('/notifications/index.php');
}

export function markFarmerNotificationRead(id: string) {
  return authenticatedRequest<{ id: string; isRead: boolean }>('/notifications/index.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mark_read', notificationId: id }),
  });
}

export function listWeatherAdvisories(category?: WeatherAdvisory['category']) {
  const query = category ? `?category=${encodeURIComponent(category)}` : '';
  return authenticatedRequest<WeatherAdvisoryListResult>(`/weather/advisories.php${query}`);
}

export function getCurrentWeather(forceRefresh = false) {
  const query = forceRefresh ? '?refresh=1' : '';
  return authenticatedRequest<CurrentWeather>(`/weather/current.php${query}`, {}, { timeoutMs: 15000 });
}

export async function getWeatherAdvice(farmId?: number | null, forceRefresh = false) {
  const query = new URLSearchParams();
  if (farmId) query.set('farmId', String(farmId));
  if (forceRefresh) query.set('refresh', '1');
  const suffix = query.toString() ? `?${query.toString()}` : '';
  const data = await authenticatedRequest<WeatherAdviceResult>(`/weather/advice.php${suffix}`, {}, { timeoutMs: 20000 });
  const problem = weatherAdviceShapeProblem(data);
  if (__DEV__) {
    // Diagnostics only: no token, no coordinates, no address.
    console.info('[AgriReach weather advice]', { farmId: farmId ?? 'primary', status: data?.status, reasonCode: data?.reasonCode ?? null, locationBasis: data?.location?.basis ?? null, stale: data?.weather?.isStale ?? null, shapeProblem: problem });
  }
  if (problem) throw new FarmerDataApiError('The server returned weather advice in a format this app version cannot read. Update the app or the server so they match.', 200, {}, 'bad_response');
  return data;
}

export async function getWeatherAdvisory(id: string) {
  const advisory = await authenticatedRequest<Omit<WeatherAdvisory, 'recommendations'>>(`/weather/advisories.php?id=${encodeURIComponent(id)}`);
  const recommendations = advisory.message.split(/\r?\n/).map((item) => item.replace(/^[-\u2022\s]+/, '').trim()).filter(Boolean);
  return {
    ...advisory,
    recommendations: recommendations.length ? recommendations : [advisory.message],
  } satisfies WeatherAdvisory;
}

export function updateFarmerProfile(profile: ProfileUpdate) {
  return authenticatedRequest<{ updated: true }>('/farmers/profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'update_profile', ...profile }),
  });
}

export function changeFarmerPassword(currentPassword: string, newPassword: string) {
  return authenticatedRequest<{ updated: true }>('/farmers/profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'change_password', currentPassword, newPassword }),
  });
}

export function saveFarmerFarm(farm: FarmUpdate) {
  return authenticatedRequest<{ updated: true }>('/farmers/profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'save_farm', ...farm }),
  });
}

export function deleteFarmerFarm() {
  return authenticatedRequest<{ deleted: true }>('/farmers/profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete_farm' }),
  });
}

export function listFarmerFarms() {
  return authenticatedRequest<FarmListResult>('/farms/index.php');
}

export function getFarmerFarm(id: number) {
  return authenticatedRequest<FarmerFarm>(`/farms/index.php?id=${encodeURIComponent(String(id))}`);
}

export function createFarmerFarm(farm: FarmInput) {
  return authenticatedRequest<FarmerFarm>('/farms/index.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', ...farm }),
  });
}

export function updateFarmerFarm(id: number, farm: FarmInput) {
  return authenticatedRequest<FarmerFarm>('/farms/index.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update', farmId: id, ...farm }),
  });
}

export function archiveFarmerFarm(id: number) {
  return authenticatedRequest<{ archived: true }>('/farms/index.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'archive', farmId: id }),
  });
}

export type ProductionCategoryCode = FarmerFarm['agriculturalCategory'];
export type ProductionCategory = { code: ProductionCategoryCode; label: string };
export type MeasurementUnit = { code: string; name: string; symbol: string; measure: 'weight' | 'count' | 'volume'; kgPerUnit: number | null; decimals: number };
export type ProductionCommodity = {
  id: number;
  name: string;
  category: ProductionCategoryCode;
  group: string;
  productType: 'crop' | 'live_animal' | 'breeding_stock' | 'animal_product' | 'aquaculture';
  /** English, Filipino and local names used by the search. */
  searchNames: string;
  defaultUnit: string;
  /** Allowed unit codes, default first. */
  units: string[];
};
export type ProductionCatalog = { categories: ProductionCategory[]; units: MeasurementUnit[]; commodities: ProductionCommodity[] };
export type ProductionRecordStatus = 'Submitted' | 'Verified' | 'Rejected';
export type ProductionRecord = {
  id: string;
  /** Persisted report ID: RSBSA reference + the farmer's sequence shared with damage reports, or "PR-000123" for older records. Older servers omit it. */
  reportCode?: string;
  commodityId: number;
  commodityName: string;
  category: string;
  categoryLabel: string;
  commodityGroup: string;
  farmId: number | null;
  farmName: string | null;
  farmBarangay: string | null;
  /** YYYY-MM-DD (Manila calendar). */
  harvestDate: string;
  quantity: number;
  unitCode: string;
  unitName: string;
  unitSymbol: string;
  /** Kilogram equivalent for kg and MT records; null for head, pieces, trays and liters. */
  quantityKg: number | null;
  remarks: string | null;
  status: ProductionRecordStatus;
  /** False for rejected records: shown, but never added to totals. */
  counted: boolean;
  submittedAt: string;
  updatedAt: string;
};
export type ProductionCropTotal = { commodityId: number; name: string; category: string; totalKg: number; recordCount: number; percent: number };
/** A total in a unit that is never added to kilograms (head, pieces, trays, liters). */
export type ProductionUnitTotal = { unitCode: string; unitName: string; unitSymbol: string; total: number; recordCount: number };
export type ProductionOtherMeasure = { commodityId: number; name: string; category: string; unitCode: string; unitSymbol: string; total: number; recordCount: number };
export type ProductionMonthSummary = {
  month: string; label: string; totalKg: number; recordCount: number; crops: ProductionCropTotal[];
  otherMeasures: ProductionOtherMeasure[]; otherTotals: ProductionUnitTotal[];
};
export type ProductionOverview = { today: string; currentMonth: ProductionMonthSummary; previousMonth: ProductionMonthSummary; recent: ProductionRecord[]; years: number[] };
export type ProductionMonthDetail = ProductionMonthSummary & { records: ProductionRecord[] };
export type ProductionYearMonth = { month: string; label: string; shortLabel: string; totalKg: number; recordCount: number; otherTotals: ProductionUnitTotal[]; isFuture: boolean };
export type ProductionYear = { year: number; totalKg: number; recordCount: number; otherTotals: ProductionUnitTotal[]; months: ProductionYearMonth[]; years: number[] };
export type NewProductionRecord = {
  farmId: number;
  category: ProductionCategoryCode;
  commodityId: number;
  harvestDate: string;
  quantity: string;
  unitCode: string;
  remarks: string;
  clientSubmissionId: string;
  /** Set only after the farmer confirms that a matching record is a separate harvest. */
  confirmDuplicate?: boolean;
};

export function getProductionOverview() {
  return authenticatedRequest<ProductionOverview>('/production/index.php');
}

export function getProductionMonth(month: string) {
  return authenticatedRequest<ProductionMonthDetail>(`/production/index.php?view=month&month=${encodeURIComponent(month)}`);
}

export function getProductionYear(year: number) {
  return authenticatedRequest<ProductionYear>(`/production/index.php?view=year&year=${encodeURIComponent(String(year))}`);
}

export function getProductionRecord(id: string) {
  return authenticatedRequest<ProductionRecord>(`/production/index.php?id=${encodeURIComponent(id)}`);
}

export function getProductionCatalog() {
  return authenticatedRequest<ProductionCatalog>('/production/index.php?view=catalog');
}

export function submitProductionRecord(record: NewProductionRecord) {
  return authenticatedRequest<{ record: ProductionRecord; alreadySaved: boolean }>('/production/index.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(record),
  });
}

export async function getAuthenticatedImageSource(uri: string) {
  const token = await readFarmerToken();
  return token ? { uri: getApiUrl(uri), headers: { Authorization: `Bearer ${token}` } } : { uri: '' };
}
