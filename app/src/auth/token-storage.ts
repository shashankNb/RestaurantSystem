import * as SecureStore from 'expo-secure-store';

const KEY = 'auth-token';

/**
 * iOS and Android: the API token lives in the Keychain / Keystore via expo-secure-store.
 * The web version is token-storage.web.ts.
 */
export const tokenStorage = {
  get: (): Promise<string | null> => SecureStore.getItemAsync(KEY),
  set: (token: string): Promise<void> => SecureStore.setItemAsync(KEY, token),
  clear: (): Promise<void> => SecureStore.deleteItemAsync(KEY),
};
