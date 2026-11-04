# AgriReach cross-network development (Windows + XAMPP)

From C:\xampp\htdocs\CAPSTONE AGRICULTURE, with Apache and MySQL started in XAMPP:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\start-agrireach-dev.ps1
~~~

Stop:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\stop-agrireach-dev.ps1
~~~

Run the same start command to restart, including after changing laptop Wi-Fi. It stops
only its previously recorded processes, creates a **fresh** API tunnel, updates the
environment, and starts a new Expo Go session. A manually launched Expo/LAN server is
left alone; the managed server selects a free port in 8081–8099.

## What the launcher verifies

1. Apache process and the actual PHP auth/me response on port 80; MySQL process and a
   real AgriReach database connection. A failed check names the service and remedy.
   The launcher does not automatically start/stop XAMPP services.
2. The existing loopback-only restricted API gateway on 127.0.0.1:8090.
   Only its explicit mobile API route whitelist is exposed. Admin, phpMyAdmin, config,
   storage, PHP includes and unrelated projects must return gateway 404 locally and publicly.
3. A newly issued Cloudflare Quick Tunnel using HTTP/2 to the gateway, **not Apache**.
   Public auth/me.php must return 401 with the expected PHP authentication message and
   gateway header. HTTPS certificate verification remains enabled.
4. Only then, EXPO_PUBLIC_API_URL is written to frontend/mobile/.env.local.
   Other environment entries are preserved. Stop or failed startup leaves this variable
   **empty**, not pointing at a dead hostname.
5. A visible console runs npx expo start --clear --tunnel --go --port <free-port>.
   The runner uses the verified API URL even if its parent shell had an old environment
   variable. Metro's cache is cleared. Readiness requires both a public Metro status
   response and a public Expo manifest through the hostname Metro actually advertises.
   A local /status response by itself is not success.
6. The API is checked again before announcing that the session is ready.

Scan the **new QR in the visible Expo console**, not the Cloudflare URL.
The same verified QR is saved temporarily as var/cloudflare-tunnel/expo-qr.png after
the public iOS bundle contains the current API URL. The stop command removes it.
The Expo QR hostname loads the development app. The separate HTTPS Cloudflare hostname
handles PHP API requests, login and protected images. Expo Go's exp:// development link
normally uses HTTP for the advertised Metro bundle; API requests remain HTTPS.

The active API URL is in frontend/mobile/.env.local, ending with
/CAPSTONE%20AGRICULTURE/api. Current verified API/Expo URLs, process identities and diagnostic
logs are in var/cloudflare-tunnel/processes.json and expo-process.json. They are temporary,
local runtime files. Never copy these hostnames into source control or app code.

## DNS recovery and network limits

The launcher tries Windows DNS, then explicitly queries 1.1.1.1 and 8.8.8.8. These are DNS
resolver addresses, **not hardcoded Cloudflare service IPs**. This handles the router's
"Bad DNS packet" failure without changing Windows/router DNS or the hosts file.

Cloudflare's existing loopback provisioning relay connects to a freshly resolved service
address with the real api.trycloudflare.com TLS name and certificate validation. It stops
after public API verification. Newly issued hostnames may briefly return NXDOMAIN; bounded
retries allow DNS propagation. Public probes try normal DNS first, then the current DNS
answers with the original HTTPS Host/SNI. The phone independently resolves its public URLs;
a laptop DNS recovery test does not prove the phone works.

No launcher can guarantee tunnels on every Wi-Fi: captive portals, firewall policies,
provider outages or blocked DNS can prevent them. HTTP/2 requires outbound TCP 7844 to
Cloudflare; Quick Tunnel provisioning uses HTTPS 443. Expo's tunnel has its own network
requirements. No LAN fallback is used. If DNS cannot recover or either public tunnel
fails, startup fails, its owned processes are cleaned up, and the API environment is cleared.

Cloudflare diagnostics: var/cloudflare-tunnel/cloudflared.err.log and
provisioning-proxy.err.log. Look for registration, DNS failures, connection timeouts,
TLS errors and TCP connectivity pre-checks. Expo diagnostics: visible QR console and
var/cloudflare-tunnel/expo.log (PowerShell transcript; native interactive output may
appear only in the console). A Windows curl HTTPS probe can report
CRYPT_E_REVOCATION_OFFLINE if its certificate-revocation server is unreachable. Do not
disable certificate validation to hide it; readiness probes use Metro's actual advertised
transport, not an assumed HTTPS URL.

A failed command prints SESSION NOT RUNNING. Logs remain for diagnosis.
Concurrent start/stop commands are locked. Cleanup checks creation time, executable and
command as well as PID; stale PID numbers never authorize killing unrelated processes.
Recorded Expo descendants can be cleaned up if the QR window dies. Legacy PID-only state
is refused with a remedy instead of being trusted. No Apache/MySQL/service is killed.

## Phone and laptop network changes

- **Only the phone changes Wi-Fi or switches to mobile data:** keep using the public URLs
  while the laptop and both tunnels remain online. No LAN IP lookup or environment edit.
  Reopen Expo Go or scan the current QR if the app connection needs refreshing.
- **Laptop changes Wi-Fi, disconnects, or a tunnel dies:** reconnect the laptop, run the
  same start command and scan its new QR. The launcher replaces the API URL and clears
  Metro so the app does not retain the previous value. Existing installed/running phone
  bundles do not magically learn a replaced API hostname; reload through the new session.
- Keep the laptop awake with Apache, MySQL, gateway, cloudflared and Expo running.
  Quick Tunnel hostnames are temporary, not deployment URLs.

## Tests and mobile-data acceptance

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\dev\dev-launcher-tests.ps1
C:\xampp\php\php.exe .\tests\dev\cloudflare_tunnel_api_integration.php --auth-only
node .\tests\dev\dev-public-metro.mjs
~~~

The first test uses isolated fixtures for DNS failure, stale configuration and PID safety.
The second requires the active verified API state. It uses the real public HTTPS hostname
for unauthenticated auth/me (401), login (200), authenticated auth/me (200), farms and logout.
It creates a random, temporary test farmer/farm/session, then removes only those fixtures;
--auth-only creates no damage report. Credentials/tokens are not printed. DNS answers may
come from the launcher's live recovery; TLS validation stays on. This is public-edge testing
from the laptop, **not a physical-phone or independent mobile-network test**.

The Node smoke test fetches the public Expo manifest and iOS development bundle, then checks
that the actual bundle contains the current verified API URL. It does not change app source.

Physical acceptance, still required after these tests:

1. Leave the laptop session running. On the phone turn Wi-Fi OFF and mobile data ON.
2. Scan the current Expo QR (Android: Expo Go scanner; iPhone: Camera -> open Expo Go).
3. Wait for AgriReach to load, log in with your own approved farmer account, and open My Reports.
4. Open an existing protected photo if available. Do not share your password/token.
5. Report whether app loading, login, report list and photo succeeded; include the exact
   error text if one fails. Phone connectivity is verified only after this test succeeds.

For a nonstandard Apache port, append -ApachePort <port>. -SkipExpo is an API-only
diagnostic option and explicitly does not claim a complete Expo session.
