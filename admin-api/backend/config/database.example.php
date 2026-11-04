<?php
// TEMPLATE ONLY - contains no credentials. Copy to C:\xampp\agrireach-secrets\database.php
// (outside htdocs, never committed) and fill in the values from the Supabase Dashboard:
// Project > Connect > "Session pooler" (IPv4-compatible, port 5432).
//
// 'default' selects the connection the app uses. Set it to 'mysql' to roll back to XAMPP MySQL.
return [
    'default' => 'mysql',
    'connections' => [
        'supabase' => [
            'driver' => 'pgsql',
            'host' => 'aws-0-REGION.pooler.supabase.com', // Session pooler host from the Dashboard
            'port' => 5432,                                // Session pooler port (not 6543)
            'database' => 'postgres',
            'username' => 'postgres.PROJECT_REF',          // pooler user includes the project ref
            'password' => 'DATABASE_PASSWORD',
            'sslmode' => 'verify-full',
            // Dashboard > Project Settings > Database > SSL Configuration > Download certificate
            'sslrootcert' => 'C:/xampp/agrireach-secrets/supabase-ca.crt',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'agrireach',
            'username' => 'root',
            'password' => '',
        ],
    ],
];
