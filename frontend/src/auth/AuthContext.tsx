import { useMemo, useState, type ReactNode } from 'react';
import { TOKEN_KEY } from '../api/client';
import { parseJwt } from '../lib/utils';
import { AuthContext, type AuthContextValue } from './context';

/**
 * Holds the JWT in localStorage and exposes the decoded identity (email, roles).
 * Token expiration is handled by the API: any 401 clears the token and
 * redirects to /login (see the axios response interceptor in api/client.ts).
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_KEY));

  const value = useMemo<AuthContextValue>(() => {
    const decoded = token ? parseJwt(token) : null;

    return {
      token,
      email: token ? (decoded?.email ?? null) : null,
      roles: token ? (decoded?.roles ?? []) : [],
      isAuthenticated: token !== null,
      login: (newToken: string) => {
        localStorage.setItem(TOKEN_KEY, newToken);
        setToken(newToken);
      },
      logout: () => {
        localStorage.removeItem(TOKEN_KEY);
        setToken(null);
      },
    };
  }, [token]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}