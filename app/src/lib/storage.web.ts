// The web preview has no Keychain; localStorage is enough there.
export async function getSecret(key: string): Promise<string | null> {
  try {
    return globalThis.localStorage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

export async function setSecret(key: string, value: string | null): Promise<void> {
  try {
    if (value === null) {
      globalThis.localStorage?.removeItem(key);
    } else {
      globalThis.localStorage?.setItem(key, value);
    }
  } catch {
    // Private mode: the person simply logs in again next time.
  }
}
