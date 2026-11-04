import { getApiUrl } from '@/config/api';
import { classifyHttpFailure } from '@/services/apiFailure';

/**
 * Farmer Forgot Password: emailed 6-digit code -> one-time reset token -> new password.
 * No deep links: the farmer types the code from the email into the app. Codes, tokens and
 * passwords are never logged.
 */
export type PasswordResetRequestResult = { message: string; codeLength: number; expiresInSeconds: number; resendAfterSeconds: number };
export type PasswordResetVerifyResult = { resetToken: string; expiresInSeconds: number };

type Payload = {
  success?: boolean;
  status?: string;
  message?: string;
  errors?: Record<string, string>;
  retryAfterSeconds?: number;
  data?: { codeLength?: number; expiresInSeconds?: number; resendAfterSeconds?: number; resetToken?: string };
};

export class PasswordResetApiError extends Error {
  code: string;
  status?: number;
  fields: Record<string, string>;
  retryAfterSeconds: number | null;

  constructor(message: string, code: string, status?: number, fields: Record<string, string> = {}, retryAfterSeconds: number | null = null) {
    super(message);
    this.name = 'PasswordResetApiError';
    this.code = code;
    this.status = status;
    this.fields = fields;
    this.retryAfterSeconds = retryAfterSeconds;
  }
}

async function post(path: string, body: Record<string, string>): Promise<Payload> {
  let url: string;
  try {
    url = getApiUrl(path);
  } catch (error) {
    throw new PasswordResetApiError(error instanceof Error && error.message === 'API_NOT_CONFIGURED'
      ? 'The AgriReach server is not configured on this device.'
      : 'The AgriReach server address is invalid. Restart the app after checking the API configuration.', 'config');
  }
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);
  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: controller.signal,
    });
    const bodyText = await response.text();
    let payload: Payload | null = null;
    try {
      const parsed: unknown = JSON.parse(bodyText);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) payload = parsed as Payload;
    } catch { /* not JSON: classified below */ }
    if (response.ok && payload?.success) return payload;
    if (!payload || response.status === 404 || response.status >= 500) {
      const failure = classifyHttpFailure({ status: response.status, bodyText, gateway: response.headers.get('X-AgriReach-Gateway') });
      throw new PasswordResetApiError(failure.message, failure.code, response.status);
    }
    throw new PasswordResetApiError(
      payload.message || `The request could not be completed (HTTP ${response.status}).`,
      payload.status || 'rejected',
      response.status,
      payload.errors || {},
      typeof payload.retryAfterSeconds === 'number' ? payload.retryAfterSeconds : null,
    );
  } catch (error) {
    if (error instanceof PasswordResetApiError) throw error;
    if (controller.signal.aborted) throw new PasswordResetApiError('The AgriReach server took too long to respond. Check your connection and try again.', 'timeout');
    throw new PasswordResetApiError('Unable to reach the AgriReach server. Check your connection and try again.', 'network');
  } finally {
    clearTimeout(timeout);
  }
}

/** Always resolves with the same confirmation for any valid email; the server never says whether an account exists. */
export async function requestPasswordResetCode(email: string): Promise<PasswordResetRequestResult> {
  const payload = await post('/auth/forgot-password.php', { email });
  return {
    message: payload.message || 'If an AgriReach farmer account uses this email, a code was sent to it.',
    codeLength: payload.data?.codeLength || 6,
    expiresInSeconds: payload.data?.expiresInSeconds || 600,
    resendAfterSeconds: payload.data?.resendAfterSeconds || 60,
  };
}

export async function verifyPasswordResetCode(email: string, code: string): Promise<PasswordResetVerifyResult> {
  const payload = await post('/auth/verify-reset-code.php', { email, code });
  if (!payload.data?.resetToken) throw new PasswordResetApiError('The server returned an unexpected response. Please request a new code.', 'bad_response');
  return { resetToken: payload.data.resetToken, expiresInSeconds: payload.data.expiresInSeconds || 900 };
}

export async function completePasswordReset(resetToken: string, password: string, confirmPassword: string): Promise<string> {
  const payload = await post('/auth/reset-password.php', { resetToken, password, confirmPassword });
  return payload.message || 'Your password was changed. Log in with your new password.';
}
