import { getApiUrl } from '@/config/api';

export type AuthenticatedFarmer = {
  id: number;
  fullName: string;
  firstName: string;
  /** Empty when the farmer has no middle name (or an older server does not send it). */
  middleName?: string;
  lastName: string;
  /** Empty when not used, for example Jr. or III. */
  extensionName?: string;
  gender: string;
  dateOfBirth: string | null;
  email: string;
  contactNumber: string;
  address: string;
  residentialLocation: {
    cityCode: string | null;
    cityName: string | null;
    provinceName: string | null;
    barangayCode: string | null;
    barangayName: string | null;
    purokSitio: string | null;
    landmark: string | null;
    locationMethod: 'Legacy' | 'Manual' | 'Map';
    boundaryStatus: 'Not Checked' | 'Inside Mapped Boundary' | 'Outside Mapped Boundary' | 'Check Unavailable';
    latitude: number | null;
    longitude: number | null;
  } | null;
  accountStatus: 'Active';
  verificationStatus: 'Approved';
  farm: {
    id: number;
    name: string;
    type: string;
    agriculturalCategory: string;
    location: string;
    sizeHectares: number | null;
    mainProduct: string;
    latitude: number | null;
    longitude: number | null;
    cityCode: string | null;
    cityName: string | null;
    provinceName: string | null;
    barangayCode: string | null;
    barangayName: string | null;
    purokSitio: string | null;
    landmark: string | null;
    locationMethod: 'Legacy' | 'Manual' | 'Map';
    locationBoundaryStatus: 'Not Checked' | 'Inside Mapped Boundary' | 'Outside Mapped Boundary' | 'Check Unavailable';
    locationReviewStatus: 'Needs Review' | 'Verified' | 'Outside Coverage';
  } | null;
};

export type FarmerLoginResult =
  | { status: 'authenticated'; token: string; farmer: AuthenticatedFarmer }
  | { status: 'pending'; message: string }
  | { status: 'rejected'; message: string; rejectionReason: string | null }
  | { status: 'inactive' | 'blocked' | 'invalid'; message: string };

type ApiPayload = {
  success?: boolean;
  status?: FarmerLoginResult['status'] | 'error';
  message?: string;
  rejectionReason?: string | null;
  token?: string;
  farmer?: AuthenticatedFarmer;
};

export class FarmerAuthApiError extends Error {
  status?: number;

  constructor(message: string, status?: number) {
    super(message);
    this.name = 'FarmerAuthApiError';
    this.status = status;
  }
}

async function readPayload(response: Response): Promise<ApiPayload> {
  try {
    return await response.json() as ApiPayload;
  } catch {
    throw new FarmerAuthApiError('The authentication server returned an invalid response.', response.status);
  }
}

function connectionError(error: unknown): FarmerAuthApiError {
  if (error instanceof FarmerAuthApiError) return error;
  if (error instanceof Error && error.message === 'API_NOT_CONFIGURED') {
    return new FarmerAuthApiError('The AgriReach server is not configured on this device.');
  }
  return new FarmerAuthApiError('Unable to reach the AgriReach server. Check your connection and try again.');
}

export async function loginFarmer(email: string, password: string): Promise<FarmerLoginResult> {
  try {
    const response = await fetch(getApiUrl('/auth/login.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    const payload = await readPayload(response);

    if (response.ok && payload.status === 'authenticated' && payload.token && payload.farmer) {
      return { status: 'authenticated', token: payload.token, farmer: payload.farmer };
    }
    if (payload.status === 'pending') return { status: 'pending', message: payload.message || 'Your registration is still awaiting verification.' };
    if (payload.status === 'rejected') return { status: 'rejected', message: payload.message || 'Your registration was rejected.', rejectionReason: payload.rejectionReason || null };
    if (payload.status === 'inactive' || payload.status === 'blocked' || payload.status === 'invalid') {
      return { status: payload.status, message: payload.message || 'Login is not available for this account.' };
    }
    throw new FarmerAuthApiError(payload.message || 'Login could not be completed.', response.status);
  } catch (error) {
    throw connectionError(error);
  }
}

export async function getAuthenticatedFarmer(token: string): Promise<AuthenticatedFarmer> {
  try {
    const response = await fetch(getApiUrl('/auth/me.php'), {
      method: 'GET',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    const payload = await readPayload(response);
    if (!response.ok || !payload.farmer) {
      throw new FarmerAuthApiError(payload.message || 'Your session is no longer valid.', response.status);
    }
    return payload.farmer;
  } catch (error) {
    throw connectionError(error);
  }
}

export async function logoutFarmer(token: string): Promise<void> {
  try {
    const response = await fetch(getApiUrl('/auth/logout.php'), {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!response.ok) {
      const payload = await readPayload(response);
      throw new FarmerAuthApiError(payload.message || 'Logout could not be completed.', response.status);
    }
  } catch (error) {
    throw connectionError(error);
  }
}
