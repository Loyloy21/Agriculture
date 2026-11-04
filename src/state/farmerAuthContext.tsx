import { router, useSegments } from 'expo-router';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { ActivityIndicator, AppState, StyleSheet, View } from 'react-native';

import { FarmerAuthApiError, getAuthenticatedFarmer, loginFarmer, logoutFarmer, type AuthenticatedFarmer, type FarmerLoginResult } from '@/services/farmerAuthApi';
import { clearFarmerToken, readFarmerToken, saveFarmerToken } from '@/services/secureFarmerSession';

type AuthContextValue = {
  ready: boolean;
  farmer: AuthenticatedFarmer | null;
  login: (email: string, password: string) => Promise<FarmerLoginResult>;
  logout: () => Promise<void>;
  refreshSession: () => Promise<boolean>;
};

const FarmerAuthContext = createContext<AuthContextValue | null>(null);
const PUBLIC_ROUTES = new Set(['', 'index', 'login', 'register', 'verification-pending', 'forgot-password', 'new-password']);

export function FarmerAuthProvider({ children }: { children: ReactNode }) {
  const segments = useSegments();
  const [ready, setReady] = useState(false);
  const [farmer, setFarmer] = useState<AuthenticatedFarmer | null>(null);
  const rootRoute = String(segments[0] ?? '');
  const isPublicRoute = PUBLIC_ROUTES.has(rootRoute);

  const clearAuthentication = useCallback(async () => {
    await clearFarmerToken();
    setFarmer(null);
  }, []);

  const refreshSession = useCallback(async () => {
    const token = await readFarmerToken();
    if (!token) {
      setFarmer(null);
      return false;
    }
    try {
      const currentFarmer = await getAuthenticatedFarmer(token);
      setFarmer(currentFarmer);
      return true;
    } catch (error) {
      if (error instanceof FarmerAuthApiError && error.status === 401) await clearAuthentication();
      return false;
    }
  }, [clearAuthentication]);

  useEffect(() => {
    let active = true;
    (async () => {
      const token = await readFarmerToken();
      if (token) {
        try {
          const currentFarmer = await getAuthenticatedFarmer(token);
          if (active) {
            setFarmer(currentFarmer);
          }
        } catch (error) {
          if (error instanceof FarmerAuthApiError && error.status === 401) await clearFarmerToken();
        }
      }
      if (active) setReady(true);
    })();
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!ready) return;
    if (!farmer && !isPublicRoute) router.replace('/login');
    else if (farmer && (rootRoute === '' || rootRoute === 'index' || rootRoute === 'login')) router.replace('/farmer-home');
  }, [farmer, isPublicRoute, ready, rootRoute]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') void refreshSession();
    });
    return () => subscription.remove();
  }, [refreshSession]);

  const login = useCallback(async (email: string, password: string) => {
    const previousToken = await readFarmerToken();
    setFarmer(null);
    await clearFarmerToken();
    if (previousToken) await logoutFarmer(previousToken).catch(() => undefined);
    const result = await loginFarmer(email, password);
    if (result.status === 'authenticated') {
      try {
        await saveFarmerToken(result.token);
      } catch {
        await logoutFarmer(result.token).catch(() => undefined);
        throw new Error('The secure session could not be saved on this device.');
      }
      setFarmer(result.farmer);
    }
    return result;
  }, []);

  const logout = useCallback(async () => {
    const token = await readFarmerToken();
    try {
      if (token) await logoutFarmer(token);
    } finally {
      await clearAuthentication();
    }
  }, [clearAuthentication]);

  const value = useMemo(() => ({ ready, farmer, login, logout, refreshSession }), [ready, farmer, login, logout, refreshSession]);

  if (!ready || (!farmer && !isPublicRoute)) return <View style={styles.loading}><ActivityIndicator color="#4B842F" size="large" /></View>;
  return <FarmerAuthContext.Provider value={value}>{children}</FarmerAuthContext.Provider>;
}

export function useFarmerAuth() {
  const context = useContext(FarmerAuthContext);
  if (!context) throw new Error('useFarmerAuth must be used inside FarmerAuthProvider.');
  return context;
}

const styles = StyleSheet.create({ loading: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FCFDF9' } });
