import { createContext, useContext, useState, useCallback, useMemo } from 'react';
import { auth as authApi } from '../lib/api';

const AuthContext = createContext(null);

const STORAGE_KEY = 'promo_suite_user';

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  });
  const [loading, setLoading] = useState(false);

  const persist = useCallback((u) => {
    setUser(u);
    if (u) localStorage.setItem(STORAGE_KEY, JSON.stringify(u));
    else localStorage.removeItem(STORAGE_KEY);
  }, []);

  const signIn = useCallback(
    async (email, password) => {
      setLoading(true);
      try {
        const { user: u } = await authApi.signIn(email, password);
        persist(u);
        return u;
      } finally {
        setLoading(false);
      }
    },
    [persist]
  );

  const signUp = useCallback(
    async (email, password, metadata) => {
      setLoading(true);
      try {
        const { user: u } = await authApi.signUp(email, password, metadata);
        persist(u);
        return u;
      } finally {
        setLoading(false);
      }
    },
    [persist]
  );

  const signOut = useCallback(async () => {
    try {
      await authApi.signOut();
    } finally {
      persist(null);
    }
  }, [persist]);

  const value = useMemo(
    () => ({ user, loading, signIn, signUp, signOut, isPlatformAdmin: user?.role === 'platform_admin' }),
    [user, loading, signIn, signUp, signOut]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
