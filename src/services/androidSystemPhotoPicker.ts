import type { ImagePickerAsset } from 'expo-image-picker';
import type { PhotoEvidence } from './photoLocationMetadata';
export function androidOriginalPickerAvailable() { return false; }
export async function pickAndroidOriginalPhotos(_limit: number): Promise<{ picked: ImagePickerAsset; evidence: PhotoEvidence }[]> {
  throw new Error('The original-photo picker is Android-only.');
}
