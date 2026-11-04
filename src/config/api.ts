const configuredApiUrl = process.env.EXPO_PUBLIC_API_URL?.trim().replace(/\/+$/, '');

/**
 * True for addresses that only work on the developer's computer or network, or for a short time: localhost,
 * private/link-local IPs, .local/.lan/.internal or single-label names, and temporary *.trycloudflare.com tunnels.
 */
export function isLocalOrTemporaryHost(hostname: string): boolean {
  const host = hostname.toLowerCase().replace(/^\[|\]$/g, '');
  if (host === 'localhost' || host.endsWith('.localhost') || host === '::1' || host === '0.0.0.0') return true;
  if (host.endsWith('.trycloudflare.com') || host.endsWith('.local') || host.endsWith('.lan') || host.endsWith('.internal') || !host.includes('.')) return true;
  const ipv4 = host.match(/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/);
  if (ipv4) {
    const [a, b] = [Number(ipv4[1]), Number(ipv4[2])];
    return a === 10 || a === 127 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 169 && b === 254) || a === 0;
  }
  return host.startsWith('fc') || host.startsWith('fd') || host.startsWith('fe80');
}

/**
 * Whether $url may be used as the API address. Development builds may use any http(s) address (localhost, LAN,
 * tunnel). Production builds require HTTPS on a public host, so a release can never talk to a developer machine.
 */
export function isAllowedApiUrl(url: URL, development: boolean): boolean {
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
  if (development) return true;
  return url.protocol === 'https:' && !isLocalOrTemporaryHost(url.hostname);
}

export function getApiUrl(path: string) {
  if (!configuredApiUrl) {
    throw new Error('API_NOT_CONFIGURED');
  }

  let url: URL;
  try {
    url = new URL(`${configuredApiUrl}/${path.replace(/^\/+/, '')}`);
  } catch {
    throw new Error('API_INVALID_URL');
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') throw new Error('API_INVALID_URL');
  // A production build with a local, temporary or plain-HTTP address is treated as "not configured".
  if (!isAllowedApiUrl(url, __DEV__)) throw new Error('API_NOT_CONFIGURED');
  return url.toString();
}
