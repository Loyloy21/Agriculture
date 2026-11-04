<?php
declare(strict_types=1);

return [
    // Keep development mode until test delivery is confirmed. Farmer decision
    // emails are redirected to MAIL_TEST_RECIPIENT outside production.
    // Set APP_ENV to production only when live farmer delivery is authorized.
    // On deployment, prefer setting APP_ENV in the server environment and keep
    // email.local.php out of source control.
    'APP_ENV' => 'development',
    'MAIL_TRANSPORT' => 'log',
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => '587',
    'SMTP_ENCRYPTION' => 'tls',
    'SMTP_AUTH' => 'true',
    'SMTP_USERNAME' => 'your-smtp-username',
    'SMTP_PASSWORD' => 'your-smtp-password',
    'SMTP_FROM_ADDRESS' => 'no-reply@example.com',
    'SMTP_FROM_NAME' => 'AgriReach',
    'SMTP_TIMEOUT' => '10',
    'MAIL_TEST_RECIPIENT' => 'your-test-address@example.com',
];

// Gmail App Passwords saved in the admin UI are encrypted in MySQL. Configure
// AGRIREACH_EMAIL_ENCRYPTION_KEY or AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE in the
// Apache/PHP environment. Never put the encryption key in this project.
