# Photo-based damage location: implementation and verification

## Current behavior

- Gallery selection checks each picker result and the selected JPEG's accessible EXIF before JPEG preparation; an original-asset lookup is attempted only when that picker supplies an asset ID. Decimal and DMS GPS, hemisphere references and nested iOS GPS dictionaries are supported. No gallery code calls device GPS.
- Limited-library access is supported when the selected original asset is accessible. Denying full-library metadata permission does not block the system picker. Shared, edited, downloaded, cloud-only or permission-restricted images can have no accessible GPS. No location is invented.
- Original previews appear while processing. The first available selected-photo point becomes a candidate pin before compression/upload completes. Selecting another thumbnail updates the map and clears confirmation; removing the main photo clears the main pin. Photo coordinates are never averaged or modified by editing the main point.
- Multiple photo points more than 1 meter apart produce a distance warning. The farmer chooses the main photo and explicitly confirms **This is the damaged location**. The server checks the city polygon at confirmation and submission. Manual coordinates or explicitly requested current GPS remain separate sources; they do not fill missing photo metadata.
- Every camera invocation handles exactly one capture and starts a fresh foreground location watch. No last-known-location API is used. The watch is bounded to 20 seconds; accepted readings must be within 30 seconds of the camera reference time and accurate to 100 meters or better. Permission denial, services disabled, timeout, stale fix and poor accuracy remain distinct states. A poor fix can be viewed as evidence but cannot be automatically confirmed as the main photo location.
- If the original camera EXIF has an unambiguous timestamp with timezone, it is used. Otherwise the recorded reference is **camera-return time**, when the native camera returns the accepted photo. This is NOT the exact shutter time. Accept the photo promptly and remain at the damage site until its pin appears. Retrying a failed capture-location reading means taking a new photo, not attaching a later GPS reading to an older photo.
- The address/landmark is entered by the farmer. No reverse-geocoded barangay/purok is invented. The existing local Cabadbaran polygon checks city coverage only, not farm boundaries or photo authenticity.
- New submissions require a confirmed damage point and at least one photo. Existing reports, including records with no coordinates or source metadata, remain readable without being backfilled.
- Bearer authentication, farm ownership, private report-image endpoints, farmer isolation, generated upload names and the same `client_submission_id` on retry are retained. Simultaneous duplicate requests converge on one report, image set and notification.

## Database and local setup

Migration: `database/migrations/20261007_report_photo_location.sql` (MariaDB/XAMPP).

It only adds nullable columns:

- `damage_reports`: `location_source`, `location_photo_order`, `location_confirmed_at`.
- `report_images`: `gps_source`, `gps_accuracy_m`, `gps_recorded_at`, `capture_time_source`, `location_status`.

The migration was applied during the prior session and all eight columns were verified present and nullable in this continuation. No additional migration was required. NULL on old records means provenance was not recorded; existing values were not guessed or overwritten. Photo metadata timestamps are stored in UTC. The report's confirmation-recorded timestamp is the server's submission-time record, not an independently verified physical event.

For another local installation, back up the database and import ONLY this additive migration into `agrireach` with phpMyAdmin's SQL/import tab or your existing migration process. Do not reimport the full seed schema just to add these columns. `database/agrireach.sql` also includes them for fresh installations.

Existing limits remain: 10 images/report, 5 MiB/image, 32 MiB total prepared images, JPG/PNG at the API. Gallery HEIC is read for metadata first, then converted using the existing native image preparation; actual device decode support still needs verification.

Local PHP CLI configuration was inspected: `upload_max_filesize=40M`, `post_max_size=40M`, `max_file_uploads=20`. Keep the Apache-loaded PHP settings at least sufficient for the limits (40M post size leaves multipart overhead), with `file_uploads=On` and a writable PHP temporary directory. Restart Apache only if php.ini changes. No php.ini or API base-URL changes were made by this feature.

`expo-media-library` is installed and its native permission plugin is configured. Use the existing development start command (or clear/restart Metro from `frontend/mobile`) and scan the current QR. A custom development/production build must be rebuilt for native permission changes; restarting Metro does not change a native binary. Expo Go uses its own native permissions.

Platform references: [Expo ImagePicker SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/imagepicker/), [MediaLibrary legacy SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/media-library-legacy/). iOS camera EXIF does not guarantee GPS; Android location metadata access and iOS limited access depend on native permissions. Not every gallery image can reveal where it was taken.

## Gallery GPS correction (Oct 8, 2026)

The Android black map was caused by the shared range check accepting `0,0` as an available point. The picker EXIF and original MediaLibrary location are now checked before compression; `0,0`, absent values, malformed EXIF hemispheres, and nonfinite values are not usable GPS. If the original library asset ID is missing, permission is restricted, or the original cannot be read, the form says so and allows an explicit manually confirmed point. A gallery photo never borrows the phone's current location. Camera capture logic is unchanged.

The Android Gallery screenshot identifies a place, but it does not reveal whether that label comes from embedded GPS, a library-edited asset location, or a gallery estimate. We do not have the original `IMG_20261008_033935.jpg` file or phone metadata output, so the provenance of that *specific* label remains unverified. On Android 10+, the installed Expo MediaLibrary native implementation reads original EXIF through `MediaStore.setRequireOriginal`, which needs `ACCESS_MEDIA_LOCATION`. The plugin requests that manifest permission for a custom build; changing `app.json` or restarting Metro cannot add it to Expo Go. Expo Go's actual manifest/permission outcome on the target Android device has not been measured. A real geotagged asset may therefore show “No accessible photo GPS” in Expo Go even though the Gallery has a place label. A development build with this plugin configuration may be needed.

For `IMG_8484`, the supplied iPhone Photos Info screenshot explicitly says “Add a location…”, so that photo has no saved location. The form says “This photo has no saved location” when its original asset is accessible and lacks GPS; if the asset itself cannot be read, it says the original location is inaccessible instead. A *different* geotagged iPhone HEIC still needs physical testing with full/limited Photos access. Tests simulate the SDK's original-asset location but cannot prove HEIC metadata is returned on a phone.

New uploads with `0,0` photo metadata or a `0,0` confirmed point return HTTP 422. Historical records are not rewritten: mobile/admin/PDF displays treat an old `0,0` as unusable evidence. This is a plausibility/range check, not proof that client-provided GPS is authentic. Cabadbaran city-boundary validation of the confirmed damage point remains enforced.

Android retest: restart Expo with a clear cache, select the original `IMG_20261008_033935.jpg`, and check whether it shows a Cabadbaran pin or the explicit inaccessible-GPS message (never `0,0` or a black map). In Android Settings, check photo/media-location permission if offered. Compare with a second photo known to contain GPS EXIF, and test a photo without GPS. If both remain inaccessible in Expo Go, test a rebuilt development client with `ACCESS_MEDIA_LOCATION` enabled. Confirm only the actual damage point, then submit a controlled report and inspect the saved source/coordinates.

iPhone retest: select `IMG_8484` and expect no photo pin; its Photos Info says it has no location. Separately select an original HEIC whose Photos Info actually shows a map, grant selected/full Photos access as prompted, and check that the app displays its coordinates and moves the pin. Deny access and retry; the photo should remain selectable but show an honest inaccessible state. Confirm the actual damage point; the current-device option remains explicitly farmer-confirmed device GPS, not photo GPS. No physical-device test has been performed for this correction.

### Follow-up: normal Android Gallery picker and development build

The failing **Browse original photo library** action and its pre-picker `ExpoMediaLibrary.requestPermissionsAsync` call were removed. **Choose from Gallery** remains the only gallery-selection control; it never requests broad MediaLibrary permission before displaying photos in Expo Go. The iPhone and Take Photo Now routes are unchanged. On Android, if no genuine photo GPS can be read, the form shows **No accessible photo GPS** and offers a separate farmer-selected map pin. The map opens over Cabadbaran only as a starting view, with *no damage marker* until the farmer taps; the point is not photo GPS and must still pass the existing confirmation and Cabadbaran boundary check.

The installed Expo SDK 57 Android ImagePicker normally opens the visual-media picker. Its native `MediaHandler` copies the selected content URI to a cache `file://` URI and returns `assetId` only for recognized MediaStore document URI forms. Its JS result does not expose the original content URI/authority, so a null `assetId` does **not** prove whether the source was a file browser, system photo picker, or a particular gallery provider. At `quality: 1`, its Android exporter copies the selected bytes before conversion. The app examines picker EXIF and that selected JPEG's EXIF *before* `preparePickedImage`; it accepts only valid coordinates with hemisphere references and rejects `0,0`. If no valid GPS is present there and an asset ID exists, it attempts the corresponding original-asset lookup. It never reads a different photo by filename and never borrows current device GPS.

The installed Android MediaLibrary implementation reads original location EXIF through `MediaStore.setRequireOriginal`; on Android 10+ this requires `ACCESS_MEDIA_LOCATION`. The existing `expo-media-library` config plugin in `frontend/mobile/app.json` already has `isAccessMediaLocationEnabled: true` and `granularPermissions: ["photo"]`. That setting only affects a **new native build**, not the installed Expo Go binary. In Expo Go the app never calls the failing MediaLibrary *request* API. In a custom Android build, it requests the declared photo/media-location permissions **after** selection if original lookup is needed and the permissions are not yet granted. An asset ID or original URI still must be available; a development build cannot reconstruct an ID that ImagePicker did not return. If a custom build still returns null ID and stripped cache EXIF, reliable original access would require a native picker path that retains the selected content URI; configuration alone is not a guarantee.

The original `DCIM/Camera/IMG_20261008_033935.jpg` is not in this workspace. Its Gallery place label cannot establish embedded EXIF GPS or that the normal picker exposes it. The exact Expo Go manifest/permission state and a fresh selection result were not measured on the physical Android device. Thus it is **not established** that the normal picker retrieves this original's GPS in Expo Go—or that Expo Go's missing native permission is the sole cause. The new development-only `[AgriReach gallery GPS]` record logs Expo/Android versions, asset ID, selected URI scheme and MIME type, picker EXIF quality, selected-file EXIF quality, original lookup/permission states, and a normalized failure reason. It logs no coordinates, file paths, credentials, or tokens. `not_granted_or_not_in_manifest` is ambiguous until the installed binary's manifest is checked.

Android Expo Go retest: restart Metro with `npx expo start --clear`, open **Choose from Gallery**, select that exact original JPG, then capture its `[AgriReach gallery GPS]` log and confirm whether a genuine pin appears. Test several images, remove/reorder them, and verify each keeps its own metadata. If no GPS is accessible, tap **Set farmer-confirmed pin on map (not photo GPS)**, place the actual damage point, then explicitly confirm it through the server boundary check. Do not label the manually selected point as photo GPS.

For a **separate, opt-in Android development-build test** (not run automatically): install Android Studio/SDK and USB debugging; from `frontend/mobile` run `npx expo install expo-dev-client`, then `npx expo run:android --device`. The existing config plugin requests `ACCESS_MEDIA_LOCATION` and photo-read permission in the new manifest; verify the generated manifest and grant the runtime photo/media-location prompts. Launch the newly installed AgriReach development app—not Expo Go—and repeat the exact **Choose from Gallery** test. The build command changes `package.json`, lockfile, and generated native files, so it has not been run in this pass. [Expo MediaLibrary SDK 57](https://docs.expo.dev/versions/v57.0.0/sdk/media-library-legacy/), [local-build guide](https://docs.expo.dev/guides/local-app-development/).

## Automated checks

Run from the PHP project root:

```powershell
node tests/mobile/photo_location_metadata.cjs
& C:\xampp\php\php.exe tests/backend/report_photo_location_integration.php
& C:\xampp\php\php.exe tests/backend/damage_report_review_integration.php
& C:\xampp\php\php.exe tests/backend/damage_report_assessment_integration.php
git -C frontend/mobile diff --check
Push-Location frontend/mobile
npx tsc --noEmit
Pop-Location
```

The metadata test runs the actual TypeScript parser, location service and multipart builder with mocked native APIs. It covers GPS parsing, missing metadata, original asset/limited access, metadata independence, denial/services/timeout/staleness/poor accuracy, watcher cleanup, ordered multipart fields after deletion, auth and retry identifiers.

The upload integration test uses local Apache at `http://127.0.0.1/CAPSTONE%20AGRICULTURE/api`. It creates two unique controlled `.test` farmers and tests six-photo uploads, mixed GPS sources, conflicting points, validation, private images, cross-account denial, manual confirmation without photo GPS, legacy reports, concurrent duplicate submissions and one notification. It deletes only its own temporary records and generated fixtures in `finally`, and fingerprints existing report/image rows to confirm they are unchanged. No decision emails or real-farmer notifications are sent.

The report/PDF regression is read-only against existing reports and also tests long/missing-data documents in memory. Assessment regression uses a controlled farmer and cleans up its own records. No visual PDF or browser/phone interaction testing was performed in this continuation.

## Physical iPhone and Android checklist — still required

1. With a controlled approved farmer, select a geotagged photo taken somewhere different from your current position. Verify the displayed pin uses the photo location, source and coordinates. Test a HEIC original and limited-library access.
2. Select a screenshot/shared image without metadata. Verify no automatic pin, no device-position substitution, and a clear missing-GPS state.
3. Select several different geotagged photos. Switch thumbnails and remove an earlier item. Confirm each keeps its own coordinates and that the main-photo index is correct after saving.
4. Take a new photo at the actual site. Accept it promptly and remain there while GPS is obtained. Verify accuracy and the explicit camera-return timestamp label when no exact EXIF time is available.
5. Deny camera/location permissions, disable services, and try an indoor low-accuracy or timeout case. Verify the actual reason, retained photo, and retake/manual-location path. Do not pair a later location with an older photo.
6. Confirm a known Cabadbaran point, then an outside-city point. Only the valid point should submit. Any photo/main-point change requires confirmation again.
7. Submit, inspect all images and metadata in mobile and admin, then retry after an uncertain connection response without leaving the form. Verify a single report/notification. Check Account B cannot see Account A's records/images.

These checks have NOT been performed on physical phones. Native permission dialogs, HEIC decoding, capture timing, map gestures/rendering and actual network retry behavior remain device acceptance tests.

## Exact feature file inventory

Preserved from the interrupted implementation (some were completed further in this continuation):

- `frontend/mobile/app.json`
- `frontend/mobile/package.json`
- `frontend/mobile/package-lock.json`
- `frontend/mobile/src/app/reports/new.tsx`
- `frontend/mobile/src/app/reports/[id].tsx`
- `frontend/mobile/src/components/damage-location-map.native.tsx`
- `frontend/mobile/src/components/damage-location-map.tsx`
- `frontend/mobile/src/services/photoLocation.ts`
- `frontend/mobile/src/services/photoLocationMetadata.ts`
- `frontend/mobile/src/services/farmerDataApi.ts`
- `backend/api/location/validate.php`
- `backend/api/reports/index.php`
- `backend/lib/report_photo_location.php`
- `backend/lib/damage_reports.php`
- `frontend/admin/includes/damage_report_pdf.php`
- `frontend/admin/damage-reports.php`
- `database/agrireach.sql`
- `database/migrations/20261007_report_photo_location.sql`

Tests retained and expanded: `tests/mobile/photo_location_metadata.cjs`, `tests/backend/report_photo_location_integration.php`.

Changed in this continuation: the two report screens, `photoLocation.ts`, `photoLocationMetadata.ts`, those two tests, and this document. The nested mobile repository has many unrelated pre-existing changes; they were not reset or committed. The PHP project root has no `.git`, so a historical PHP-root diff is unavailable; root feature files were inspected directly. `git diff --check` applies to the mobile repository's tracked changes, with additional direct checks for untracked feature files.
