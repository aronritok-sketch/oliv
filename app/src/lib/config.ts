import Constants from 'expo-constants';

/**
 * Where the WordPress site lives. EXPO_PUBLIC_API_BASE wins (handy for local testing),
 * then `expo.extra.apiBase` from app.json.
 */
export function apiBase(): string {
  const fromEnv = process.env.EXPO_PUBLIC_API_BASE;
  const fromConfig = (Constants.expoConfig?.extra as { apiBase?: string } | undefined)?.apiBase;
  return (fromEnv || fromConfig || '').replace(/\/+$/, '');
}

/** The deep link the web checkout's "Back to the app" button opens. */
export const APP_SCHEME = 'oliviayoga';
