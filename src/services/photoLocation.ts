import type { ImagePickerAsset } from 'expo-image-picker';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import { File } from 'expo-file-system';
import * as Location from 'expo-location';
import * as MediaLibrary from 'expo-media-library/legacy';
import { PermissionsAndroid, Platform } from 'react-native';
import { version as expoPackageVersion } from 'expo/package.json';
import { CAPTURE_FIX_TIMEOUT_MS, MAX_CAPTURE_ACCURACY_METERS, MAX_CAPTURE_FIX_AGE_MS, emptyPhotoEvidence, evidenceFromExif, exifGpsState, validPoint, type PhotoEvidence } from './photoLocationMetadata';
import { selectedJpegGps } from './photoJpegExif';
import { galleryOperation, GalleryTimeoutError } from './galleryOperation';

function mediaErrorReason(error: unknown): string {
  if (error instanceof GalleryTimeoutError) return `${error.stage}_timeout`;
  const message = error instanceof Error ? error.message : String(error);
  if (/ACCESS_MEDIA_LOCATION/i.test(message)) return 'access_media_location_missing_or_denied';
  if (/permission|not granted|access/i.test(message)) return 'media_library_permission_denied';
  if (/not found|does not exist|invalid asset/i.test(message)) return 'original_asset_not_found';
  return 'original_asset_lookup_failed';
}

export async function galleryPhotoEvidence(asset: ImagePickerAsset): Promise<PhotoEvidence> {
  let evidence = evidenceFromExif(asset.exif);
  const pickerExif = exifGpsState(asset.exif);
  if (evidence.latitude === null) evidence.locationIssue = 'No accessible photo GPS in the selected image.';
  const diagnostic: Record<string, unknown> = {
    expoSdkPackageVersion: expoPackageVersion, expoGoVersion: Constants.expoVersion,
    platform: Platform.OS, androidApi: Platform.OS === 'android' ? Platform.Version : null,
    osVersion: Device.osVersion, pickerRoute: 'system_picker', assetId: asset.assetId || null,
    selectedMimeType: asset.mimeType || 'unknown', selectedFileNameAvailable: !!asset.fileName,
    selectedUriScheme: asset.uri?.split(':', 1)[0] || 'missing',
    originalContentUriExposedByImagePicker: false, pickerExifGps: pickerExif,
    selectedJpegExifGps: 'not_checked',
    libraryPermission: 'not_checked', mediaLocationPermission: 'not_checked',
    originalUriScheme: 'not_checked', localOriginalUriAvailable: 'not_checked',
    originalExifGps: 'not_checked', originalAssetLocation: 'not_checked',
    unavailableReason: null,
  };
  if (__DEV__ && Platform.OS === 'android' && Number(Platform.Version) >= 29) {
    try {
      diagnostic.mediaLocationPermission = (await galleryOperation('media_location_check', () => PermissionsAndroid.check(PermissionsAndroid.PERMISSIONS.ACCESS_MEDIA_LOCATION), 3000)) ? 'granted' : 'not_granted_or_not_in_manifest';
    } catch { diagnostic.mediaLocationPermission = 'check_failed'; }
  }
  // SDK 57 copies quality=1 JPEGs to its cache. This only helps if the selected
  // provider actually supplied unredacted EXIF bytes; it cannot bypass Android permissions.
  if (Platform.OS === 'android' && evidence.latitude === null && asset.uri?.startsWith('file://')
    && (asset.mimeType === 'image/jpeg' || /\.jpe?g$/i.test(asset.uri))) {
    try {
      const file = new File(asset.uri);
      if (file.exists && file.size > 0 && file.size <= 24 * 1024 * 1024) {
        const raw = selectedJpegGps(await galleryOperation('selected_file_read', () => file.bytes(), 4000));
        diagnostic.selectedJpegExifGps = raw.state;
        if (validPoint(raw.evidence.latitude, raw.evidence.longitude)) evidence = raw.evidence;
      } else diagnostic.selectedJpegExifGps = 'file_missing_or_too_large';
    } catch { diagnostic.selectedJpegExifGps = 'file_read_failed'; }
  }
  // Read only the selected original asset. Limited access and missing asset IDs are normal.
  if (asset.assetId && Platform.OS !== 'web') {
    try {
      let permitted = Platform.OS === 'android';
      try {
        let permission = await galleryOperation('media_permission_check', () => MediaLibrary.getPermissionsAsync(false, ['photo']), 3000);
        diagnostic.libraryPermission = permission.accessPrivileges || permission.status;
        if (Platform.OS === 'android' && Constants.appOwnership !== 'expo' && evidence.latitude === null && !permission.granted) {
          // A custom binary can request its manifest-declared media permissions.
          // Never invoke this failing MediaLibrary permission request in Expo Go.
          permission = await galleryOperation('media_permission_request', () => MediaLibrary.requestPermissionsAsync(false, ['photo']), 15000);
          diagnostic.libraryPermission = permission.accessPrivileges || permission.status;
          diagnostic.mediaLocationPermission = (await galleryOperation('media_location_check', () => PermissionsAndroid.check(PermissionsAndroid.PERMISSIONS.ACCESS_MEDIA_LOCATION), 3000))
            ? 'granted' : 'not_granted_or_not_in_manifest';
        }
        permitted = permitted || permission.granted || permission.accessPrivileges === 'limited';
      } catch (error) {
        diagnostic.libraryPermission = error instanceof GalleryTimeoutError ? 'check_timed_out' : 'check_failed';
        diagnostic.unavailableReason = mediaErrorReason(error);
      }
      // Android's system picker may grant access to this selected asset even when
      // broad MediaLibrary permission is unavailable in Expo Go. Never request it here.
      if (permitted) {
        const info = await galleryOperation('original_asset_read', () => MediaLibrary.getAssetInfoAsync(asset.assetId!, { shouldDownloadFromNetwork: false }), 5000);
        diagnostic.originalUriScheme = info.uri?.split(':', 1)[0] || 'missing';
        diagnostic.localOriginalUriAvailable = !!info.localUri;
        const original = evidenceFromExif(info.exif);
        diagnostic.originalExifGps = exifGpsState(info.exif);
        diagnostic.originalAssetLocation = validPoint(info.location?.latitude, info.location?.longitude)
          ? 'valid' : info.location?.latitude === 0 && info.location?.longitude === 0 ? 'zero_zero' : 'missing_or_invalid';
        if (validPoint(info.location?.latitude, info.location?.longitude)) {
          // Use only the location returned for this exact asset. Do not carry EXIF
          // accuracy over if the two coordinate sources disagree.
          const sameExifPoint = original.latitude === info.location!.latitude && original.longitude === info.location!.longitude;
          evidence = { ...evidence, latitude: info.location!.latitude, longitude: info.location!.longitude,
            accuracy: sameExifPoint ? original.accuracy : null,
            locationSource: 'asset_location', locationStatus: 'available', locationIssue: null };
        } else if (evidence.latitude === null && original.latitude !== null) {
          evidence = { ...original, capturedAt: evidence.capturedAt || original.capturedAt,
            captureTimeSource: evidence.capturedAt ? evidence.captureTimeSource : original.captureTimeSource };
        } else if (evidence.latitude === null) {
          evidence.locationIssue = Platform.OS === 'ios'
            ? 'This photo has no saved location in the accessible original asset.'
            : 'No usable GPS in the accessible original asset. The Gallery place label may not be embedded GPS.';
          diagnostic.unavailableReason = 'original_asset_has_no_usable_gps';
        }
        if (!evidence.capturedAt && original.capturedAt) {
          evidence.capturedAt = original.capturedAt;
          evidence.captureTimeSource = original.captureTimeSource;
        }
        if (!evidence.capturedAt && Number.isFinite(info.creationTime) && info.creationTime > 0) {
          evidence.capturedAt = new Date(info.creationTime).toISOString();
          evidence.captureTimeSource = 'asset';
        }
      } else if (evidence.latitude === null) {
        evidence.locationIssue = diagnostic.libraryPermission === 'check_timed_out'
          ? 'No accessible photo GPS: the photo-library permission check timed out. The photo can still be attached.'
          : 'Original photo location is not accessible with the current photo-library permission.';
        diagnostic.unavailableReason ||= 'media_library_permission_denied';
      }
    } catch (error) {
      // A shared/edited/iCloud/limited-access image may have no accessible original metadata.
      const reason = mediaErrorReason(error);
      diagnostic.unavailableReason = reason;
      if (evidence.latitude === null) evidence.locationIssue = error instanceof GalleryTimeoutError
        ? 'No accessible photo GPS: original metadata reading timed out. The photo can still be attached.'
        : reason === 'access_media_location_missing_or_denied'
        ? 'No accessible photo GPS: original media-location access is unavailable or denied. Select a separate farmer-confirmed map pin.'
        : 'Original photo location could not be read. The photo may be shared, cloud-only, or outside library access.';
    }
  } else if (evidence.latitude === null) {
    diagnostic.unavailableReason = 'asset_id_missing';
    evidence.locationIssue = Platform.OS === 'android'
      ? 'No accessible photo GPS from this Gallery selection. Set a separate farmer-confirmed damage pin on the map.'
      : 'This selected photo has no accessible location.';
    if (__DEV__ && Platform.OS !== 'web') {
      try {
        const permission = await galleryOperation('media_permission_check', () => MediaLibrary.getPermissionsAsync(false, ['photo']), 3000);
        diagnostic.libraryPermission = permission.accessPrivileges || permission.status;
      } catch { diagnostic.libraryPermission = 'check_failed'; }
    }
  }
  diagnostic.finalSource = evidence.locationSource;
  diagnostic.finalStatus = evidence.locationStatus;
  if (__DEV__) console.info('[AgriReach gallery GPS]', diagnostic);
  return evidence;
}

export async function requestCaptureLocationAccess(): Promise<PhotoEvidence | null> {
  if (!(await Location.hasServicesEnabledAsync())) {
    return { ...emptyPhotoEvidence('Location services are off. Turn them on and take a new photo to retry.'), locationStatus: 'services_disabled' };
  }
  const permission = await Location.requestForegroundPermissionsAsync();
  if (!permission.granted) {
    return { ...emptyPhotoEvidence('Location permission was denied. Enable it in Settings and take a new photo to retry.'), locationStatus: 'permission_denied' };
  }
  return null;
}

/** Start a fresh, bounded foreground watch. Never read last-known position. */
export async function freshCaptureEvidence(cameraReturnedAt: number, originalExif?: unknown): Promise<PhotoEvidence> {
  const originalTime = evidenceFromExif(originalExif);
  const capturedAt = originalTime.capturedAt || new Date(cameraReturnedAt).toISOString();
  const captureReference = Date.parse(capturedAt);
  const base: PhotoEvidence = { ...emptyPhotoEvidence(), capturedAt, captureTimeSource: originalTime.capturedAt ? 'exif' : 'camera_return' };
  if (Math.abs(cameraReturnedAt - captureReference) > MAX_CAPTURE_FIX_AGE_MS) {
    return { ...base, locationStatus: 'stale_fix', locationIssue: 'The capture time is too far from a fresh GPS reading. Take a new photo and accept it promptly while still at the damage site.' };
  }
  return new Promise((resolve) => {
    let subscription: Location.LocationSubscription | null = null;
    let finished = false;
    let latestFailure: PhotoEvidence = { ...base, locationStatus: 'timeout', locationIssue: 'A fresh location fix timed out. Stay at the damage site and take a new photo to retry.' };
    const finish = (evidence: PhotoEvidence) => {
      if (finished) return;
      finished = true;
      clearTimeout(timer);
      subscription?.remove();
      resolve(evidence);
    };
    const timer = setTimeout(() => finish(latestFailure), CAPTURE_FIX_TIMEOUT_MS);
    void Location.watchPositionAsync({ accuracy: Location.Accuracy.High, timeInterval: 1000, distanceInterval: 0 }, (position) => {
      if (finished) return;
      if (!validPoint(position.coords.latitude, position.coords.longitude) || position.mocked) {
        latestFailure = { ...base, locationStatus: 'unavailable', locationIssue: 'A reliable device location was not available. Take a new photo at the damage site to retry.' };
        return;
      }
      const age = Date.now() - position.timestamp;
      if (!Number.isFinite(position.timestamp) || age < -5000 || age > MAX_CAPTURE_FIX_AGE_MS || Math.abs(position.timestamp - captureReference) > MAX_CAPTURE_FIX_AGE_MS) {
        latestFailure = { ...base, locationStatus: 'stale_fix', locationIssue: 'The device returned an old location fix. Take a new photo to retry with a fresh location.' };
        return;
      }
      const accuracy = position.coords.accuracy;
      const evidence: PhotoEvidence = { ...base, latitude: position.coords.latitude, longitude: position.coords.longitude,
        locationSource: 'device_capture', accuracy: typeof accuracy === 'number' && Number.isFinite(accuracy) && accuracy >= 0 ? accuracy : null,
        locationTimestamp: new Date(position.timestamp).toISOString(), locationStatus: 'available', locationIssue: null };
      if (accuracy === null || !Number.isFinite(accuracy) || accuracy < 0 || accuracy > MAX_CAPTURE_ACCURACY_METERS) {
        latestFailure = { ...evidence, locationStatus: 'poor_accuracy', locationIssue: `GPS accuracy ${accuracy === null ? 'is unavailable' : `is about ${Math.round(accuracy)} m`}. A fix within ${MAX_CAPTURE_ACCURACY_METERS} m is needed. Move to an open area and take a new photo to retry.` };
        return;
      }
      finish(evidence);
    }, () => finish({ ...base, locationStatus: 'unavailable', locationIssue: 'Device location could not be read. Check Location Services and take a new photo to retry.' }))
      .then((watch) => { subscription = watch; if (finished) watch.remove(); })
      .catch(() => finish({ ...base, locationStatus: 'unavailable', locationIssue: 'Device location could not be read. Check permissions and take a new photo to retry.' }));
  });
}
