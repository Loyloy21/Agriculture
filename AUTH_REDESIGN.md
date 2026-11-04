# AgriReach authentication redesign handoff

## Completed UI

Welcome, Login, Forgot Password, and all three Registration steps are implemented. Existing dirty work was inspected and retained; no reset or restart was performed.

The supplied boards guided the warm ivory background, forest-green actions, sage section cards, green/gold leaf branding, scenic welcome/footer, connected numbered registration steps, farm-category cards, and separate ID Front/Back cards. Existing decorative scenery is reused; rolling hills and the leaf mark are code-native shapes, not new network assets.

Intentional differences from the illustrative boards:

- Date of Birth, user-entered password and confirmation, all original address fields, and existing required-field rules remain. These make registration longer than the board; each step scrolls.
- Names stack on narrow screens. Controls and long labels can wrap instead of clipping.
- Main product remains the original editable field, not an invented fixed product list.
- Forgot Password says **Continue**, not **Send reset link**. The existing backend recovery service is not connected. The screen explains that no email will be sent; its existing notice and reset-link route remain. This is a completed visual redesign, not a newly implemented recovery service.

## Files changed in this continuation

- `src/app/index.tsx`: scenic, scrollable Welcome; original Login/Register routes.
- `src/app/login.tsx`: labelled inputs, visibility toggle, actions, approval note and scenic footer.
- `src/app/forgot-password.tsx`: matching recovery presentation and truthful service explanation.
- `src/app/register.tsx`: three-step presentation, keyboard/scroll handling, category controls, ID-side-specific camera/gallery actions.
- `src/components/auth-ui.tsx`: new auth-only palette, icons, branding and decorative scenery.
- `../../tests/auth_redesign.cjs`: actual handler tests with controlled native/API mocks.
- `../../tests/render_auth_redesign.cjs`: offline captures from the actual screen JSX and styles.
- This handoff.

The login submission handler and 19 existing registration validation/submission/location handlers were compared with the starting source and remained identical. The ID picker now accepts an explicit side for direct Front/Back actions, avoiding reliance on previous upload-sheet state; existing picker options, permissions and preparation remain. Validation-error scrolling accounts for the form's layout offset.

All 58 existing mobile source files outside these four screens remained unchanged in this continuation, including authentication services, damage-photo GPS extraction, iPhone damage picker, Android original-media module, report payloads and protected image services. Shared farmer UI, PHP/API, native/build configuration, dependencies and environment settings were not edited. Pre-existing changes in those files were retained.

## Passed checks

- `npx tsc --noEmit` (final source).
- `git diff --check`; both new Node scripts also pass `node --check`.
- `npx expo export --platform all --output-dir ../../tests/.artifacts/auth-export`: Android, iOS and web JS/assets export, including 19 web routes. This is not an APK build or native device test.
- `node tests/auth_redesign.cjs`: all three steps on iOS/Android inputs at 320, 393 and 768 pixels; DOB, exact typed password/confirmation, +63 normalization, independent addresses, explicit home/farm confirmation, separate ID sides, cancellation, back/next draft retention and submission payload; login status routes and truthful recovery notice.
- `php tests/farm_location_registration_integration.php`: controlled local PHP registration records, distinct home/farm addresses and protected Front/Back IDs, phone normalization/duplicate rules, explicit pin validation, outside-city rejection without partial records, and approved login. The existing test cleaned up only its own records/uploads.
- Existing regressions: `gallery_picker_flow.cjs`, `android_original_picker_contract.cjs`, `android_damage_map.cjs`, `mobile_redesign.cjs`, `photo_location_metadata.cjs`, `android_gallery_expo_go.cjs`.

Native APIs in the JavaScript tests are mocked. A passing map/picker contract does not establish native tiles, EXIF access or physical-device behavior.

## Implemented screen captures

[Capture gallery](../../tests/.artifacts/auth-redesign/index.html)

The gallery includes Welcome, Login, Forgot Password, Registration steps 1–3, lower-page views (including DOB/passwords, farm fields and both ID upload cards), and 320-pixel compact views. These are offline renders of the shipping JSX/styles through React Native Web with controlled data and native boundaries, not photographs/screenshots of a running phone. Native map, date-picker, permission, keyboard and camera behavior is not verified by these captures. The private `tests/.artifacts` output is ignored and HTTP-denied.

Visual review found the controls readable and reachable through scrolling, without horizontal clipping in the reviewed normal and compact captures. The extra retained fields are intentionally below the first viewport rather than removed to fit the board.

## Physical-device checks still required

No physical iPhone or Android test was performed in this continuation. Check both devices on the existing app setup:

1. Welcome routes; Login typing/autofill, show/hide, keyboard submit, valid/invalid/pending/rejected outcomes, recovery explanation and Back to login.
2. Step 1 keyboard scrolling through every field; DOB picker; +63 pasted/local number handling; typed password/confirmation retained on Back/Continue. Confirm no field is hidden permanently by the keyboard.
3. Independent Cabadbaran home/farm address selection and map confirmation; remove/edit a home pin; manual address remains available. Native map availability still depends on the existing installed build configuration.
4. Step 3 direct camera/gallery for each ID side, cancellation, replacing/removing an image, full-image preview, permission denial and the existing Passport exception. Confirm Front and Back remain distinct after navigating between steps.
5. One controlled registration through PHP, then authorized admin review of the same applicant's addresses and two ID sides. Do not use illustrative capture data for real submissions.
6. Existing damage Gallery/camera/photo-specific GPS and report submission regression on the actual iPhone and Android setup.

No APK was built or installed, no billing action was taken, and no existing farmer/report/legacy record was deleted.
