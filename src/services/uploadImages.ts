import type { ImagePickerAsset } from 'expo-image-picker';
import { manipulateAsync, SaveFormat } from 'expo-image-manipulator';
import { File } from 'expo-file-system';

export const MAX_ID_IMAGE_BYTES = 5 * 1024 * 1024;
export const MAX_DAMAGE_IMAGE_BYTES = 5 * 1024 * 1024;
export const MAX_DAMAGE_TOTAL_BYTES = 32 * 1024 * 1024;
export const MAX_DAMAGE_PHOTOS = 10;

export type PreparedUploadImage = {
  uri: string;
  fileName: string;
  mimeType: 'image/jpeg';
  size: number;
  width: number;
  height: number;
};

export class UploadImagePreparationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'UploadImagePreparationError';
  }
}

export async function preparePickedImage(
  asset: ImagePickerAsset,
  fileStem: string,
  maximumBytes: number,
): Promise<PreparedUploadImage> {
  if (!asset.uri || asset.type === 'video' || asset.type === 'pairedVideo') {
    throw new UploadImagePreparationError('Choose a valid still image.');
  }

  try {
    const actions = asset.width > 2000 ? [{ resize: { width: 2000 } }] : [];
    const converted = await manipulateAsync(asset.uri, actions, {
      compress: 0.82,
      format: SaveFormat.JPEG,
    });
    const file = new File(converted.uri);
    if (!file.exists || file.size <= 0) {
      throw new UploadImagePreparationError('The selected image could not be prepared.');
    }
    if (file.size > maximumBytes) {
      throw new UploadImagePreparationError(`The prepared image is still larger than ${Math.round(maximumBytes / 1024 / 1024)}MB. Choose a smaller image.`);
    }
    return {
      uri: converted.uri,
      fileName: `${fileStem}.jpg`,
      mimeType: 'image/jpeg',
      size: file.size,
      width: converted.width,
      height: converted.height,
    };
  } catch (error) {
    if (error instanceof UploadImagePreparationError) throw error;
    throw new UploadImagePreparationError('This image format could not be processed. Choose another photo or take a new one.');
  }
}
