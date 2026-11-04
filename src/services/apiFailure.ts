/**
 * Pure helpers that turn a failed API exchange into a specific, honest cause so the UI never shows
 * a generic "unavailable" for a problem the farmer (or developer) can act on. No tokens or personal
 * data are handled here.
 */
export type ApiFailureCode =
  | 'config'          // EXPO_PUBLIC_API_URL missing/invalid
  | 'network'         // no HTTP response at all (offline, DNS, refused)
  | 'timeout'         // request aborted by the client timeout
  | 'tunnel_unavailable' // a proxy/tunnel answered with its own error page: the public URL is no longer active
  | 'route_unavailable'  // gateway answered 404 {"message":"Not found."}: endpoint is not exposed by this server
  | 'auth'            // 401: session missing/expired/revoked
  | 'server'          // 5xx from the AgriReach API itself
  | 'bad_response'    // HTTP answered but the body is not the expected JSON shape
  | 'rejected';       // 4xx validation/other rejection with an API message

export const API_FAILURE_TITLES: Record<ApiFailureCode, string> = {
  config: 'App is not configured',
  network: "Can't reach the AgriReach server",
  timeout: 'The server took too long to respond',
  tunnel_unavailable: 'AgriReach server address is not active',
  route_unavailable: 'Not available on this server yet',
  auth: 'Please log in again',
  server: 'AgriReach server problem',
  bad_response: 'Unexpected server response',
  rejected: 'Request not accepted',
};

const TUNNEL_STATUSES = new Set([502, 503, 504, 521, 522, 523, 524, 530]);

export type FailureInput = { status: number; bodyText: string; gateway: string | null };

/** Classify a response that could not be used as a normal successful JSON payload. */
export function classifyHttpFailure({ status, bodyText, gateway }: FailureInput): { code: ApiFailureCode; message: string } {
  let json: { message?: unknown } | null = null;
  try {
    const parsed: unknown = JSON.parse(bodyText);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) json = parsed as { message?: unknown };
  } catch { /* not JSON */ }
  const apiMessage = typeof json?.message === 'string' ? json.message : '';

  if (status === 401) return { code: 'auth', message: apiMessage || 'Session expired. Please log in again.' };
  if (json === null) {
    // An HTML/empty page from Cloudflare or a gateway, not from the AgriReach PHP API.
    if (TUNNEL_STATUSES.has(status) || /cloudflare|tunnel|error code/i.test(bodyText)) {
      return { code: 'tunnel_unavailable', message: 'The AgriReach server address is not responding. The API tunnel may have expired or been restarted: restart it and reload the app so it uses the new address.' };
    }
    return { code: 'bad_response', message: `The server returned an unreadable response (HTTP ${status}).` };
  }
  if (status === 404 && gateway === 'api-only' && apiMessage === 'Not found.') {
    return { code: 'route_unavailable', message: 'The connected AgriReach server does not expose this feature yet (HTTP 404). Restart the AgriReach API gateway so it picks up the latest endpoints.' };
  }
  if (status >= 500) return { code: 'server', message: apiMessage || `The AgriReach server reported an error (HTTP ${status}).` };
  return { code: 'rejected', message: apiMessage || `The request could not be completed (HTTP ${status}).` };
}

const WEATHER_ADVICE_ASSESSMENTS = ['thresholds_exceeded', 'no_threshold_exceeded', 'limited', 'unclear'];

type AdviceLike ={ status?: unknown; weather?: unknown; advice?: unknown };

/**
 * A 200 response must still match what the panel renders. Returns an error string, or null when valid.
 * Guards against a "valid API response that the mobile panel parses incorrectly".
 */
export function weatherAdviceShapeProblem(data: unknown): string | null {
  if (!data || typeof data !== 'object') return 'response body is not an object';
  const value = data as AdviceLike;
  if (value.status !== 'current' && value.status !== 'stale' && value.status !== 'unavailable') return 'unknown status';
  if (value.status === 'unavailable') return null;
  const weather = value.weather as Record<string, unknown> | null;
  const advice = value.advice as Record<string, unknown> | null;
  if (!weather || typeof weather !== 'object' || !weather.units || typeof weather.observedAt !== 'string') return 'missing weather reading';
  if (!advice || typeof advice !== 'object' || !Array.isArray(advice.matched) || !Array.isArray(advice.recommendations) || !Array.isArray(advice.notEvaluated)) return 'missing advice lists';
  // An older server only said "normal"; showing that would repeat the misleading all-clear, so refuse it.
  if (!WEATHER_ADVICE_ASSESSMENTS.includes(advice.assessment as string) || typeof advice.headline !== 'string' || !Array.isArray(advice.limitations)) return 'advice format not recognised (server and app versions differ)';
  return null;
}
