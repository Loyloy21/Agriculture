# Android Expo Go: photo GPS and damage-point confirmation

No USB, ADB, new binary, new permission request, dependency install or API key is
required for this workflow. The iPhone picker timing, extraction implementation,
native map, permissions and UI remain unchanged. Platform resolution chooses
`galleryPhotoEvidence.android.ts` on Android and a direct export of the existing
`photoLocation.galleryPhotoEvidence` on iPhone. The camera still uses the existing
fresh-capture implementation.

## What automatic Gallery GPS can do

Before app conversion, Android checks:

1. Valid GPS and hemisphere references in the selected picker asset's EXIF.
2. GPS EXIF in the readable selected JPEG bytes (including the picker cache copy).
3. The exact library asset's location, EXIF and readable original JPEG, **only if
   that selection supplies an asset ID and the existing access permits lookup**.

All reads are bounded. A failed optional read cannot reject the photo attachment.
No broad library browser, permission prompt, filename search, current device GPS,
address inference or guessed hemisphere is used. Empty, malformed, missing-reference,
nonfinite and 0,0 GPS remain invalid. Evidence stays with its own photo during reorder.
Logs contain source availability and normalized errors, not coordinates or paths.

Expo Go cannot recover GPS stripped from every accessible representation or invent
a missing original asset ID. Camera location being enabled and a location appearing
in Android Gallery do not prove that the normal picker exposes that location.
The recorded Android attempt supplied no asset ID and unusable GPS references.
For that result the correct outcome remains **No accessible photo GPS**, not an
automatic pin. Automatic extraction for the user's exact original is **not verified**.

## Usable workflow even when embedded tiles are blank

- A prominent **Open in Google Maps** action appears above the embedded map with
  the exact selected latitude/longitude. Opening it never changes coordinates.
- Review the actual site, return, then tap **This is the damaged location**.
  Confirmation still requires the existing Cabadbaran server boundary check, but
  does not require any map-ready/tile-loaded event. Photo/upload requirements remain.
- **Hide embedded map** removes the blank surface without clearing photo or point.
- If Gallery exposes no GPS, choose **Set separate damage location (not photo GPS)**.
  In Google Maps long-press the actual site and copy its coordinates. Paste decimal
  `latitude, longitude`, tap **Apply these farmer-selected coordinates**, then
  **Use this farmer-selected point**. Finally confirm on the report screen.
- The optional manual embedded map starts with no marker. Cabadbaran is only its
  starting view, never a default selected location. No Google Maps return data is
  imported automatically. Manually entered coordinates are never saved as photo GPS.
- Android gallery additions preserve an already selected manual/device point and
  its confirmation. A geotagged new photo still retains its own evidence and can
  be selected explicitly with **Show photo location**.

## Checks and physical retest

Run from the project root:

```powershell
node tests/mobile/android_gallery_expo_go.cjs
node tests/mobile/android_damage_map.cjs
node tests/mobile/gallery_picker_flow.cjs
node tests/mobile/photo_location_metadata.cjs
```

These use native mocks. TypeScript is checked from frontend/mobile with
`npx tsc --noEmit`. They are not physical-device acceptance.

Reload the current Expo Go project. Select one known-geotagged and one no-GPS photo.
Only accessible, valid photo metadata should create a photo pin; otherwise the photo
must remain attachable with a short reason. Test manual entry/confirmation while
tiles remain blank, Open in Google Maps before and after changing points, gallery
add/remove/reorder, and Take Photo Now. Existing iPhone behavior should be unchanged.
No claim is made that embedded tiles or this phone's original-photo GPS are fixed.
