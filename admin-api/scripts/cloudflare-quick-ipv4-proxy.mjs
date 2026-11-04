import http from 'node:http';
import https from 'node:https';
import net from 'node:net';

const host = '127.0.0.1';
const port = Number.parseInt(process.argv[2] || '8091', 10);
const allowedHost = 'api.trycloudflare.com';
const upstreamAddresses = String(process.argv[3] || '').split(',');
let addressIndex = 0;

if (!upstreamAddresses.length || upstreamAddresses.some((address) => net.isIP(address) !== 4)) {
  throw new Error('A resolved Cloudflare IPv4 address is required.');
}

const server = http.createServer((request, response) => {
  process.stdout.write(`Quick service relay received ${request.method}\n`);
  if (request.method !== 'POST' || request.url !== '/tunnel') {
    response.writeHead(404, { Connection: 'close' });
    response.end();
    return;
  }

    const headers = { ...request.headers, host: allowedHost };
    delete headers.connection;
    const upstream = https.request({
      hostname: upstreamAddresses[addressIndex++ % upstreamAddresses.length],
      port: 443,
      path: '/tunnel',
      method: 'POST',
      headers,
      servername: allowedHost,
      timeout: 20000,
    }, (upstreamResponse) => {
      process.stdout.write(`Quick service upstream returned ${upstreamResponse.statusCode || 502}\n`);
      response.writeHead(upstreamResponse.statusCode || 502, upstreamResponse.headers);
      upstreamResponse.pipe(response);
    });
    upstream.on('timeout', () => upstream.destroy());
    upstream.on('error', (error) => {
      process.stderr.write(`Quick service relay failed: ${error.code || 'UPSTREAM_ERROR'}\n`);
      if (!response.headersSent) response.writeHead(502, { Connection: 'close' });
      response.end();
    });
    request.pipe(upstream);
});

server.on('connect', (request, clientSocket, head) => {
  const [requestedHost, requestedPort = '443'] = String(request.url || '').split(':');
  if (requestedHost.toLowerCase() !== allowedHost || requestedPort !== '443') {
    clientSocket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
    return;
  }

    const upstream = net.connect(443, upstreamAddresses[addressIndex++ % upstreamAddresses.length], () => {
      clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      upstream.pipe(clientSocket);
      clientSocket.pipe(upstream);
    });
    upstream.setTimeout(20000, () => upstream.destroy());
    upstream.on('error', () => clientSocket.destroy());
    clientSocket.on('error', () => upstream.destroy());
});

server.listen(port, host, () => {
  process.stdout.write(`Cloudflare Quick Tunnel IPv4 provisioning proxy listening on ${host}:${port}\n`);
});
