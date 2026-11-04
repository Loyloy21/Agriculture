<?php
declare(strict_types=1);

/**
 * Filesystem locations used by the backend, defined once so that moving a PHP file can never
 * silently change where data or secrets live.
 *
 *   C:\xampp\                                   agrireach_server_root()
 *   ├── agrireach-secrets\                      database.php, email.local.php, email.key, supabase-ca.crt
 *   ├── agrireach-private\farmer-ids\           farmer ID photos (outside the web root)
 *   └── htdocs\CAPSTONE AGRICULTURE\            agrireach_root()
 *       ├── storage\report-images\              damage report photos (web access denied)
 *       └── uploads\ids, uploads\reports\       legacy uploads (web access denied)
 */
function agrireach_root(): string
{
    return dirname(__DIR__, 2);
}

/** Folder that contains htdocs (C:\xampp on XAMPP). Secrets and private files live beside htdocs, never inside it. */
function agrireach_server_root(): string
{
    return dirname(agrireach_root(), 2);
}

function agrireach_secrets_dir(): string
{
    return agrireach_server_root() . DIRECTORY_SEPARATOR . 'agrireach-secrets';
}

function agrireach_private_dir(): string
{
    return agrireach_server_root() . DIRECTORY_SEPARATOR . 'agrireach-private';
}
