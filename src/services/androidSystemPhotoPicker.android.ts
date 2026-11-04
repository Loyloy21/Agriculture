import Constants from 'expo-constants';
import type { ImagePickerAsset } from 'expo-image-picker';
import { requireOptionalNativeModule } from 'expo-modules-core';
import { PermissionsAndroid, Platform } from 'react-native';
import { emptyPhotoEvidence, validPoint, type PhotoEvidence } from './photoLocationMetadata';

type Original = { uri: string; width: number; height: number; mimeType: string; gpsState: string; latitude: number | null; longitude: number | null; selectedAuthority: string; exactMediaId: boolean };
type NativePicker = { pickOriginalPhotos(limit: number): Promise<Original[]> };
const picker = requireOptionalNativeModule<NativePicker>('AndroidOriginalPhotoPicker');

export function androidOriginalPickerAvailable() {
  return Platform.OS === 'android' && Constants.appOwnership !== 'expo' && !!picker;
}

export async function pickAndroidOriginalPhotos(limit: number): Promise<{ picked: ImagePickerAsset; evidence: PhotoEvidence }[]> {
  if (!picker || !androidOriginalPickerAvailable()) throw new Error('Original-photo GPS requires the AgriReach Android development build.');
  let permission = 'not_required';
  let mediaReadPermission = 'not_required';
  if (Number(Platform.Version) >= 33) {
    mediaReadPermission = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.READ_MEDIA_IMAGES);
  } else if (Number(Platform.Version) >= 29) {
    mediaReadPermission = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.READ_EXTERNAL_STORAGE);
  }
  if (Number(Platform.Version) >= 29) {
    permission = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.ACCESS_MEDIA_LOCATION);
  }
  const selected = await picker.pickOriginalPhotos(limit);
  if (__DEV__) console.info('[AgriReach original gallery]', {
    androidApi: Platform.Version, mediaReadPermission, mediaLocationPermission: permission,
    selectedCount: selected.length, sources: selected.map((photo) => ({ authority: photo.selectedAuthority,
      exactMediaId: photo.exactMediaId, gpsState: photo.gpsState })),
  });
  return selected.map((photo) => {
    const verified = photo.gpsState === 'valid_original_exif' && validPoint(photo.latitude, photo.longitude);
    const evidence: PhotoEvidence = verified
      ? { ...emptyPhotoEvidence(), latitude: photo.latitude, longitude: photo.longitude,
          locationSource: 'photo_exif', locationStatus: 'available', locationIssue: null }
      : emptyPhotoEvidence(photo.gpsState === 'media_location_permission_denied'
        ? 'No accessible photo GPS: Android original-media location permission was denied. Set a separate farmer-confirmed damage point.'
        : photo.gpsState === 'original_content_unavailable'
          ? 'No accessible photo GPS: Android did not provide the unredacted original for this selection. Set a separate farmer-confirmed damage point.'
          : photo.gpsState === 'selected_uri_has_no_exact_media_id'
            ? 'No accessible photo GPS: this picker did not provide an exact MediaStore photo ID. Set a separate farmer-confirmed damage point.'
            : photo.gpsState === 'original_not_offered_by_provider'
              ? 'No accessible photo GPS: this Android provider refused unredacted original access. Set a separate farmer-confirmed damage point.'
            : photo.gpsState === 'original_permission_denied'
              ? 'No accessible photo GPS: Android denied access to this original photo. Set a separate farmer-confirmed damage point.'
          : 'This selected original has no accessible GPS. Set a separate farmer-confirmed damage point.');
    return {
      picked: { uri: photo.uri, width: photo.width, height: photo.height, type: 'image',
        mimeType: photo.mimeType, assetId: null, fileName: null, fileSize: undefined, base64: null, exif: null, duration: null },
      evidence,
    };
  });
}
