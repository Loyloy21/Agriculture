# AgriReach mobile redesign handoff

Implemented in the existing Expo project. No APK build, installation, dependency change, database migration, billing action, Git commit, or tunnel/env change was performed for this redesign.

## Changed files

All paths are relative to C:\xampp\htdocs\CAPSTONE AGRICULTURE.

Mobile screen/UI files:
- mobile/Agriculture/src/components/farm-ui.tsx — forest/sage/ivory palette, existing Expo Symbols icons, shared safe-area header and five tabs, decorative gradient heroes, reduced-motion-aware fade/press animations, statuses.
- mobile/Agriculture/src/components/protected-report-photo.tsx — current authenticated API image source, no shared disk cache, bounded loading, HTTP/session/network error explanations, retry.
- mobile/Agriculture/src/app/farmer-home.tsx — real greeting, photographic hero, independent live weather/advisory states, accurate provider/observation/retrieval labels, reports/farms actions.
- mobile/Agriculture/src/app/my-reports.tsx — real farm/category/date/status rows, actual counts, all status filters, incremental twenty-row display.
- mobile/Agriculture/src/app/weather-advice.tsx — decorative banner, real active farm categories, compact matching admin advisories; no invented priority/weather text.
- mobile/Agriculture/src/app/weather/[id].tsx — consistent detail shell/icons, existing full recommendations retained.
- mobile/Agriculture/src/app/notifications.tsx — actual date groups/unread indicators, successful read-state commits, correct report/advisory destinations.
- mobile/Agriculture/src/app/profile.tsx — decorative banner (not an identity photo), real identity/account, wrapping information, existing edit/language/logout/manage-farms behavior.
- mobile/Agriculture/src/app/my-farms.tsx — decorative banner/shell/palette; existing create/edit/archive/location rules unchanged.
- mobile/Agriculture/src/app/reports/[id].tsx — ordered protected images, fullscreen previous/next viewer, each saved photo's ID/coordinates/source, no borrowed report/farm pin.
- mobile/Agriculture/src/app/reports/new.tsx — details/photos-location/review wizard, cost before description, persistent draft, step-scoped validation, preserved ten-photo limit, source modal, camera/Gallery/GPS handlers and submit payload.
- mobile/Agriculture/src/services/farmerDataApi.ts — optional real farmId/farmName fields on report contract only.

Decorative local assets (converted from supplied PNGs to optimized 1200px JPEGs; originals untouched):
- mobile/Agriculture/assets/images/redesign/home-hero.jpg
- mobile/Agriculture/assets/images/redesign/weather-hero.jpg
- mobile/Agriculture/assets/images/redesign/report-damage-hero.jpg
- mobile/Agriculture/assets/images/redesign/profile-farms-hero.jpg

API/test files:
- api/reports/index.php — additive linked farm name/ID in GET report data. Authentication, confirmation, upload, boundary, image ordering and idempotency unchanged.
- tests/mobile_redesign.cjs — controlled UI harness for wizard/draft/payload/viewer/notification/protected-image behavior.
- tests/gallery_picker_flow.cjs — shared UI import mock added; original picker orchestration assertions retained.
- tests/android_original_picker_contract.cjs — stale configuration-name assertion updated to the existing native Maps-key guard; no crashing "Try embedded map" assertion added.
- tests/farm_management_api_integration.php — test report fixture now explicitly confirms its manual pin, as required by the existing server; verifies real linked farm name/ID.
- mobile/Agriculture/REDESIGN_NOTES.md — this handoff.

## Saved-photo findings

The API returns protected relative photo URLs by photo ID, ordered by display_order then ID. The unchanged endpoint checks the bearer session and report ownership, resolves only private report-image roots, and serves JPEG/PNG with private/no-store headers.

The previous detail UI had no image-loading/error/retry feedback and supplied an empty URI until the session source resolved. Failed requests therefore looked like blank photos.

Read-only audit: all seven existing non-fixture images were found and their image headers decoded. Four missing file references belong to fixture reports; the normal report API excludes those fixture reports. No originals or metadata were replaced.

The current public API URL could not be reached from this laptop during the audit. That is a separate connectivity observation, not evidence of broken image records or a diagnosed Wi-Fi restriction. The central .env.local and gateway/tunnel scripts were left unchanged. Use a healthy verified API tunnel before phone photo/login testing.

Local controlled integration tests successfully delivered owner-protected images and rejected cross-account access. Actual existing-photo display on iPhone/Android remains to be checked with the active public API.

## Preservation checks

Direct before/after source comparison confirmed unchanged implementations of changeLocation, selectPhotoLocation, removePhoto, movePhoto, useMyLocation and addPhoto. The native Android original-picker, iPhone metadata/picker services, platform map components, native Maps-key guard, manual-pin components and Admin saved-photo map were not edited.

Switching photo identity clears confirmation and selects that photo's point; reordering retains identity and coordinates. Explicit manual/device points remain independent from photo metadata. Cabadbaran confirmation still calls the same validation endpoint. Review does not replace the final submit payload or client submission identifier.

The iPhone camera-return timestamp remains labeled as camera-return time, not exact shutter time.

The blue gear is the installed expo-dev-menu Tools button: its native source uses gearshape.fill and its onboarding explicitly calls it the blue gear button. It is not an AgriReach control.

## Checks completed

- TypeScript: npx.cmd tsc --noEmit — passed.
- Expo web export — passed; nineteen existing routes bundled.
- Android and iOS Hermes JavaScript/assets exports — passed. These are bundle checks, NOT an APK build or phone rendering test.
- Git diff --check — passed for tracked mobile changes. Separate no-index whitespace checks included all redesign source/test files, since many pre-existing screens are untracked.
- PHP syntax: api/reports/index.php and changed PHP integration test — passed.
- tests/gallery_picker_flow.cjs — nine mocked iPhone success/cancel/dismissal/picker/metadata/conversion timeout/error cases, plus three Android explicit-point cases passed.
- tests/photo_location_metadata.cjs — GPS parsing/invalid inputs/source isolation/multipart metadata and retry identifier passed.
- tests/android_gallery_expo_go.cjs — native APIs mocked; metadata states/permissions/late results passed.
- tests/android_original_picker_contract.cjs — exact selected-original/permission/no-device-fallback contract passed.
- tests/android_damage_map.cjs — keyless no-mount guard, exact point/marker/satellite configuration/manual coordinates passed; native tiles not tested.
- tests/admin_photo_viewer_map.cjs — existing Admin image ID/pin switching and no-GPS clearing passed.
- tests/mobile_redesign.cjs — mocked iPhone/Android at width inputs 320/390/430, three-step validation/back/review, long draft, photo add/switch/reorder/remove, payload and duplicate-submit lock passed. Ordered fullscreen viewer, notification destinations, authenticated image source/404/401/503/timeout/retry states passed.
- tests/report_photo_location_integration.php — controlled local multipart uploads, ten-photo limit, boundary/source/freshness/accuracy validation, protected delivery, concurrent retry/one notification, legacy data passed. Existing report/photo-row fingerprints unchanged.
- tests/current_weather_api_integration.php — actual authenticated current-weather endpoint/freshness metadata passed.
- tests/weather_advisory_api_integration.php — advisory/notification location/category/date/account isolation passed.
- tests/farm_management_api_integration.php — multi-farm ownership/create/edit/archive, ordered photos, count/size validation and advisory recipient deduplication passed.

An initial sandboxed upload test could not remove its own synthetic uploads. Only its 28 exact verified synthetic files were then cleaned with appropriate permissions; rerunning the suite completed cleanup successfully. No existing reports or farmer images were deleted. Other integration fixtures were also cleaned.

Browser tooling reported no connected browser. Rendered visual QA, actual accessibility font scaling/keyboard/safe-area layout, native picker presentation and phone map tiles are NOT verified by these harnesses.

## Metro versus a future APK

These JSX, styling, local assets, source labels, navigation, wizard and protected-image changes can be delivered through Metro to the existing compatible development APK. No native dependency or configuration was changed here.

Start your verified API tunnel first if necessary, then in mobile/Agriculture:
npx expo start --dev-client --clear --tunnel

Open the current Android development app using that Metro session. Use the established working iPhone setup. Do not install a different APK for this redesign.

A new Android APK with a configured Maps SDK key is still required for embedded satellite tiles when the installed APK lacks that key. The redesign preserves the non-crashing exact-point Google Maps action and never bypasses the native key check. No embedded-map success is claimed.

## Physical retest checklist — pending

On both iPhone and Poco X3 NFC:
1. Check all five tabs at normal and large system text size; verify safe-area/tab clearance, long names/email/addresses, empty/error states and scroll behavior.
2. Verify Home uses actual weather provider, units and observation/retrieval times. Pull to refresh; verify advisory matching.
3. Check report status counts/filters and a real linked farm name. Open a saved report and load its real protected photos; fullscreen previous/next must keep each photo's own pin/source. Retry after a temporary connection failure.
4. Open report and advisory notifications; verify exact destinations and server-backed unread state without creating notifications.
5. Verify Edit Profile, View/Manage Farms, add/edit/archive and logout.
6. Fill report step one, continue to photos/location, go back and forward; values must persist and keyboard must not obscure controls.
7. Use the unchanged camera and Gallery flows. Add photos from different places and one without GPS. Switch/reorder/remove; each photo keeps its own metadata and switching requires reconfirmation.
8. Confirm an allowed Cabadbaran point, inspect review, submit once and verify the saved report/Admin per-photo linkage. Out-of-boundary points must still be rejected.
9. In the current keyless Android APK, ensure no native MapView crash and verify the exact-point Google Maps handoff/return state. A keyed future APK's satellite preview remains a separate future physical test.

No physical-device pass has been reported for this redesign.
