import * as SecureStore from 'expo-secure-store';

const FARMER_TOKEN_KEY = 'agrireach.farmer.session-token';

export function readFarmerToken() {
  return SecureStore.getItemAsync(FARMER_TOKEN_KEY);
}

export function saveFarmerToken(token: string) {
  return SecureStore.setItemAsync(FARMER_TOKEN_KEY, token, {
    keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
  });
}

export function clearFarmerToken() {
  return SecureStore.deleteItemAsync(FARMER_TOKEN_KEY);
}
