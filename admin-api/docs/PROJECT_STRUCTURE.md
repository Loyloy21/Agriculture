# AgriReach project structure

One PHP backend serves two front ends: the admin website and the Expo farmer app.

```
CAPSTONE AGRICULTURE/
├── backend/
│   ├── api/              mobile JSON API routes (public as /CAPSTONE AGRICULTURE/api/...)
│   │   └── includes/     private: API bootstrap (JSON responses) and farmer bearer-token auth
│   ├── config/           private: paths, database connection (MySQL or PostgreSQL), mail settings loader
│   ├── lib/              private: domain logic and data access (reports, farmers, weather, email, uploads)
│   └── data/             private: Cabadbaran boundary GeoJSON
├── frontend/
│   ├── admin/            admin website (public as /CAPSTONE AGRICULTURE/admin/...)
│   │   ├── includes/     private: session/auth bootstrap, layout partials, pagination, PDF templates
│   │   └── assets/       CSS, JS, images
│   └── mobile/           Expo farmer app: its own Git repository (never served by Apache)
├── database/             private: MySQL schema and migrations; pgsql/ for the Supabase migration
├── scripts/              private: CLI tools (database checks, pgsql migrate/transfer/verify, dev tunnel)
├── storage/report-images private: damage report photos (served only by the image routes)
├── uploads/              private: legacy photo folders (read-only fallback)
├── tests/                private: backend/ admin/ mobile/ dev/ support/ (support/router.php: see below)
├── docs/                 private: documentation
├── .htaccess             public URL routing (see below)
├── config/logout.php     compatibility route (old admin logout URL)
├── assets/.htaccess      compatibility redirect (old /assets/... URLs → /admin/assets/...)
└── index.php             redirects to the admin login
```

Outside the web root (`C:\xampp\`):

- `agrireach-secrets\`: `database.php`, `email.local.php`, `email.key`, `supabase-ca.crt`. Never in Git.
- `agrireach-private\farmer-ids\`: farmer ID photos.

## Public URLs and routing

The public URLs did not change when the code moved into `backend/` and `frontend/`:

| Public URL | Served from |
|---|---|
| `/CAPSTONE AGRICULTURE/api/...` | `backend/api/...` |
| `/CAPSTONE AGRICULTURE/admin/...` | `frontend/admin/...` |

- The project `.htaccess` maps them with internal Apache rewrites. The method, body, query string and
  `Authorization` header pass through unchanged.
- Direct requests to `/backend/...` or `/frontend/...` answer 404, and private folders answer 403.
  `frontend/mobile` is never served.
- `backend/.htaccess` and `frontend/.htaccess` deny everything. `backend/api/.htaccess` and
  `frontend/admin/.htaccess` re-allow only those two folders (directory listings off), and their
  `includes/` folders deny again.
- PHP's built-in server ignores `.htaccess`, so tests and rehearsals use the same rules from
  `tests/support/router.php`:
  `php -S 127.0.0.1:8091 -t C:\xampp\htdocs "C:\xampp\htdocs\CAPSTONE AGRICULTURE\tests\support\router.php"`

## Rules of thumb

- A new mobile endpoint goes in `backend/api/<area>/<name>.php`, and must also be added to the allowlist in
  `scripts/agrireach-api-gateway.mjs` before the tunnel will expose it.
- A new admin page goes in `frontend/admin/`, starts with `require_once __DIR__ . '/includes/bootstrap.php'` and
  `require_admin()`, and gets its data from `backend/lib/` functions rather than inline SQL.
- File locations come from `backend/config/paths.php` and `backend/lib/private_uploads.php`. Stored database
  image paths are never rewritten; files are found by name.
- The mobile app (`frontend/mobile`) only knows `EXPO_PUBLIC_API_URL`. Database credentials never go in the app.
- Start Expo with `start-agrireach-dev.ps1`, or run `npx expo start` inside `frontend/mobile`.
