import { fetch as expoFetch } from 'expo/fetch';
import { File } from 'expo-file-system';

import { getApiUrl } from '@/config/api';
import { MAX_ID_IMAGE_BYTES } from '@/services/uploadImages';

export type FarmerRegistrationRequest = {
  firstName: string;
  /** Optional; sent as an empty string when not used. */
  middleName: string;
  lastName: string;
  /** Optional suffix such as Jr., Sr., II or III; empty when not used. */
  extensionName: string;
  gender: string;
  customGender: string;
  email: string;
  phone: string;
  residentialBarangayCode: string;
  residentialPurokSitio: string;
  residentialLandmark: string;
  residentialLocationMethod: 'Manual' | 'Map';
  residentialLatitude: number | null;
  residentialLongitude: number | null;
  dateOfBirth: string;
  password: string;
  confirmPassword: string;
  farmName: string;
  farmType: string;
  farmBarangayCode: string;
  farmPurokSitio: string;
  farmLandmark: string;
  farmLocationMethod: 'Manual' | 'Map';
  farmSize: string;
  mainProduct: string;
  latitude: number | null;
  longitude: number | null;
  idType: string;
  idNumber: string;
  idFrontImage: { uri: string; name: string; type: string };
  idBackImage: { uri: string; name: string; type: string } | null;
};

type RegistrationResponse = {
  success: boolean;
  message: string;
  errors?: Record<string, string>;
  application?: { id: number; verificationStatus: 'Pending' };
};

export class RegistrationApiError extends Error {
  fields: Record<string, string>;
  status?: number;

  constructor(message: string, fields: Record<string, string> = {}, status?: number) {
    super(message);
    this.name = 'RegistrationApiError';
    this.fields = fields;
    this.status = status;
  }
}

export async function registerFarmer(request: FarmerRegistrationRequest) {
  let requestUrl: string;
  try {
    requestUrl = getApiUrl('/farmers/register.php');
  } catch (error) {
    const errorType = error instanceof Error ? error.message : typeof error;
    if (__DEV__) console.warn('[AgriReach registration] request failed', { url: 'unavailable', stage: 'configuration', errorType });
    if (errorType === 'API_NOT_CONFIGURED') throw new RegistrationApiError('Registration server is not configured on this device.');
    throw new RegistrationApiError('The registration server address is invalid. Restart Expo after checking the API configuration.');
  }
  const rsbsaId = request.idType === 'RSBSA ID';
  const frontLabel = rsbsaId ? 'Front of RSBSA ID' : 'ID Front';
  const backLabel = rsbsaId ? 'Back of RSBSA ID' : 'ID Back';
  let idFrontFile: File;
  let idBackFile: File | null = null;
  try {
    idFrontFile = new File(request.idFrontImage.uri);
    if (!idFrontFile.exists || idFrontFile.size <= 0) throw new Error('FRONT_IMAGE_FILE_UNAVAILABLE');
    if (idFrontFile.size > MAX_ID_IMAGE_BYTES) {
      throw new RegistrationApiError(`The ${frontLabel} image is too large.`, { idFrontImageUri: `Choose a ${frontLabel} image that is 5MB or smaller.` });
    }
    if (request.idBackImage) {
      idBackFile = new File(request.idBackImage.uri);
      if (!idBackFile.exists || idBackFile.size <= 0) throw new Error('BACK_IMAGE_FILE_UNAVAILABLE');
      if (idBackFile.size > MAX_ID_IMAGE_BYTES) {
        throw new RegistrationApiError(`The ${backLabel} image is too large.`, { idBackImageUri: `Choose a ${backLabel} image that is 5MB or smaller.` });
      }
    }
  } catch (error) {
    if (error instanceof RegistrationApiError) throw error;
    if (__DEV__) console.warn('[AgriReach registration] request failed', { url: requestUrl, stage: 'prepare-file', errorType: error instanceof Error ? error.name : typeof error });
    throw new RegistrationApiError('A selected ID photo is no longer available. Please choose it again.', {
      idFrontImageUri: `Check the ${frontLabel} photo.`,
      idBackImageUri: request.idType === 'Passport' ? '' : `Check the ${backLabel} photo.`,
    });
  }

  const form = new FormData();
  form.append('first_name', request.firstName);
  form.append('middle_name', request.middleName);
  form.append('last_name', request.lastName);
  form.append('extension_name', request.extensionName);
  form.append('gender', request.gender);
  form.append('custom_gender', request.customGender);
  form.append('email', request.email);
  form.append('contact_number', request.phone);
  form.append('residential_city_psgc_code', '1600203000');
  form.append('residential_barangay_psgc_code', request.residentialBarangayCode);
  form.append('residential_purok_sitio', request.residentialPurokSitio);
  form.append('residential_landmark', request.residentialLandmark);
  form.append('residential_location_method', request.residentialLocationMethod);
  form.append('residential_latitude', request.residentialLatitude === null ? '' : String(request.residentialLatitude));
  form.append('residential_longitude', request.residentialLongitude === null ? '' : String(request.residentialLongitude));
  form.append('date_of_birth', request.dateOfBirth);
  form.append('password', request.password);
  form.append('confirm_password', request.confirmPassword);
  form.append('farm_name', request.farmName);
  form.append('farm_type', request.farmType);
  form.append('agricultural_category', request.farmType);
  form.append('farm_city_psgc_code', '1600203000');
  form.append('farm_barangay_psgc_code', request.farmBarangayCode);
  form.append('farm_purok_sitio', request.farmPurokSitio);
  form.append('farm_landmark', request.farmLandmark);
  form.append('farm_location_method', request.farmLocationMethod);
  form.append('land_area', request.farmSize);
  form.append('main_product', request.mainProduct);
  form.append('latitude', request.latitude === null ? '' : String(request.latitude));
  form.append('longitude', request.longitude === null ? '' : String(request.longitude));
  form.append('id_type', request.idType);
  form.append('id_number', request.idNumber);
  form.append('valid_id_front', idFrontFile, request.idFrontImage.name);
  if (request.idBackImage && idBackFile) form.append('valid_id_back', idBackFile, request.idBackImage.name);

  if (__DEV__) {
    const probeController = new AbortController();
    const probeTimeout = setTimeout(() => probeController.abort(), 10000);
    try {
      const probeResponse = await expoFetch(requestUrl, { method: 'GET', signal: probeController.signal });
      console.info('[AgriReach registration] connectivity probe', { url: requestUrl, status: probeResponse.status });
    } catch (error) {
      const errorType = error instanceof Error ? error.name : typeof error;
      console.warn('[AgriReach registration] request failed', { url: requestUrl, stage: 'connectivity-probe', errorType });
      if (errorType === 'AbortError') throw new RegistrationApiError('The registration server connection timed out before upload started.');
      throw new RegistrationApiError('The app cannot access the local registration server. Check Local Network access for Expo Go and restart the app.');
    } finally {
      clearTimeout(probeTimeout);
    }
  }

  let response: Response;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 45000);
  try {
    if (__DEV__) console.info('[AgriReach registration] request', { url: requestUrl });
    response = await expoFetch(requestUrl, { method: 'POST', body: form, signal: controller.signal });
    if (__DEV__) console.info('[AgriReach registration] response', { url: requestUrl, status: response.status });
  } catch (error) {
    const errorType = error instanceof Error ? error.name : typeof error;
    if (__DEV__) console.warn('[AgriReach registration] request failed', { url: requestUrl, stage: 'fetch', errorType });
    if (errorType === 'AbortError') {
      throw new RegistrationApiError('The registration request timed out. Check your connection and try again.');
    }
    throw new RegistrationApiError('Could not connect to the registration server. The request did not receive an HTTP response.');
  } finally {
    clearTimeout(timeout);
  }

  let payload: RegistrationResponse;
  try {
    payload = await response.json() as RegistrationResponse;
  } catch (error) {
    if (__DEV__) console.warn('[AgriReach registration] response parse failed', { url: requestUrl, status: response.status, errorType: error instanceof Error ? error.name : typeof error });
    throw new RegistrationApiError('The registration server returned an invalid response.', {}, response.status);
  }

  if (!response.ok || !payload.success || !payload.application) {
    throw new RegistrationApiError(payload.message || 'Registration could not be completed.', payload.errors, response.status);
  }

  return payload.application;
}

type FarmPinValidationResponse = {
  success?: boolean;
  code?: string;
  message?: string;
  data?: { status?: 'inside'; source?: string };
  errors?: Record<string, string>;
};

export async function validateFarmPin(latitude: number, longitude: number, context: 'farm' | 'residential' = 'farm') {
  const field = context === 'residential' ? 'residentialPin' : 'farmPin';
  const label = context === 'residential' ? 'home' : 'farm';
  let requestUrl: string;
  try {
    requestUrl = getApiUrl('/location/validate.php');
  } catch (error) {
    if (__DEV__) console.warn('[AgriReach pin check]', { endpoint: '/location/validate.php', errorCode: 'API_URL_INVALID' });
    const message = error instanceof Error && error.message === 'API_NOT_CONFIGURED'
      ? 'The API URL is empty. Start the API tunnel, then restart Metro with --clear and reload AgriReach. You can still enter the address manually.'
      : 'The API URL is invalid. Verify EXPO_PUBLIC_API_URL, then restart Metro with --clear and reload AgriReach. You can still enter the address manually.';
    throw new RegistrationApiError(message, { [field]: message });
  }
  const startedAt = Date.now();
  if (__DEV__) console.info('[AgriReach pin check] request', { apiUrl: requestUrl.replace(/\/location\/validate\.php$/, ''), endpoint: '/location/validate.php', method: 'POST', context, timeoutMs: 15000 });
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15000);
  let status: number | undefined;
  const fail = (message: string, code: string): never => {
    if (__DEV__) console.warn('[AgriReach pin check] failed', { url: requestUrl, context, status, errorCode: code, elapsedMs: Date.now() - startedAt });
    throw new RegistrationApiError(message, { [field]: message }, status);
  };
  try {
    const response = await fetch(requestUrl, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ latitude, longitude, context }),
      signal: controller.signal,
    });
    status = response.status;
    let payload: FarmPinValidationResponse | null = null;
    try {
      const body: unknown = await response.json();
      if (body && typeof body === 'object' && !Array.isArray(body)) payload = body as FarmPinValidationResponse;
    } catch (error) {
      if (controller.signal.aborted) throw error;
      // Cloudflare/Apache can send HTML. Classify its HTTP status, not as a network failure.
    }
    if (__DEV__) console.info('[AgriReach pin check] response', {
      url: requestUrl, context, status, elapsedMs: Date.now() - startedAt,
      errorCode: payload?.code || (response.ok ? 'OK' : 'HTTP_ERROR'),
      validator: response.headers.get('X-AgriReach-Boundary-Validator'),
      // Never log coordinates, request JSON, arbitrary response bodies or personal addresses.
    });
    if (status === 401 || status === 403) fail(`The server refused this registration pin check (HTTP ${status}). This endpoint should allow unsigned home/farm checks; check the API gateway configuration. No pin was saved.`, 'PIN_ACCESS_DENIED');
    if (status === 404 || status === 405) fail(`The pin-check endpoint is unavailable (HTTP ${status}). Verify the current API tunnel and /location/validate.php route, then restart Metro after any URL change.`, 'PIN_ROUTE_UNAVAILABLE');
    if (status === 503 && payload?.code === 'BOUNDARY_DATA_UNAVAILABLE') fail('Cabadbaran boundary data is unavailable on the server (HTTP 503). Retry later or use the manual address. No pin was saved.', 'BOUNDARY_DATA_UNAVAILABLE');
    if (status >= 500) fail(`The server or API tunnel returned HTTP ${status}. Check Apache/PHP and the running tunnel, then retry or use the manual address. This is a server response, not a device GPS error.`, 'PIN_SERVER_ERROR');
    if (!payload) return fail(`The server returned a non-API response (HTTP ${status}). Verify the restricted API tunnel and PHP endpoint; no pin was saved.`, 'INVALID_API_RESPONSE');
    if (!response.ok || !payload.success || payload.data?.status !== 'inside') {
      const message = payload.errors?.[field] || payload.message || `The server could not validate the ${label} pin.`;
      return fail(status === 422 ? `${message} (HTTP 422; server validation.)` : `${message} (HTTP ${status}.)`, payload.code || 'PIN_VALIDATION_ERROR');
    }
    return { source: payload.data.source || '' };
  } catch (error) {
    if (error instanceof RegistrationApiError) throw error;
    if (controller.signal.aborted) fail('The pin check timed out after 15 seconds. Check the running API tunnel and connection, then retry or use the manual address. No pin was saved.', 'API_TIMEOUT');
    fail('No HTTP response was received from the API. The tunnel may have expired, or DNS/Internet access failed. Restart the API tunnel; if its URL changes, restart Metro with --clear and reload. Manual address entry remains available.', 'API_NETWORK_ERROR');
  } finally {
    // Bound response-body reads too, not just the initial fetch.
    clearTimeout(timeout);
  }
}
