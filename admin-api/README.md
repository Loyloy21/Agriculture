# AgriReach admin website and API

This folder holds the PHP side of AgriReach: the farmer API (`backend/`) and the admin website (`frontend/admin/`). The Expo farmer app is at the root of this repository.

## This folder is a copy

The running system is at `C:\xampp\htdocs\CAPSTONE AGRICULTURE`, which is not itself a Git repository. Make changes there, then copy them here. The folder layout is the same, except that this copy has no `frontend/mobile/`.

Tests in `tests/mobile/` expect the app at `frontend/mobile/`, so run them from the XAMPP project, not from this copy.

## Files that must stay out of this repository

`admin-api/.gitignore` blocks these, but check `git status` before each commit, because the folders below exist next to the source on the server:

- `uploads/` and `storage/` (farmer ID images and report photos; only their `.htaccess` deny files are kept)
- `C:\xampp\agrireach-secrets` and `C:\xampp\agrireach-private` (credentials, farmer IDs and backups)
- `tools/`, `var/`, `vendor/`, `tmp/` and `tests/.artifacts/`
- Database dumps and archives such as `CAPSTONE AGRICULTURE.zip`

## Setup

1. Run `composer install`.
2. Copy `backend/config/database.example.php` and `backend/config/email.example.php` to `C:\xampp\agrireach-secrets\database.php` and `email.local.php`, then fill in your own values.
3. Apply the schema: `database/pgsql/` for PostgreSQL, or `database/agrireach.sql` plus `database/migrations/` for MySQL.
4. Set the admin password. The schema ships without one:

   ```
   php scripts/admin-recovery.php reset-password --username=admin
   ```

`docs/PROJECT_STRUCTURE.md` explains the layout, and `docs/deployment-https.md` covers deployment.
