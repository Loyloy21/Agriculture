# AgriReach redesign continuation — October 10, 2026

This pass refines the existing implementation; it does not replace authenticated
app data with mockups. No APK was built/installed, native dependencies added,
Google billing enabled, API contract changed, or existing report deleted.

## Initial screen checklist and implementation status

| Screen | Approved element | Initial gap | Changed file | Status / remaining checks |
| --- | --- | --- | --- | --- |
| Shared | Consistent compact hierarchy and accessible controls | Large type/padding, stacked category chips | src/components/farm-ui.tsx | Code/layout complete; native font scaling and safe areas pending |
| Home | Visible agricultural hero, real weather and compact shortcuts | Mostly dark foliage, large greeting/cards, extra farm line | src/app/farmer-home.tsx | Code/layout complete; live provider data/refresh and native image appearance pending |
| My Reports | Compact counts, filters and evidence rows | Tall header/rows, category-only thumbnail | src/app/my-reports.tsx | Code/layout complete; real authenticated thumbnails and filters on phone pending |
| Weather Advice | Scenic banner; recommendation before metadata | Dark sky; prominent technical provenance badge | src/app/weather-advice.tsx | Code/layout complete; real category matching on phone pending |
| Notifications | Short preview, timestamp, unread/tap affordance | Tall previews and repeated navigation text | src/app/notifications.tsx | Code/layout complete; real destinations/unread after tap pending |
| Profile | Compact identity, wrapping info and farm summary | Large name/Edit button, many vertical label/value rows | src/app/profile.tsx | Code/layout complete; native long values, expandable details/edit/language/logout pending |
| Report step 1 | Compact farm selector, calamity/cost/description | Every farm expanded as a tall card | src/app/reports/new.tsx | Code/layout complete; multi-farm selector and keyboard on phone pending |
| Report step 2 | Two immediate photo actions, selected GPS and confirmation | Single add card, repeated paragraphs, editable auto-GPS | src/app/reports/new.tsx | Code/layout complete; native camera/Gallery presentation, photo switching, no-GPS/manual and large text pending |
| Report step 3 | Real draft/photos/confirmed-point review; explicit submit | Tall summary rows | src/app/reports/new.tsx | Code/layout complete; end-to-end phone submission and Admin linkage pending |
| Saved photos | Owner-authenticated photo, own location and clear failures | Must verify blank-photo behavior independently | src/components/protected-report-photo.tsx | Local protected delivery/error/retry tests pass; actual saved-photo display through public API still pending |

The four supplied ZIP backgrounds remain the existing optimized JPEGs; no asset
was replaced. Hero now uses the installed expo-image component with explicit
100% bounds, cover fit and deliberate positioning (Home 75%; others 65%). Compact
minimum height is 136; Home 196. The former blanket dark overlay is removed;
the bottom gradient is lighter. These images are decorative only, never evidence
or a farmer's identity photo.

The floating blue gear is Expo dev-menu's Tools button. Its installed iOS source
uses gearshape.fill; it is not an AgriReach control. No app hiding hack was added.

## Files changed in this continuation only

- src/components/farm-ui.tsx — spacing/type/chips, explicit scenic image bounds.
- src/components/protected-report-photo.tsx — compact protected list thumbnail
  loading/failure variant; detail loading/authentication/retry behavior retained.
- src/app/farmer-home.tsx — tighter hierarchy/shortcuts; actual weather semantics retained.
- src/app/my-reports.tsx — compact list and protected actual thumbnail where available.
- src/app/weather-advice.tsx — recommendation first, compact link and metadata;
  provenance remains in the unchanged advisory details.
- src/app/notifications.tsx — two-line previews; original read/navigation handler retained.
- src/app/profile.tsx — compact account/info/farm rows; more fields expandable;
  original edit/password/language/logout handlers retained.
- src/app/reports/new.tsx — compact selector/progress/review, two direct photo
  actions, horizontal actual-photo strip, expandable GPS/reorder details,
  read-only automatic coordinates and explicitly separate manual/current points.
- ../../tests/mobile_redesign.cjs — direct actions, non-editable photo GPS,
  compact protected failure and existing wizard/source/submit/navigation checks.
- ../../tests/gallery_picker_flow.cjs — retained source-sheet dismissal tests
  accessed through Photo options; existing timeout/failure assertions preserved.
- ../../tests/render_mobile_redesign.cjs — offline screenshots from actual screen
  JSX/styles, with test-only data outside the app.
- REDESIGN_CONTINUATION.md — this checklist/handoff.

## Preservation and checks

Before/after source comparison confirms byte-identical implementations of
changeLocation, selectPhotoLocation, removePhoto, movePhoto,
confirmDamageLocation, useMyLocation, addPhoto, validate and submitReport.

The direct Gallery button calls the existing no-sheet branch. Photo options
retains the old sheet branch and UIKit onDismiss sequencing. iPhone picker options,
metadata and conversion deadlines, Android selected-original extraction,
ACCESS_MEDIA_LOCATION module, camera capture evidence, per-photo source/identity,
boundary confirmation, multipart payload, ten-photo limit and retry identifier
are unchanged. No GPS/service/platform-map/Admin source file was edited.

Passed:

- TypeScript: npx.cmd tsc --noEmit.
- Android/iOS Hermes and web JS/asset exports (nineteen routes), not APK builds.
- gallery_picker_flow.cjs — dismissal, cancellation, picker/metadata/conversion
  errors and deadlines; Android explicitly confirmed point preservation.
- mobile_redesign.cjs — iOS/Android width inputs 320/390/430, draft/back/review,
  photo add/switch/reorder/remove, unchanged payload and duplicate-submit lock,
  own-photo viewer coordinates, notification destination/read isolation,
  protected bearer source/error/timeout/retry/compact failure states.
- photo_location_metadata.cjs, android_gallery_expo_go.cjs,
  android_original_picker_contract.cjs, android_damage_map.cjs,
  admin_photo_viewer_map.cjs — GPS/source/pin/keyless guard regressions.
- report_photo_location_integration.php — real local owner-protected delivery,
  cross-account denial, boundary/count/source/accuracy/idempotency and legacy
  behavior. Existing report/image-row fingerprints unchanged. Only this run's
  synthetic fixtures were removed by the test's cleanup.

No PHP/API/database/network/.env source was changed in this pass.

## Screenshot review

Open ../../tests/.artifacts/redesign-review/index.html for all previews, including
scrolled Profile/settings, step-two confirmation and step-three Submit areas.
Each PNG is 1179x2556, corresponding to 393x852 logical pixels at 3x, matching
the supplied physical screenshot dimensions.

The browser connector had no connected browser. The installed Chrome was used
only to render offline React Native Web markup in a separate workspace test
profile. Screens use the actual screen JSX/styles and explicitly controlled
test data, not production-data screenshots. Native symbol rendering, gradients,
maps, safe areas, keyboard and font scaling may differ. Map areas are explicitly
marked as native-device tests pending; no fake satellite tiles were inserted.
These are layout-review evidence, NOT physical-phone acceptance.

Compared against the supplied boards, this pass corrected the dark/mis-sized
scenery, tall farm choices/progress/summary, stacked chips, technical list badge,
full-width Edit/View More, duplicate notification actions, long metadata blocks
and single hidden-source photo action. Source and actual GPS labels are retained.

## Physical checks still required

On the working iPhone setup and existing compatible Poco development APK:

1. Check Home/Reports/Advice/Alerts/Profile and all three report steps at normal
   and larger text size, especially long names/emails, horizontal chips/photo
   strip, keyboard, safe areas and reduced-motion setting.
2. Use both direct photo buttons. Also check Photo options > Gallery cancellation.
   Native presentation and permission behavior are not proved by JS harnesses.
3. Select original geotagged Android photos from two different places and a
   no-GPS image; take a fresh camera image. Switch/reorder/remove via GPS help.
   Each photo must retain its source/coordinates and switching must clear
   confirmation. A no-GPS photo must never acquire the upload/device/farm point.
4. Confirm a Cabadbaran point, back/forward through the draft, inspect all real
   review values, then submit once. Verify the real saved photos and Admin's own
   photo-ID/order pin linkage. Out-of-boundary points must still be rejected.
5. Open actual protected saved photos over the current public API, fullscreen
   and previous/next, and test retry after a connection failure. Local delivery
   passed; live phone display remains unverified.
6. Check actual unread notification destinations, Edit Profile, More account
   details, Manage/Add Farms, Language and Logout.
7. A keyless Android APK must remain non-crashing and show the exact-point link,
   not a native MapView. Embedded satellite tiles still require a later keyed
   APK and physical test. No native-map success is claimed here.

Reload through your existing Metro workflow; no native rebuild is needed for
these cosmetic/JS changes. Keep the already-working API/tunnel workflow.
