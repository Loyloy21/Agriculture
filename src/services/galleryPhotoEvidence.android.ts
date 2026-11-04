// Expo Go Android only. No permission requests, filename guessing or device GPS.
import type { ImagePickerAsset } from 'expo-image-picker';
import Constants from 'expo-constants';
import { File } from 'expo-file-system';
import * as MediaLibrary from 'expo-media-library/legacy';
import { PermissionsAndroid, Platform } from 'react-native';
import { galleryOperation, GalleryTimeoutError } from './galleryOperation';
import { evidenceFromExif, exifGpsState, validPoint, type PhotoEvidence } from './photoLocationMetadata';
import { selectedJpegGps } from './photoJpegExif';

function reason(error: unknown) {
  if (error instanceof GalleryTimeoutError) return 'timeout';
  const message = error instanceof Error ? error.message : String(error);
  if (/ACCESS_MEDIA_LOCATION/i.test(message)) return 'original_media_location_access_denied';
  if (/permission|denied|not granted/i.test(message)) return 'permission_denied';
  return 'read_failed';
}

export async function galleryPhotoEvidence(asset: ImagePickerAsset): Promise<PhotoEvidence> {
  let evidence = evidenceFromExif(asset.exif);
  const diagnostic: Record<string, unknown> = {
    platform: 'android', androidApi: Platform.Version, expoGoVersion: Constants.expoVersion,
    assetIdAvailable: !!asset.assetId, selectedUriType: asset.uri?.startsWith('file://') ? 'file'
      : asset.uri?.startsWith('content://') ? 'content' : asset.uri ? 'other' : 'missing',
    // Installed ImagePicker returns its cache URI, not the provider's original URI.
    originalContentUriExposed: asset.uri?.startsWith('content://') || false,
    pickerExif: exifGpsState(asset.exif), selectedFileExif: 'not_read',
    assetExif: 'not_read', assetLocation: 'not_read', originalFileExif: 'not_read',
    assetRead: asset.assetId ? 'not_read' : 'asset_id_missing',
    mediaLocationPermission: 'not_checked', libraryPermission: 'not_checked',
  };
  const retain = (next: PhotoEvidence) => {
    if (!validPoint(evidence.latitude, evidence.longitude) && validPoint(next.latitude, next.longitude)) {
      evidence = { ...next, capturedAt: evidence.capturedAt || next.capturedAt,
        captureTimeSource: evidence.capturedAt ? evidence.captureTimeSource : next.captureTimeSource };
    }
  };
  const readJpeg = async (uri: string, field: string, knownJpeg: boolean) => {
    if (!/^(file|content):\/\//.test(uri)) { diagnostic[field] = 'uri_not_readable'; return; }
    if (!knownJpeg && !/\.jpe?g(?:\?|$)/i.test(uri)) { diagnostic[field] = 'not_jpeg'; return; }
    try {
      const file = new File(uri);
      if (!file.exists || file.size <= 0 || file.size > 24 * 1024 * 1024) {
        diagnostic[field] = 'file_missing_or_size_limit'; return;
      }
      const result = selectedJpegGps(await galleryOperation('selected_file_read', () => file.bytes(), 4000));
      diagnostic[field] = result.state;
      retain(result.evidence);
    } catch (error) { diagnostic[field] = reason(error); }
  };
  // Inspect bytes before app conversion even if picker EXIF is incomplete.
  await readJpeg(asset.uri || '', 'selectedFileExif', asset.mimeType === 'image/jpeg');
  if (asset.assetId) {
    try {
      const info = await galleryOperation('original_asset_read', () => MediaLibrary.getAssetInfoAsync(asset.assetId!, { shouldDownloadFromNetwork: false }), 5000);
      diagnostic.assetRead = 'read';
      diagnostic.assetExif = exifGpsState(info.exif);
      const original = evidenceFromExif(info.exif);
      const hasLocation = validPoint(info.location?.latitude, info.location?.longitude);
      diagnostic.assetLocation = hasLocation ? 'valid' : 'missing_or_invalid';
      if (hasLocation) {
        const same = original.latitude === info.location!.latitude && original.longitude === info.location!.longitude;
        evidence = { ...evidence, latitude: info.location!.latitude, longitude: info.location!.longitude,
          accuracy: same ? original.accuracy : null, locationSource: 'asset_location',
          locationStatus: 'available', locationIssue: null };
      } else retain(original);
      const originalUri = info.localUri || info.uri;
      if (originalUri && originalUri !== asset.uri) {
        await readJpeg(originalUri, 'originalFileExif', /\.jpe?g$/i.test(info.filename || ''));
      }
      if (!evidence.capturedAt && original.capturedAt) {
        evidence = { ...evidence, capturedAt: original.capturedAt, captureTimeSource: original.captureTimeSource };
      }
      if (!evidence.capturedAt && Number.isFinite(info.creationTime) && info.creationTime > 0) {
        evidence = { ...evidence, capturedAt: new Date(info.creationTime).toISOString(), captureTimeSource: 'asset' };
      }
    } catch (error) { diagnostic.assetRead = reason(error); }
  }
  // Checks only, never trigger the rejected Expo Go library permission prompt.
  if (__DEV__) {
    try {
      const permission = await galleryOperation('media_permission_check', () => MediaLibrary.getPermissionsAsync(false, ['photo']), 2000);
      diagnostic.libraryPermission = permission.accessPrivileges || permission.status;
    } catch (error) { diagnostic.libraryPermission = reason(error); }
    if (Number(Platform.Version) >= 29) {
      try {
        diagnostic.mediaLocationPermission = await galleryOperation('media_location_check', () => PermissionsAndroid.check(PermissionsAndroid.PERMISSIONS.ACCESS_MEDIA_LOCATION), 2000)
          ? 'granted' : 'not_granted_or_not_declared';
      } catch (error) { diagnostic.mediaLocationPermission = reason(error); }
    }
  }
  if (!validPoint(evidence.latitude, evidence.longitude)) {
    const explanation = diagnostic.assetRead === 'original_media_location_access_denied'
      ? 'Original media-location access was denied.'
      : diagnostic.assetRead === 'timeout' ? 'Original metadata reading timed out.'
      : diagnostic.assetRead === 'permission_denied' ? 'The selected original is not accessible with current permissions.'
      : !asset.assetId ? 'The picker supplied no usable GPS or original asset ID.'
      : 'No usable GPS was exposed for this selected photo.';
    evidence = { ...evidence, locationIssue: `No accessible photo GPS. ${explanation} Set a separate farmer-confirmed damage location.` };
  }
  diagnostic.finalSource = evidence.locationSource;
  diagnostic.finalStatus = evidence.locationStatus;
  if (__DEV__) console.info('[AgriReach gallery GPS]', diagnostic);
  return evidence;
}
