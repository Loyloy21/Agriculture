<?php
declare(strict_types=1);

/*
 * Router for PHP's built-in server, which does not read .htaccess. It applies the same public URL rules as
 * the project .htaccess, so integration tests and rehearsals use exactly the URLs Apache serves:
 *   /CAPSTONE AGRICULTURE/api/...    -> backend/api/...
 *   /CAPSTONE AGRICULTURE/admin/...  -> frontend/admin/...
 * Direct requests to backend/ or frontend/ answer 404; private includes/ folders answer 403.
 * Every other URL is served by the built-in server exactly as without a router.
 *
 *   php -S 127.0.0.1:8091 -t C:\xampp\htdocs "C:\xampp\htdocs\CAPSTONE AGRICULTURE\tests\support\router.php"
 */

$agrireachRouterProject = dirname(__DIR__, 2);
$agrireachRouterPrefix = '/' . basename($agrireachRouterProject) . '/';
$agrireachRouterPath = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (!str_starts_with($agrireachRouterPath, $agrireachRouterPrefix)) return false;
$agrireachRouterRelative = substr($agrireachRouterPath, strlen($agrireachRouterPrefix));

if (preg_match('#^(backend|frontend)(/|$)#', $agrireachRouterRelative)) {
    http_response_code(404);
    return true;
}
if ($agrireachRouterRelative === 'admin' || $agrireachRouterRelative === 'api') {
    header('Location: ' . str_replace(' ', '%20', $agrireachRouterPrefix) . $agrireachRouterRelative . '/', true, 301);
    return true;
}
if (!preg_match('#^(admin|api)/(.*)$#s', $agrireachRouterRelative, $agrireachRouterMatch)) return false;

$agrireachRouterTarget = ($agrireachRouterMatch[1] === 'admin' ? 'frontend/admin/' : 'backend/api/') . $agrireachRouterMatch[2];
if (preg_match('#(^|/)\.\.?(/|$)|(^|/)includes(/|$)|(^|/)\.#', $agrireachRouterTarget)) {
    http_response_code(403);
    return true;
}
$agrireachRouterFile = $agrireachRouterProject . '/' . $agrireachRouterTarget;
if (!is_file($agrireachRouterFile)) {
    http_response_code(is_dir($agrireachRouterFile) ? 403 : 404);
    return true;
}

if (str_ends_with($agrireachRouterFile, '.php')) {
    $_SERVER['SCRIPT_FILENAME'] = $agrireachRouterFile;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $agrireachRouterPath;
    chdir(dirname($agrireachRouterFile));
    $agrireachRouterScript = $agrireachRouterFile;
    unset($agrireachRouterProject, $agrireachRouterPrefix, $agrireachRouterPath, $agrireachRouterRelative, $agrireachRouterMatch, $agrireachRouterTarget, $agrireachRouterFile);
    require $agrireachRouterScript;
    return true;
}

$agrireachRouterTypes = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'ico' => 'image/x-icon', 'json' => 'application/json', 'woff2' => 'font/woff2', 'map' => 'application/json'];
header('Content-Type: ' . ($agrireachRouterTypes[strtolower(pathinfo($agrireachRouterFile, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($agrireachRouterFile));
readfile($agrireachRouterFile);
return true;
