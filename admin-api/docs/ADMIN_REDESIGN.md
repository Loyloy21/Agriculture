# AgriReach admin redesign — implementation and verification

## Delivered

The eight existing admin routes now share a forest-green/sage/off-white municipal design. The existing authenticated portal, sidebar routes, account rules, CSRF checks, report ownership, protected images, and location validation remain in use. Reference artwork is visual guidance only; application content and charts come from database queries.

| Area | Implementation |
| --- | --- |
| Dashboard | Live approved/pending/received/effective-advisory KPIs, six-month or thirty-day submission trend, oldest pending work, recent reports, active advice and Google Maps previews of valid saved in-city report points. No fabricated percentage changes. |
| Farmer Verification | Scrollable fixed-header review, personal/home/farm/ID/decision/email sections and all linked active/archived farms. Existing pending-only approval/rejection and full-size protected ID viewer retained. |
| Manage Users | Approved farmers plus existing admin account rules, server-side ten-row paging/search/filters, three-dot menu, scrollable details/edit/add forms and all linked farms. |
| Damage Reports | Ten-row paging; fixed-header scrollable review with Previous/Next, farmer/farm summaries, original estimate and saved assessment before long description, protected featured photo/gallery beside Google road/satellite map, exact saved photo ID/order/source linkage, separate main-report/farm point actions, editable authorized assessments, collapsed technical checks and protected PDF export. |
| Weather Advisory | Compact list/forms, explicit city/barangay/date/category/status/eligibility, exact advisory links and existing legacy-location review. No automatic assignment of out-of-city legacy addresses. |
| Email Settings | Sender/templates/history layout, SMTP acceptance wording instead of inbox-delivery claims, removed one accidentally appended password-like token from the existing approval template and added a validation guard. No credentials printed or changed. |
| Notifications | Grouped readable feed, compact actions, exact report/advisory links, separate admin read/archive state and independently displayed farmer read state. Archiving here never deletes a farmer notice. |
| Settings | Compact profile/password/preferences/system information forms with unchanged account authorization. |

### Maps and evidence

- Dashboard and damage review/full-size photo viewer use Google web iframe previews and exact-point Google Maps links. Road and satellite imagery with a pin visibly rendered in an isolated desktop Chrome test at the controlled saved photo point.
- Each selection uses the selected saved image ID. A different photo gets its own saved coordinates/source; missing or invalid GPS removes the previous iframe and link. The farm point and main report point are separate explicit reference views, never replacements for missing photo GPS.
- Dashboard map activity is a selectable point-at-a-time view, not a multi-marker Google SDK map. Only saved points inside the existing approximate Cabadbaran boundary enter it. Fixtures, invalid/missing coordinates, outside-city and unavailable boundary checks are counted separately and retained in the database. This is not a cadastral boundary or authenticity check.
- Verification's existing home/farm map provider is unchanged. Changing it to Google was paused by the safety review because it sends residential/farm coordinates to a new provider. An explicit approval question is outstanding; this does not block the dashboard/report Google maps.
- No Maps SDK key, billing activation, native app build or APK installation occurred. The Android native Maps SDK issue is not claimed fixed by this PHP redesign.

### Corresponding farmer API results

- A resolved assessment now includes the saved final amount in the existing `adminResponse` field displayed by the current mobile report screen, plus additive `finalAssessedCost`/`assessmentRemarks` fields. Farmer estimate is unchanged. Draft amounts/remarks are not published through these fields.
- Resolution notifications identify the saved final amount and remarks. Repeated resolution remains rejected and produces no duplicate notification.
- Advisory Notifications now match **all** active verified farms, like Weather Advice and the admin recipient rules, rather than only the primary farm. Authentication and per-farmer record ownership checks are unchanged.
- All 62 mobile `src` files matched their pre-edit SHA-256 hashes. No iPhone picker, Android original-photo module, camera, map component, permission or mobile UI file was edited.

## Files changed

Shared presentation:

- `frontend/admin/assets/css/admin-redesign.css` (new)
- `frontend/admin/assets/images/admin-leaf.svg` (new)
- `frontend/admin/assets/js/admin-dashboard.js`, `frontend/admin/assets/js/admin-farms.js` (new)
- `frontend/admin/assets/js/admin.js`
- `frontend/admin/includes/header.php`, `frontend/admin/includes/sidebar.php`
- `frontend/admin/login.php`

Pages and data logic:

- `frontend/admin/dashboard.php`, `frontend/admin/farmer-verification.php`, `frontend/admin/manage-users.php`
- `frontend/admin/damage-reports.php`, `frontend/admin/damage-reports-pdf.php`
- `frontend/admin/weather-advisory.php`, `frontend/admin/email-settings.php`, `frontend/admin/notifications.php`
- `backend/lib/admin_dashboard.php`, `backend/lib/admin_farms.php`, `backend/lib/admin_notifications.php` (new)
- `backend/lib/damage_report_assessment.php`, `frontend/admin/includes/damage_report_pdf.php`, `backend/lib/email_settings.php`
- `backend/api/reports/index.php`, `backend/api/notifications/index.php`

Database/QA protection:

- `database/admin-notification-state.sql` (new additive migration)
- `database/agrireach.sql` (same new table added to fresh-install schema)
- `.gitignore`, `tests/.htaccess`, `tmp/pdfs/.htaccess`

Regression harnesses:

- `tests/admin/admin_redesign_fixture.php`, `tests/admin/admin_redesign_integration.cjs`
- `tests/admin/admin_browser_review.cjs`, `tests/admin/admin_redesign_rules.php`
- `tests/admin/admin_google_maps.cjs`, `tests/admin/admin_photo_viewer_map.cjs`

## Database deployment

The additive `admin_notification_state` migration was applied to the existing local database. It has administrator/notification foreign keys and a composite primary key. Existing farmer `notifications.is_read` values were not rewritten.

For another existing deployment, select its AgriReach database and run **only** `database/admin-notification-state.sql`. Do not re-import the entire fresh-install schema over existing records just for this change.

Existing reports were not edited or deleted for the redesign. Controlled QA rows/files were removed by their unique IDs/markers. One notification left by an earlier iteration of this QA harness was also specifically removed; no legacy notification was deleted.

## Checks passed

- PHP syntax: 71 project PHP files; all eight authenticated pages and their rendered inline JavaScript parse.
- Mobile: `npx.cmd tsc --noEmit`; source-hash equality; existing iOS Gallery stage/error/timeout tests, Android original-picker contract, Android keyless-map/manual-point guard tests, mobile wizard/photo switching/removal/ordering/private-image/notification regression tests.
- Real localhost HTTP actions using normal controlled logins: user edit reflected in authenticated farmer profile, block/revocation/unblock, admin read/archive isolated from farmer read status, individual notification to its intended farmer, assessment invalid-CSRF rejection/save/resolve/repeat rejection, correct owner's saved final report/notification/photo results, isolated secondary-farm advisory publish/archive/repeated-publish notification guard, and controlled admin profile/preferences/password changes.
- Existing integration suites: approved-only users, pending/rejected/blocked authentication rules, verification approve/reject helpers, protected ID front/back authentication/bytes; stable ten-row user/verification/report pagination and filters; advisory eligibility/status/dates/category/location; assessment audits/resolution; authenticated multi-photo upload, exact image IDs/sources, cross-account denial, 0,0/range/boundary validation, ten-photo/manual-pin limits, concurrent idempotency, and unchanged pre-existing report/image fingerprints.
- Email template validation and save/read tested inside a rolled-back transaction. Production template content after the test equals the pre-test content. No SMTP message was sent by these redesign checks.
- Isolated hidden Chrome: all eight pages at 1440px and 820px; no page-wide horizontal overflow; real interactive framework loaded; scrollable review/add/sender modals and fixed headers; all linked farms; selected-photo/frame/link changes; protected full-size photo navigation and no-GPS clearing; no uncaught page JavaScript exceptions.
- Google report road and satellite tiles and the pin were visually inspected. Google resource-failure capture was empty for that controlled desktop session. Merely receiving iframe `load` was not treated as map-render acceptance.
- Saved-assessment A4 PDF with three controlled JPEG evidence images was generated via the authenticated endpoint, rendered with project-local Poppler, and visually checked. Existing real-report short/long PDFs and filtered multi-page PDF layout tests also pass.
- QA screenshots/PDFs are denied over HTTP (403), not exposed as public report images. The test browser used a new isolated profile, not the user's browser session.

Useful repeat commands from the project root:

```powershell
node tests/admin/admin_redesign_integration.cjs --browser
& C:\xampp\php\php.exe tests/admin/admin_redesign_rules.php
& C:\xampp\php\php.exe tests/backend/report_photo_location_integration.php
cd frontend\mobile
npx.cmd tsc --noEmit
```

The browser test requires installed Chrome. Its JPEG test-fixture generator enables the already-installed GD DLL **only for that CLI process**. It does not change Apache configuration. QA artifacts/dependencies are under ignored, HTTP-denied `tests/.artifacts`.

## Remaining limitations and acceptance

1. **Apache PHP GD:** Apache's current PHP configuration has `;extension=gd`. A transparent-PNG evidence PDF failed with the exact GD dependency error; JPEG evidence and existing real-report PDF checks pass. The endpoint now reports the remedy instead of a generic failure. To support that PNG case, enable `extension=gd` in `C:\xampp\php\php.ini`, restart Apache at an approved time, and retest PNG export. Neither PHP configuration nor Apache services were changed in this phase.
2. **SMTP/inbox:** Sender/retry/test-email actions and confirmed inbox delivery were not exercised against the real configured Gmail account. The portal distinguishes SMTP acceptance from inbox delivery. Verification decision helper tests do not constitute an SMTP delivery test.
3. **Verification map provider:** Existing home/farm previews remain as before pending permission for sending those coordinates to Google.
4. **Physical phones:** No iPhone/Poco test was performed. Existing source hashes and automated regressions are not physical-device acceptance.
5. **Controlled action coverage:** No bulk notification was sent to real farmers, no real farmer was approved/rejected/blocked, no sender credential was replaced, and no legacy location was assigned a new barangay. Advisory QA uses currently unused category/barangay coverage to avoid notifying existing farmers. Review/add modals were tested without submitting a real new farmer.

### Physical-phone follow-up

Use a clearly identified QA farmer/report, not an existing real farmer's report:

1. On iPhone, confirm the unchanged Gallery/camera flow and multiple photos; submit a controlled report with an explicitly confirmed valid damage point.
2. On Poco, select two geotagged photos taken at different places plus one without GPS. Verify source labels and that upload location is not substituted. Native map testing still depends on the already-existing Android build/key configuration.
3. In admin review, click each photo and verify its own saved ID/order/source and Google pin/link; the no-GPS photo must clear the previous pin/link. Compare farm/main report only through the separate actions.
4. Save/resolve the controlled assessment once. Refresh that farmer's My Reports and Notifications: original estimate stays unchanged, saved final amount/remarks/status appear, no duplicate resolution notice.
5. Read/archive the notice in admin, then check the farmer's unread state is unchanged.
6. Publish an isolated eligible advisory and confirm its exact category/coverage/dates in Weather Advice and Notifications; archive it and confirm it disappears from current advice.
7. Export the saved final PDF and compare costs, remarks and photo evidence; test transparent PNG after GD is enabled.

No commit, native build or physical-phone verification is implied by this handoff.
