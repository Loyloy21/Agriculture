# AgriReach: MySQL → Supabase PostgreSQL migration runbook

The PHP admin site and mobile API keep their URLs, login, permissions and JSON shapes. Only the
database changes. Supabase Auth, Storage and the Data API are **not** used.

## How the switch works

- `backend/config/database.php` returns a **PDO** connection (`pdo_pgsql` or `pdo_mysql`). Which one comes from
  a settings file **outside the web root**: `C:\xampp\agrireach-secrets\database.php` (override with
  `AGRIREACH_DB_CONFIG_FILE`). The format is in `backend/config/database.example.php`, which holds placeholders only.
- `'default' => 'mysql'` keeps XAMPP MySQL; `'default' => 'supabase'` is the cutover. Changing it takes
  effect on the next request. With no settings file, the original local MySQL settings are used.
- The SQL is written to run on both drivers. The few dialect differences (upsert, `INSERT IGNORE`,
  UTC "now", `GROUP_CONCAT`, `DATE_FORMAT`) live in small helpers in `backend/config/database.php`.
- Uploaded ID and damage-report photos stay on this server. Database rows keep their existing paths, and
  the same PHP endpoints serve them only to authenticated owners and admins.

## One-time Supabase setup (Dashboard)

1. Create the project in **Southeast Asia (Singapore)**. Save the database password in a password manager.
2. **Connect → Session pooler** (port **5432**). This machine has no IPv6, so the direct host
   (`db.<ref>.supabase.co`, IPv6-only) will not work. Do not use the transaction pooler (6543).
   Copy: host (`aws-?-ap-southeast-1.pooler.supabase.com`), database `postgres`, user `postgres.<project-ref>`.
3. **Project Settings → Database → SSL Configuration → Download certificate**. Save it as
   `C:\xampp\agrireach-secrets\supabase-ca.crt`. TLS uses `sslmode=verify-full` with this CA.
   (XAMPP's libpq 11 cannot use `sslrootcert=system`.)
4. Optional hardening: **Project Settings → API** → disable the Data API (the app never uses it), and
   **Database → Network restrictions** → allow only this server's public IP. The schema already enables
   RLS on every table and revokes `anon`/`authenticated` access.

## Local setup

1. `php.ini`: `extension=pdo_pgsql` is enabled. Restart Apache (XAMPP Control Panel → Apache → Stop/Start)
   so the web server loads it. The CLI already has it.
2. Copy `backend/config/database.example.php` to `C:\xampp\agrireach-secrets\database.php` and fill in the
   `supabase` entry. **Leave `'default' => 'mysql'`** until verification passes.
3. Check the connection: `$env:AGRIREACH_DB_CONNECTION='supabase'; php scripts/check-database.php`

## Migration commands (PowerShell, project root)

```powershell
$env:AGRIREACH_DB_CONNECTION = $null      # scripts take explicit --connection / --source / --target
php scripts/pgsql-migrate.php  --connection=supabase           # creates the schema (refuses a non-empty DB)
# Freeze writes: stop the mobile tunnel (stop-agrireach-dev.ps1) and keep admins out of the site.
php scripts/pgsql-transfer.php --source=mysql --target=supabase # one transaction; MySQL is only read
php scripts/pgsql-verify.php   --source=mysql --target=supabase # must end with ALL DATABASE CHECKS PASSED
```

`pgsql-transfer.php` copies every row with its original ID and wall-clock timestamp. It fills
self-references in a second pass, resets each identity sequence to MySQL's `AUTO_INCREMENT`, and
commits only if every table's row count **and full-content SHA-256** match MySQL. `--replace` empties
the target first; use it only to re-run a rehearsal before cutover.

## Rehearsal against Supabase (before switching)

Start an isolated server that uses Supabase while Apache stays on MySQL, with email forced off:

```powershell
$env:AGRIREACH_DB_CONNECTION='supabase'; $env:APP_ENV='development'; $env:MAIL_TRANSPORT='log'
$env:AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE='C:\nonexistent\email.key'
php -S 127.0.0.1:8091 -t C:\xampp\htdocs "C:\xampp\htdocs\CAPSTONE AGRICULTURE\tests\support\router.php"
# second terminal (same AGRIREACH_DB_CONNECTION):
$env:AGRIREACH_TEST_BASE_URL='http://127.0.0.1:8091'; $env:AGRIREACH_TEST_EMAIL_DISABLED='1'
php tests/backend/database_cutover_flows_integration.php
```

That test creates only a `.test` admin and `.test` farmers plus a Draft advisory, and removes them by ID.
Do **not** run the older advisory/fixture suites against real migrated data; use a rehearsal copy for those.

## Cutover

1. Writes still frozen; `pgsql-verify.php` passed.
2. Set `'default' => 'supabase'` in `C:\xampp\agrireach-secrets\database.php`.
3. `php scripts/check-database.php` should print `PostgreSQL connection OK (supabase)`. Log in to the admin
   site, then log in on the phone app; restart the tunnel.

## Rollback

- **Before any new writes reach Supabase:** set `'default' => 'mysql'`. MySQL is unchanged.
- **After writes reached Supabase:** those rows exist only in Supabase. Switching back loses them unless
  they are re-entered or copied back by hand. Keep the freeze short and decide quickly.
- **Restore the MySQL database from the backup:**
  `C:\xampp\mysql\bin\mysql.exe -uroot < C:\xampp\agrireach-private\backups\agrireach_mysql_20261016_132738.sql`
  (recreates every `agrireach` table from the dump; SHA-256 `71088689…a93dc0`).
- **Restore the pre-migration code:** extract
  `C:\xampp\agrireach-private\backups\code_snapshot_pre_pgsql_20261016_132859.zip` over the project, and
  restore `php.ini.pre_pgsql_*` from the same folder if needed.

## Behavior notes (MySQL → PostgreSQL)

| MySQL behavior | How it is preserved |
| --- | --- |
| `utf8mb4_general_ci` case-insensitive email/username | `citext` columns; the connection sets `search_path = public, extensions` (without it, citext silently compares case-sensitively) |
| Case-insensitive `LIKE` searches | `LOWER(col) LIKE LOWER(?)` |
| Session `time_zone +08:00`, `TIMESTAMP` read as Manila time | `timestamp(0)` columns hold the same wall-clock strings; connection runs `SET TIME ZONE 'Asia/Manila'` |
| `ON UPDATE CURRENT_TIMESTAMP` | `agrireach_touch_updated_at()` trigger (only when the row really changed) |
| `affected_rows` = changed rows | PostgreSQL counts matched rows; the one message that depended on it ("No profile changes were made.") compares values explicitly |
| `ORDER BY x DESC` puts NULLs last | explicit `x IS NULL` tiebreaker where the column is nullable |
| Non-strict mode: `''` into DATE/numeric, silent truncation | the app now sends NULL for blank dates/coordinates; all inserts were audited against NOT NULL columns |
| Accent-insensitive matching (`pena` finds `Peña`) | **not** reproduced: search is case-insensitive only |
| Sorting of text (collation) | may differ slightly for mixed case or accented names |
