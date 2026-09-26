import * as SecureStore from 'expo-secure-store';

// The login token lives in the iOS Keychain, readable only after the phone was unlocked once.
const options: SecureStore.SecureStoreOptions = {
  keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK,
};

export async function getSecret(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key, options);
  } catch {
    return null;
  }
}

export async function setSecret(key: string, value: string | null): Promise<void> {
  if (value === null) {
    await SecureStore.deleteItemAsync(key, options);
  } else {
    await SecureStore.setItemAsync(key, value, options);
  }
}
