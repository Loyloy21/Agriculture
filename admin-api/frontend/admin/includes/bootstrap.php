<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/config/app.php';

agrireach_apply_runtime_hardening();
if (PHP_SAPI !== 'cli') {
    // The public host is only ever used over HTTPS on a production server (local access is left alone).
    $httpsUrl = agrireach_https_upgrade_url();
    if ($httpsUrl !== null) {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { header('Location: ' . $httpsUrl, true, 301); exit; }
        http_response_code(400);
        exit('Use the HTTPS address of this site.');
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/**
 * The admin session cookie: its own name (other sites on the same host must never share it), only sent to this
 * installation's path (e.g. /CAPSTONE%20AGRICULTURE/, which also covers the old config/logout.php route),
 * unreadable by scripts, not sent on cross-site requests, and HTTPS-only when the request is HTTPS. Unknown session
 * IDs are rejected (strict mode).
 */
function admin_session_cookie_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    foreach (['/admin/', '/config/'] as $marker) {
        $position = strpos($path, $marker);
        if ($position !== false) return substr($path, 0, $position) . '/';
    }
    return '/';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('AGRIREACH_ADMIN');
    session_set_cookie_params(['lifetime' => 0, 'path' => admin_session_cookie_path(), 'secure' => agrireach_request_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Per-session CSRF token shared by every admin form (each page still verifies it on POST). */
function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/**
 * Whether this session belongs to the active administrator. Checked against the database once per request, so a
 * session of an admin whose access was transferred, deactivated or blocked stops working on its next request.
 * 'inactive' = the account no longer has access; 'unavailable' = the database could not be checked (fails closed).
 */
function admin_session_state(): string
{
    static $state = null;
    if ($state !== null) return $state;
    if (!isset($_SESSION['admin_id'], $_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'admin') return $state = 'none';
    try {
        $statement = db()->prepare("SELECT 1 FROM admins WHERE id = ? AND role = 'admin' AND status = 'active' AND account_status = 'Active' LIMIT 1");
        $statement->execute([(int) $_SESSION['admin_id']]);
        return $state = $statement->fetchColumn() ? 'active' : 'inactive';
    } catch (Throwable $exception) {
        error_log('Admin session check failed: ' . get_class($exception));
        return $state = 'unavailable';
    }
}

function is_admin_logged_in(): bool
{
    return admin_session_state() === 'active';
}

function require_admin(): void
{
    $state = admin_session_state();
    if ($state === 'active') return;
    if ($state === 'inactive') {
        admin_logout();
        header('Location: login.php?ended=1');
        exit;
    }
    header('Location: login.php' . ($state === 'unavailable' ? '?unavailable=1' : ''));
    exit;
}

function current_admin_name(): string
{
    return (string) ($_SESSION['admin_name'] ?? 'Administrator');
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
