import http from 'node:http';

const listenHost = '127.0.0.1';
const listenPort = Number.parseInt(process.argv[2] || '8090', 10);
const apacheHost = '127.0.0.1';
const apachePort = Number.parseInt(process.argv[3] || '80', 10);
// Optional 4th argument "invitation": TEMPORARY cross-network testing also exposes the public admin invitation page
// (and only the two stylesheets it loads). Admin login and every other admin page stay 404.
const withInvitationPage = process.argv[4] === 'invitation';
const publicPrefix = '/CAPSTONE AGRICULTURE/api/';
const adminPrefix = '/CAPSTONE AGRICULTURE/admin/';
const invitationEndpoints = new Set(['admin-invitation.php', 'assets/css/admin.css', 'assets/css/admin-redesign.css']);
// Only mobile-facing entry points are public. PHP includes and future test scripts are not.
const publicEndpoints = new Set([
  'auth/login.php', 'auth/logout.php', 'auth/me.php',
  'auth/forgot-password.php', 'auth/verify-reset-code.php', 'auth/reset-password.php',
  'farmers/register.php', 'farmers/profile.php', 'farmers/id-image.php',
  'farms/index.php', 'location/validate.php', 'notifications/index.php',
  'reports/index.php', 'reports/show.php', 'reports/image.php', 'production/index.php',
  'weather/advisories.php', 'weather/current.php', 'weather/advice.php',
]);

const gatewayMode = withInvitationPage ? 'api-and-invitation' : 'api-only';

function safeApiPath(rawUrl) {
  let url;
  try { url = new URL(rawUrl || '/', 'http://gateway.local'); } catch { return null; }
  let decodedPath;
  try { decodedPath = decodeURIComponent(url.pathname); } catch { return null; }
  if (withInvitationPage && decodedPath.startsWith(adminPrefix)) {
    const adminRelative = decodedPath.slice(adminPrefix.length);
    return invitationEndpoints.has(adminRelative) ? `${adminPrefix}${adminRelative}${url.search}` : null;
  }
  if (!decodedPath.startsWith(publicPrefix)) return null;
  const relative = decodedPath.slice(publicPrefix.length);
  if (!publicEndpoints.has(relative)) return null;
  return `${publicPrefix}${relative}${url.search}`;
}

const server = http.createServer((request, response) => {
  const targetPath = safeApiPath(request.url);
  if (!targetPath) {
    const body = JSON.stringify({ success: false, message: 'Not found.' });
    response.writeHead(404, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(body), 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff', 'X-AgriReach-Gateway': gatewayMode });
    response.end(body);
    return;
  }

  const headers = { ...request.headers };
  const publicHost = String(request.headers.host || '');
  headers.host = `localhost:${apachePort}`;
  headers['x-forwarded-proto'] = 'https';
  if (publicHost) headers['x-forwarded-host'] = publicHost;
  delete headers['x-forwarded-for'];
  delete headers['forwarded'];
  delete headers.connection;

  const upstream = http.request({ host: apacheHost, port: apachePort, method: request.method, path: encodeURI(targetPath), headers }, (upstreamResponse) => {
    const status = upstreamResponse.statusCode || 502;
    response.writeHead(status, { ...upstreamResponse.headers, 'X-AgriReach-Gateway': gatewayMode });
    upstreamResponse.pipe(response);
    process.stdout.write(`${new Date().toISOString()} ${request.method} ${targetPath.split('?')[0]} ${status}\n`);
  });
  upstream.setTimeout(65000, () => upstream.destroy(new Error('upstream-timeout')));
  upstream.on('error', () => {
    if (!response.headersSent) response.writeHead(502, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-AgriReach-Gateway': gatewayMode });
    response.end(JSON.stringify({ success: false, message: 'The local AgriReach API is unavailable.' }));
  });
  request.on('aborted', () => upstream.destroy());
  request.pipe(upstream);
});

server.requestTimeout = 70000;
server.headersTimeout = 75000;
server.listen(listenPort, listenHost, () => {
  process.stdout.write(`AgriReach gateway (${gatewayMode}) listening on http://${listenHost}:${listenPort}\n`);
});
