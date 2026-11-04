import { requireOptionalNativeModule } from 'expo-modules-core';

type NativeMapsKeyCheck = {
  hasGoogleMapsApiKey?: () => boolean;
  openGoogleMapsPoint?: (latitude: number, longitude: number) => boolean;
};
let nativeModule: NativeMapsKeyCheck | null = null;
try {
  nativeModule = requireOptionalNativeModule<NativeMapsKeyCheck>('AndroidOriginalPhotoPicker');
} catch {
  // An unavailable bridge is always treated as a keyless APK.
}

/** Only a key in the installed Android manifest can safely enable Google MapView. */
export function hasNativeGoogleMapsKey(): boolean {
  try {
    return nativeModule?.hasGoogleMapsApiKey?.() === true;
  } catch {
    return false;
  }
}

export function tryOpenInstalledGoogleMaps(latitude: number, longitude: number): boolean {
  try {
    return nativeModule?.openGoogleMapsPoint?.(latitude, longitude) === true;
  } catch {
    return false;
  }
}
