// Temporary development diagnostics: only fixed stage names, durations and outcomes.
// Never pass native errors, asset identifiers, paths or coordinates to this logger.
export type GalleryStage = 'sheet_dismiss' | 'picker' | 'media_permission_check' |
  'media_permission_request' | 'media_location_check' | 'selected_file_read' |
  'original_asset_read' | 'metadata' | 'conversion';

export class GalleryTimeoutError extends Error {
  constructor(public readonly stage: GalleryStage) {
    super(stage === 'picker'
      ? 'Gallery selection timed out. Close the system picker if it is open, then retry. If it never opened, reload Expo Go.'
      : stage === 'sheet_dismiss'
        ? 'The photo menu did not finish closing. Please retry or reload Expo Go.'
        : 'The selected photo took too long to prepare. Try a locally downloaded photo or a smaller image.');
    this.name = 'GalleryTimeoutError';
  }
}

export function galleryEvent(event: 'previews_added' | 'form_updated' | 'cancelled' | 'failed' | 'loading_cleared') {
  if (__DEV__) console.info('[AgriReach gallery flow]', { event });
}

/** Bounds the JS wait; native work cannot be cancelled, so late results are ignored. */
export function galleryOperation<T>(stage: GalleryStage, operation: () => Promise<T>, timeoutMs: number): Promise<T> {
  const started = Date.now();
  if (__DEV__) console.info('[AgriReach gallery flow]', { stage, event: 'start' });
  return new Promise<T>((resolve, reject) => {
    let finished = false;
    const finish = (event: 'done' | 'failed' | 'timeout', value?: T, error?: unknown) => {
      if (finished) return;
      finished = true;
      clearTimeout(timer);
      if (__DEV__) console.info('[AgriReach gallery flow]', { stage, event, elapsedMs: Date.now() - started });
      if (event === 'done') resolve(value as T);
      else reject(error);
    };
    const timer = setTimeout(() => finish('timeout', undefined, new GalleryTimeoutError(stage)), timeoutMs);
    // Handles synchronous exceptions as well as rejected or never-settling native promises.
    Promise.resolve().then(operation).then(
      (value) => finish('done', value), (error) => finish('failed', undefined, error),
    );
  });
}
