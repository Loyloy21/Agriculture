<?php
declare(strict_types=1);

require_once __DIR__ . '/email.php';

/*
 * Deployment settings shared by the admin website and the PHP API.
 *
 * APP_URL is the ONE trusted public base address of this AgriReach installation, including its deployment path
 * (for example https://agrireach.example.gov.ph or https://example.org/agrireach). Every link sent to people outside
 * this computer (admin invitations, the admin-transfer notice) is built from it, never from the request's Host
 * header and never by falling back to localhost. It lives with the other private settings outside the web root:
 *   C:\xampp\agrireach-secrets\email.local.php  ('APP_URL' => '...'), or the APP_URL environment variable.
 *
 * TRUSTED_PROXY_IPS (optional, comma separated) lists reverse proxies / load balancers whose X-Forwarded-Proto
 * header may be believed when deciding whether a request arrived over HTTPS. Without it the header is ignored.
 */

function agrireach_is_production(): bool
{
    return strtolower((string) email_setting('APP_ENV', 'development')) === 'production';
}

/** APP_URL without a trailing slash when it is a well-formed http(s) base address, otherwise null. */
function agrireach_public_base_url(): ?string
{
    $url = rtrim(trim((string) email_setting('APP_URL', '')), '/');
    $parts = $url === '' ? false : parse_url($url);
    if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])
        || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) return null;
    return $url;
}

/**
 * Who can open a link built from $url: this_computer (localhost), local_network (private/link-local address,
 * .local or single-label name), api_tunnel (a temporary *.trycloudflare.com quick tunnel, whose gateway serves only
 * /api/), or internet.
 */
function agrireach_url_scope(string $url): string
{
    $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || str_starts_with($host, '127.') || $host === '0.0.0.0') return 'this_computer';
    if (str_ends_with($host, '.trycloudflare.com')) return 'api_tunnel';
    if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? 'internet' : 'local_network';
    return str_ends_with($host, '.local') || str_ends_with($host, '.lan') || str_ends_with($host, '.internal') || !str_contains($host, '.') ? 'local_network' : 'internet';
}

/**
 * Why APP_URL cannot be used for links sent to other people, or null when it can. In production the address must
 * be HTTPS and publicly reachable (not localhost, a private LAN address or a temporary tunnel). Development allows
 * local addresses so the flow can be tried on this computer.
 */
function agrireach_public_url_problem(?bool $production = null): ?string
{
    $production ??= agrireach_is_production();
    $raw = trim((string) email_setting('APP_URL', ''));
    $url = agrireach_public_base_url();
    if ($raw === '') return 'The public address (APP_URL) is not set in the server\'s private configuration.';
    if ($url === null) return 'The public address (APP_URL) is not a valid http(s) base address without query, fragment or credentials.';
    $scope = agrireach_url_scope($url);
    // A temporary test tunnel is accepted only as the launcher's own verified overlay (it exposes the invitation page).
    if (agrireach_public_url_is_temporary()) return null;
    if ($scope === 'api_tunnel') return 'APP_URL points to the temporary mobile API tunnel, which does not serve admin pages.';
    if (!$production) return null;
    if ($scope === 'this_computer') return 'APP_URL points to this computer (' . $url . '), so the link would not open on anyone else\'s device.';
    if ($scope === 'local_network') return 'APP_URL points to a private network address (' . $url . '), so the link would not open outside that network.';
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') return 'APP_URL must use HTTPS on a production server (it is ' . $url . ').';
    return null;
}

/** True while APP_URL is the launcher's verified TEMPORARY test tunnel (email.php temporary_test_settings()). */
function agrireach_public_url_is_temporary(): bool
{
    $temporary = temporary_test_settings();
    return $temporary !== null && agrireach_public_base_url() === $temporary['APP_URL'];
}

/**
 * Whether $path (e.g. "admin/login.php") is actually served at APP_URL. A permanent address serves every route.
 * The TEMPORARY test tunnel's gateway (scripts/agrireach-api-gateway.mjs, "invitation" mode) serves only the mobile
 * API and the public invitation page with its stylesheets; admin login and every other admin page answer 404 there.
 * Keep this list in step with that gateway's invitationEndpoints.
 */
function agrireach_public_route_available(string $path): bool
{
    if (!agrireach_public_url_is_temporary()) return true;
    $path = (string) parse_url(ltrim($path, '/'), PHP_URL_PATH);
    return str_starts_with($path, 'api/') || in_array($path, ['admin/admin-invitation.php', 'admin/assets/css/admin.css', 'admin/assets/css/admin-redesign.css'], true);
}

/** Whether a link to $path may be sent to people outside this computer right now. */
function agrireach_public_link_available(string $path): bool
{
    return agrireach_public_url_problem() === null && agrireach_public_route_available($path);
}

/**
 * An absolute public link for $path (e.g. "admin/login.php"). Throws instead of guessing a host when APP_URL is
 * unusable, and instead of linking to a route the current public address does not serve (temporary test tunnel).
 */
function agrireach_public_url(string $path): string
{
    $problem = agrireach_public_url_problem();
    $base = agrireach_public_base_url();
    if ($problem !== null || $base === null) throw new RuntimeException('No usable public address: ' . ($problem ?? 'APP_URL is missing.'));
    if (!agrireach_public_route_available($path)) throw new RuntimeException('The temporary test address does not serve ' . $path . '.');
    return $base . '/' . ltrim($path, '/');
}

/** True when this request reached the server over HTTPS (directly, or via a configured trusted proxy). */
function agrireach_request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') return true;
    $trusted = array_filter(array_map('trim', explode(',', (string) email_setting('TRUSTED_PROXY_IPS', ''))));
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($trusted && in_array($remote, $trusted, true)) {
        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwarded === 'https';
    }
    return false;
}

/**
 * When a request arrives at the PUBLIC host over plain HTTP on a production server whose APP_URL is HTTPS, the
 * HTTPS address that should be used instead (same path and query), else null. Local access (localhost, LAN) is
 * left alone so the office computer keeps working without a certificate.
 */
function agrireach_https_upgrade_url(): ?string
{
    if (!agrireach_is_production() || agrireach_request_is_https()) return null;
    $base = agrireach_public_base_url();
    if ($base === null || strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https') return null;
    $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $publicHost = strtolower((string) parse_url($base, PHP_URL_HOST));
    if ($requestHost === '' || $requestHost !== $publicHost) return null;
    return 'https://' . $publicHost . (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

/** Runtime settings for every web request: no error details or PHP version in responses on production servers. */
function agrireach_apply_runtime_hardening(): void
{
    if (PHP_SAPI === 'cli') return;
    header_remove('X-Powered-By');
    if (agrireach_is_production()) {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }
    if (agrireach_request_is_https() && agrireach_is_production()) header('Strict-Transport-Security: max-age=31536000');
}
