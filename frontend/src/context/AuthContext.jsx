import { createContext, useContext, useState, useCallback, useMemo, useEffect } from 'react';
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
  // Start "checking" only if we have a cached user to verify — a first-time
  // visitor with no cached session should see the app immediately, not a
  // loading spinner.
  const [checkingSession, setCheckingSession] = useState(() => {
    try {
      return !!localStorage.getItem(STORAGE_KEY);
    } catch {
      return false;
    }
  });
  const [loading, setLoading] = useState(false);

  const persist = useCallback((u) => {
    setUser(u);
    if (u) localStorage.setItem(STORAGE_KEY, JSON.stringify(u));
    else localStorage.removeItem(STORAGE_KEY);
  }, []);

  // On first load, verify any cached login against the REAL server-side
  // session rather than trusting localStorage blindly — a stale cache
  // (e.g. after the session expired or the server restarted) used to cause
  // the app to bounce into a protected page and then hard-crash to /login.
  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const cachedRaw = localStorage.getItem(STORAGE_KEY);
        if (!cachedRaw) return; // nothing cached — nothing to verify
        const res = await authApi.whoami();
        if (!alive) return;
        if (res?.user) {
          persist(res.user); // refresh with the authoritative copy
        } else {
          persist(null); // cached login is stale — clear it quietly
        }
      } catch {
        // Network hiccup etc. — leave the cached value as-is; any real API
        // call the person makes will re-validate and clean up if needed.
      } finally {
        if (alive) setCheckingSession(false);
      }
    })();
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // If any API call anywhere in the app hits a 401, clear the cached user
  // (soft, in-app) instead of a jarring hard page reload.
  useEffect(() => {
    const onSessionInvalid = () => setUser(null);
    window.addEventListener('auth:session-invalid', onSessionInvalid);
    return () => window.removeEventListener('auth:session-invalid', onSessionInvalid);
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
    () => ({ user, loading, checkingSession, signIn, signUp, signOut, isPlatformAdmin: user?.role === 'platform_admin' }),
    [user, loading, checkingSession, signIn, signUp, signOut]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
