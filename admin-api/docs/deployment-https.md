# AgriReach public HTTPS deployment

**Status: PENDING.** No hosting provider or permanent domain has been supplied. AgriReach currently runs on this
computer (Apache/XAMPP, `http://localhost/CAPSTONE%20AGRICULTURE`); the farmer app reaches the API through a
temporary Cloudflare quick tunnel (`*.trycloudflare.com`, new random name on every restart, API-only gateway).
Neither is a production deployment. Everything below that can be done without a host is already in place.

## What is ready

| Area | State |
| --- | --- |
| Trusted public address | One setting, `APP_URL` (private config), used for every emailed link. `backend/config/app.php`. No Host-header or localhost fallback. |
| Invitation safety | On a production server (`APP_ENV=production`) invitations are refused unless `APP_URL` is HTTPS on a public host (not localhost, LAN, `.local`, single-label or a quick tunnel). Settings shows the reason; the server refuses before creating anything. |
| HTTPS | HTTPS detection (direct, or `X-Forwarded-Proto` only from `TRUSTED_PROXY_IPS`). Requests to the public host over plain HTTP: admin pages 301 to HTTPS, API answers 403 `HTTPS_REQUIRED` (no redirect, so tokens are never resent over HTTP). HSTS on HTTPS production responses. Local access is not redirected. |
| Admin session | Cookie `AGRIREACH_ADMIN` (no longer the `PHPSESSID` shared with other htdocs apps), path limited to the installation, HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode. Each request re-checks that the admin is still the active admin. |
| Errors | `display_errors` off and `X-Powered-By` removed on production servers (admin and API). |
| Public files | `.htaccess` is deny-by-default: only `admin/`, `api/`, the root redirect and `config/logout.php` are served; no directory listings. `tests/security/apache_public_paths_probe.php` checks it. |
| Secrets | Database password, Gmail App Password (encrypted in the database), the email encryption key and the Supabase CA file live in `C:\xampp\agrireach-secrets\` outside the web root. The mobile app contains only the public API address; no Supabase key, no server secret. |
| Mobile | `eas.json` has `development`, `preview` and `production` profiles. Preview/production builds fail if `EXPO_PUBLIC_API_URL` is missing, HTTP, local, LAN or a tunnel; release builds also refuse such addresses at runtime. |

## What you still need to supply

1. **Where it will run** (one of):
   - a VPS/cloud server with Apache 2.4 (mod_rewrite, `AllowOverride All`), PHP 8.2+ with `pdo_pgsql`, `openssl`, `curl`, `gd`, `mbstring`; or
   - this office computer kept on, published through a **named** Cloudflare Tunnel (Cloudflare account + your domain); quick tunnels are not acceptable for production.
2. **The domain/subdomain**, e.g. `agrireach.example.gov.ph`, with DNS pointing at the host or tunnel.
3. **TLS certificate**: Let's Encrypt (certbot) on a VPS, or Cloudflare's edge certificate for a tunnel.
4. **Whether a proxy/load balancer sits in front** (Cloudflare tunnel, nginx, hosting panel). If yes, its source IP(s) for `TRUSTED_PROXY_IPS`.
5. **A test email address you control**, for the real invitation test.
6. **Expo/EAS account access** and the release Android application id (currently `com.agrireach.agriculture.dev`).

## Configuration values to set once the host exists

`C:\xampp\agrireach-secrets\email.local.php` (or the same keys as environment variables on the server):

```php
return [
    'APP_ENV' => 'production',
    // The public base address where /admin/ and /api/ are served, without a trailing slash.
    // Recommended: point the site's document root at the project folder, so URLs are https://<domain>/admin/...
    'APP_URL' => 'https://<your-domain>',            // or 'https://<your-domain>/<path>' if the project lives in a sub-path
    // Only if a reverse proxy terminates HTTPS in front of PHP:
    // 'TRUSTED_PROXY_IPS' => '<proxy ip>[,<proxy ip>]',
];
```

EAS (mobile):

```
eas env:create --environment production --name EXPO_PUBLIC_API_URL --value https://<your-domain>/api --visibility plaintext
eas env:create --environment preview    --name EXPO_PUBLIC_API_URL --value https://<your-domain>/api --visibility plaintext
eas build --profile preview --platform android
```

Apache virtual host (sketch): `DocumentRoot` = the AgriReach project folder, `AllowOverride All`, `Options -Indexes`,
HTTP vhost redirecting to HTTPS, `ServerTokens Prod`, `expose_php = Off` and `display_errors = Off` in php.ini.
Keep `C:\xampp\agrireach-secrets\` (or its Linux equivalent) outside the document root and readable only by the web
server account. Copy only the project folder to the host: never `CAPSTONE AGRICULTURE.zip`, `frontend/mobile/node_modules`
or the secrets folder.

## Verification after the host exists (all currently PENDING)

1. `php tests/security/apache_public_paths_probe.php https://<your-domain>`: public routes answer, private paths 403/404.
2. `http://<your-domain>/admin/login.php` redirects to HTTPS; `http://<your-domain>/api/auth/me.php` answers 403 `HTTPS_REQUIRED`.
3. Sign in at `https://<your-domain>/admin/login.php`; the cookie must show `Secure; HttpOnly; SameSite=Lax`.
4. Settings → Admin Account: all three checklist items green.
5. Invite your **test address** (never transfer the real admin to test). Open the email on a phone using **mobile data**,
   set the password, confirm the link then shows "already used"; cancel the invitation afterwards. Expiry: an
   invitation not completed within 24 hours shows "expired" (also covered by `tests/admin/admin_replacement_integration.php`).
6. Build the preview app with the production API address and sign in as a test farmer over mobile data.

## Optional Supabase hardening (not required today)

RLS is enabled on all 32 public tables with no policies and no grants to `anon`/`authenticated`, so the Supabase
Data API returns nothing, and PHP (role `postgres`, which bypasses RLS) enforces ownership itself. The project's
*default* privileges would still grant `anon`/`authenticated` rights on tables created in future, and two helper
functions are executable by `anon`. If wanted, after a backup:

```sql
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.agrireach_local_now(), public.agrireach_touch_updated_at() FROM anon, authenticated;
```

## Rollback

No database change was made for this phase. Backups taken before it, with a verified restore:
`C:\xampp\agrireach-private\backups\pre-https-prep-20261103_154213\` (Supabase dump + manifest, MySQL dump, SHA256SUMS).

Code/configuration (the project is not a Git repository):
- Previous versions of `.htaccess`, `frontend/mobile/app.config.js`, `frontend/mobile/eas.json` and
  `frontend/mobile/src/config/api.ts` are in that backup folder under `code-before-https-prep/`; copy them back.
- `C:\xampp\htdocs\.htaccess` (blocks the old project zip): delete the file to undo.
- `backend/config/app.php` is new; the other changes are small blocks marked by comments in
  `frontend/admin/includes/bootstrap.php` (hardening + session cookie block before `session_start()`),
  `backend/api/includes/bootstrap.php` (`agrireach_apply_runtime_hardening()` and the `HTTPS_REQUIRED` check) and
  `backend/lib/admin_replacement.php` (APP_URL functions delegate to `app.php`). Removing those blocks restores the
  previous behaviour; the admin will have to sign in again once either way, because the cookie name changed.

Database restore (only if a later change needs undoing): restore `supabase_public.dump` with `pg_restore` (create the
`extensions` schema with `citext` first on a non-Supabase target), or the MySQL dump with `mysql < agrireach.sql`;
compare with `manifest.json` before switching `default` in `C:\xampp\agrireach-secrets\database.php`.
