# Registration home/farm pin confirmation — October 11, 2026

## Observed failure and request trace

Both buttons already used `getApiUrl('/location/validate.php')` in the central `src/config/api.ts`. Home sends `POST` JSON `{ latitude, longitude, context: 'residential' }`; farm sends the same fields with `context: 'farm'`. They require no login token; only the endpoint's separate damage context is authenticated. No endpoint/authentication rule was changed.

The starting central API base was:

`https://<temporary-name>.trycloudflare.com/CAPSTONE%20AGRICULTURE/api`

Thus both confirmation requests targeted:

`POST https://<temporary-name>.trycloudflare.com/CAPSTONE%20AGRICULTURE/api/location/validate.php`

The configured budget was 15 seconds. A laptop reproduction of that exact POST failed at DNS in 40 ms, curl error 6, with **no HTTP status or response body** (curl's `000` is not an HTTP status). DNS queries to both 1.1.1.1 and 8.8.8.8 returned NXDOMAIN. No cloudflared process or gateway listener was running at initial inspection. The recorded tunnel log ends with shutdown/unregistration on October 10 at 19:08 Manila time. The state/env still contained that now-dead hostname. This is direct evidence of stale development configuration, not evidence that the current Wi-Fi blocks Cloudflare.

The normal stop script clears the URL; an externally terminated process/laptop session can still leave a previously verified URL on disk. Quick Tunnel URLs are not durable server addresses. A fresh start is required when that session stops, including after changing networks.

This trace establishes the failing **configured request**, not the URL in an inaccessible phone's already-bundled JavaScript. No native phone log or physical-device test was captured in this task. A previously running Metro bundle may still contain the old URL until restarted/reloaded.

## Current verified session

The updated API-only launcher completed successfully and wrote:

`EXPO_PUBLIC_API_URL=https://<temporary-name>.trycloudflare.com/CAPSTONE%20AGRICULTURE/api`

It starts the restricted localhost gateway only, uses a fresh HTTP/2 connector, resolves the issued hostname, checks public PHP `auth/me` HTTP 401 and restricted paths HTTP 404, and now also verifies home/farm boundary POSTs and outside-city rejection **before accepting/writing the URL**. Failure still clears the URL and stops only owned helper processes. Apache/MySQL remain running. No Expo QR was opened by this task; the manual workflow is preserved.

Final ordinary public DNS/HTTPS probes (no pinned IP or DNS override) returned:

| Request context/test | HTTP | Response/body evidence | Elapsed |
| --- | --- | --- | --- |
| Valid controlled home point | 200 | `success: true`, `data.status: inside` | 739 ms |
| Valid controlled farm point | 200 | `success: true`, `data.status: inside` | 106 ms |
| Out-of-city home point | 422 | `PIN_OUTSIDE_CABADBARAN`, home-specific field error | 112 ms |
| Invalid farm coordinate | 422 | `INVALID_PIN`, farm-specific field error | 288 ms |

All public responses included `X-AgriReach-Boundary-Validator: local-geojson-v1` and `X-AgriReach-Gateway: api-only`. Matching gateway logs record PHP status 200/422 for `/location/validate.php`. These tests did not write applicant/location records. Local Apache and gateway probes passed the same tests. The server still labels its stored city geometry as approximate and explicitly reports barangay spatial matching as unsupported; no new barangay/GPS certainty was invented.

## Code changes

- `frontend/mobile/src/services/authApi.ts`: both contexts retain the same central base/route. Separate actionable messages for empty/invalid URL, no HTTP response, 15-second timeout, HTTP 401/403, 404/405, 422 validation, 503 boundary data versus generic service failure, other 5xx/tunnel responses, and malformed/non-API responses. HTML errors are classified by their HTTP status instead of being called unreachable. The timer covers response-body reads too. Development diagnostics include method/context/base/path/status/duration/validator but no coordinates, raw body or personal address. Native fetch often cannot distinguish DNS from other transport failures, so the no-response message truthfully lists possibilities rather than asserting DNS on the phone.
- `frontend/mobile/src/app/register.tsx`: each full-screen map modal has its own `SafeAreaProvider` and top/right/bottom/left safe edges. Header/44px close control and bottom Confirm/Cancel footer are outside a scrollable bounded map/help/error body. The original map provider, GPS starting-point behavior, Cabadbaran rule, fields, and form state remain. Cancel/Close invalidates pending success/error updates; a response arriving after dismissal cannot silently confirm a pin. Home and farm retain independent revisions/drafts/confirmed coordinates and return to their original step.
- `scripts/agrireach-dev-common.ps1`: POST-capable probe uses stdin JSON to avoid Windows stripping quotes; new non-mutating registration endpoint acceptance check.
- `scripts/start-cloudflare-quick-tunnel.ps1`: runs that check locally and through public HTTPS before writing config; explicitly tells the user to restart Metro after a URL change.
- `frontend/mobile/.env.local`: only the central API URL updated by the launcher.
- `tests/mobile/registration_pin.cjs`, `tests/mobile/registration_pin_probe.cjs`: new actual-client/screen tests and read-only live API probe.
- `tests/mobile/auth_redesign.cjs`, `tests/mobile/mobile_redesign.cjs`: test injection/provider mocks only.
- `tests/dev/dev-launcher-tests.ps1`: isolated checks that both contexts pass and HTTP 401/404/503 block acceptance.
- This report.

PHP endpoints/schema/authentication, the restricted gateway allowlist, iPhone damage picker, Android original Gallery EXIF module, and report/photo linkage were not edited. Earlier dirty work was retained.

Modal-local providers follow the [installed Expo SDK 57 safe-area guidance](https://docs.expo.dev/versions/v57.0.0/sdk/safe-area-context/), which calls out providers at modal roots. Native status-bar/gesture behavior still needs phone verification.

## Passed tests

1. Live reachable home and farm pin checks: local PHP, gateway and public HTTPS HTTP 200.
2. Live outside-city rejection and invalid-coordinate validation: HTTP 422 with distinct codes/body.
3. Actual API client tests: empty/invalid URL, expired/unreachable no-response transport, response/header/body timeout, HTTP 401/403/404/405/422/503/530, malformed JSON and non-API HTML.
4. Actual registration screen handlers under iOS/Android mocks: correct own point/context, rejected points not confirmed, canceled pending requests not saved, correct return step, typed passwords and names retained, independent home/farm points, editable barangay/purok and manual fallback.
5. Layout structure: modal-local provider, all safe edges, scrollable body, footer outside the scroll view. This is a code contract, not a native rendered layout acceptance test.
6. Isolated launcher tests and actual updated API-only launcher: pass. Public gateway restrictions remain checked.
7. Final TypeScript; PHP syntax for inspected bootstrap/location/farm routes; mobile Git whitespace check; auth redesign and existing Gallery/original-picker/map/photo metadata/report regressions: pass.

Sandbox restrictions initially blocked outbound Node sockets and process-identity checks; the approved reruns passed. No insecure TLS bypass, system DNS change, LAN fallback or unrelated process termination was used.

## Restart and physical test

For future API starts, from the project root:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-cloudflare-quick-tunnel.ps1
```

The current API-only session is already running. Stop the old Metro terminal with Ctrl+C and restart from `frontend\mobile`. For iPhone Expo Go:

```powershell
npx expo start --go --clear --tunnel
```

For the installed Android development client instead:

```powershell
npx expo start --dev-client --clear --tunnel
```

Do not reuse the old QR/bundle. A changed API URL is bundled into JavaScript; editing `.env.local` does not repair an already loaded phone bundle. Restarting/reloading may discard an in-memory registration draft; map dismissal/confirmation itself preserves it.

Keep the laptop Internet, Apache, MySQL and API tunnel running. Use the matching new Expo QR. On each phone, confirm a deliberate home point, verify the same displayed coordinates and intact personal fields, then confirm a different farm point and review barangay/purok. Try an outside-city point and Cancel; verify no unconfirmed point is adopted. Check title/close, map, long error text and footer around the status bar/home indicator, including small-screen/large-text/rotation use.

**Physical iPhone testing: not performed. Physical Android testing: not performed.** The API/configuration repair and code tests passed; neither phone's final behavior is claimed fixed/verified until that test is completed. No new APK was built or installed.
