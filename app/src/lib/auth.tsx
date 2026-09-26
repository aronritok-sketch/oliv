import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { createClient, type Client, type Me } from './api';
import { getSecret, setSecret } from './storage';

const TOKEN_KEY = 'oys_token';

// One client for the app. The token lives outside React so every request reads the latest one.
let currentToken: string | null = null;
let onUnauthorized = () => {};
const api = createClient(
  () => currentToken,
  () => onUnauthorized(),
);

type AuthState = {
  /** false until the stored token was read, so the app doesn't flash the login screen. */
  ready: boolean;
  token: string | null;
  me: Me | null;
  api: Client;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshMe: () => Promise<Me | null>;
  setMe: (me: Me) => void;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [me, setMe] = useState<Me | null>(null);

  const forget = useCallback(async () => {
    currentToken = null;
    setToken(null);
    setMe(null);
    await setSecret(TOKEN_KEY, null);
  }, []);

  useEffect(() => {
    onUnauthorized = () => void forget();
  }, [forget]);

  useEffect(() => {
    let alive = true;
    (async () => {
      const saved = await getSecret(TOKEN_KEY);
      if (!alive) return;
      currentToken = saved;
      setToken(saved);
      setReady(true);
      if (saved) {
        try {
          const fresh = await api.me();
          if (alive) setMe(fresh);
        } catch {
          // Offline or signed out elsewhere: a 401 already cleared the token.
        }
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const res = await api.login(email.trim(), password);
      currentToken = res.token;
      await setSecret(TOKEN_KEY, res.token);
      setMe(res.me);
      setToken(res.token);
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await api.logout();
    } catch {
      // Signing out locally is what matters.
    }
    await forget();
  }, [forget]);

  const refreshMe = useCallback(async () => {
    if (!currentToken) return null;
    const fresh = await api.me();
    setMe(fresh);
    return fresh;
  }, []);

  const value = useMemo(
    () => ({ ready, token, me, api, login, logout, refreshMe, setMe }),
    [ready, token, me, login, logout, refreshMe],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
