# Android Gallery and map investigation — physical acceptance pending

The working iPhone picker, metadata services, permissions and native map have not
been changed in this investigation. Android resolves `damage-location-map.android.tsx`;
iPhone continues to resolve the existing `damage-location-map.native.tsx`.

## Evidence, not assumptions

An existing Android record in `.expo/dev/logs/start.log` reports Android 12/API 31,
Expo Go 57.0.9, SDK package 57.0.22, no asset ID, a `file` URI, missing GPS references
in picker EXIF **and** selected JPEG EXIF, library permission check failure, and
media-location permission `not_granted_or_not_in_manifest`. The record cannot prove
which named original was selected: filenames/paths are deliberately not logged.

Installed ImagePicker's Android `MediaHandler.kt` returns a cache copy, not the
original content URI. `quality: 1` uses `RawImageExporter` to copy the supplied bytes.
`getMediaStoreAssetId` recognizes only certain document-provider URIs; null is not
proof of absent photo GPS. Original location lookup uses `MediaStore.setRequireOriginal`
in MediaLibrary and requires original-media access. Current JS cannot reconstruct
an original URI from the cache filename. It must not guess by filename or use device GPS.

The USB original has not been found. No original metadata source is conclusively
identified. Copy it unmodified from DCIM/Camera and run from the PHP project root:

```powershell
node scripts/inspect-original-photo.cjs 'C:\path-to-usb-copy\IMG_20261008_033935.jpg'
```

This reads only, prints a SHA-256, GPS validity/reference result, and no coordinates
or full path. A missing EXIF result cannot distinguish MediaStore data from a
Gallery estimate without further device investigation.

## Android map reproduction

The existing map used react-native-maps 1.27.2, its default Google provider, no custom
style, no custom tile URL, and a 240px parent. No map callbacks or native error log
were available. Native Google Maps makes tile requests outside JS fetch; Metro alone
does not provide their HTTP statuses. The standalone Google Maps app is a different
binary/configuration and is not proof that this embedded renderer works.

The Android-only map now has an explicit full-size native surface without rounded
parent clipping, readiness/loaded/layout callbacks and a 15-second rendering warning.
It recenters on the actual point after native readiness. This is diagnostic hardening,
**not a proven fix for black tiles**. No API key or provider replacement was introduced.
`tiles_rendered_callback` does not substitute for visually checking the phone.

1. In Expo Go, take a new camera photo at the actual site. Keep its genuine pin.
2. Tap **Show separate Cabadbaran map diagnostic** (development only). This renders
   a separate known reference, never replacing/confirming the report's point.
3. Compare both maps and collect `[AgriReach Android map]` events: mount, non-zero
   layout, native_ready, tiles_rendered_callback or render_timeout. No coordinates
   or file paths are logged. Test Reload map and the exact-point Google Maps link.
4. With Android platform-tools installed and USB debugging authorized, use
   `adb devices -l`, then inspect `adb logcat` locally while reproducing. Look for
   Google Maps authorization, renderer/EGL/Surface or network errors. Do not post
   raw logcat output: it can contain API keys, coordinates and private paths.
5. Inspect the installed Expo Go manifest/permission grant with
   `adb shell dumpsys package host.exp.exponent` locally, specifically
   `android.permission.ACCESS_MEDIA_LOCATION`. A false JS permission check alone
   cannot distinguish undeclared permission from denial.

## Development build prerequisite (not built or installed here)

Expo Go is a prebuilt binary; app.json cannot add permissions or change its key.
The existing MediaLibrary plugin already enables `isAccessMediaLocationEnabled: true`
and photo granular permission. For a custom Android build, original media access
must be declared **and granted**, and the exact selected original URI must survive
the picker. Merely building the existing picker does not guarantee that last part.
If the original has GPS but the selected copy is redacted and assetId stays null,
a native Android picker bridge retaining the selected content URI and reading
`MediaStore.setRequireOriginal` is needed; no JS parser can recover stripped tags.
That bridge has not been implemented blindly without the original/device evidence.

Build prerequisites: Android SDK/platform-tools, Java, authorized USB phone, a chosen
Android package identifier, and a restricted Maps SDK for Android key for that package
and signing SHA-1. The current project has no Android package or Maps key configured.
Do not enable a billable service without account-owner approval. Keep the key out of
source control and use an Android-only dynamic config/plugin setting. No iOS config
or key is needed for this Android test.

After those prerequisites and any required original-URI bridge are ready:

```powershell
cd frontend/mobile
npx expo install expo-dev-client
npx expo run:android --device
npx expo start --dev-client --clear --tunnel
```

These are pending steps, not commands run in this investigation. They change native
build/dependency state. Open the separate AgriReach development app, not Expo Go.
Compare the exact USB-original GPS result with normal Gallery and native logs.
Then test a new camera photo, multiple photos/remove/reorder, manual confirmation,
and the unchanged Cabadbaran validation. Never relabel manual/device GPS as photo GPS.

Official references:
- https://docs.expo.dev/versions/v57.0.0/sdk/map-view/ (Expo Go needs no project key setup;
  custom Android binaries need their own correctly restricted Maps configuration)
- https://docs.expo.dev/versions/v57.0.0/sdk/media-library-legacy/
- https://developer.android.com/training/data-storage/shared/media#location-info-photos

No physical Android build, native logs, tile reproduction or original-file inspection
has passed yet. Do not mark either issue fixed from automated tests.
