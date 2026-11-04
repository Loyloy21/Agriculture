<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/backend/config/database.php';

try {
    $settings = db_connection_settings();
    $label = $settings['driver'] === 'pgsql' ? 'PostgreSQL' : 'MySQL';
    db()->query('SELECT 1');
    echo "{$label} connection OK ({$settings['name']})\n";
} catch (Throwable) {
    fwrite(STDERR, 'AgriReach ' . ($label ?? 'database') . " connection failed. Check that the database is running and review the database settings file.\n");
    exit(1);
}
