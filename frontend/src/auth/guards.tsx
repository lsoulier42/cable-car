import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useAuth } from './useAuth';

/** Redirects unauthenticated visitors to /login, keeping the target location. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { isAuthenticated } = useAuth();
  const location = useLocation();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  return <>{children}</>;
}

/** Requires the given role in addition to authentication. */
export function RequireRole({ role, children }: { role: string; children: ReactNode }) {
  const { roles } = useAuth();

  if (!roles.includes(role)) {
    return <Navigate to="/profile" replace />;
  }

  return <>{children}</>;
}