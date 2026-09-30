import { Navigate, Route, Routes } from 'react-router';
import { AlertTriangle } from 'lucide-react';
import { useAuth } from './auth/useAuth';
import { RequireAuth, RequireRole } from './auth/guards';
import { AppLayout } from './layouts/AppLayout';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { ProfilePage } from './pages/ProfilePage';
import { NewRunPage } from './pages/agent/NewRunPage';
import { RunDetailPage } from './pages/agent/RunDetailPage';
import { RunsPage } from './pages/agent/RunsPage';
import { UsersPage } from './pages/admin/UsersPage';

/** Redirects the root path according to the authentication state. */
export function RootRedirect() {
  const { isAuthenticated } = useAuth();
  return <Navigate to={isAuthenticated ? '/agent/runs' : '/login'} replace />;
}

export function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-app px-4 text-center">
      <AlertTriangle className="h-10 w-10 text-warning" aria-hidden />
      <h1 className="text-2xl font-bold text-ink">Page introuvable</h1>
      <p className="text-sm text-muted">La page demandée n'existe pas.</p>
    </div>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        <Route path="/agent/runs" element={<RunsPage />} />
        <Route path="/agent/runs/new" element={<NewRunPage />} />
        <Route path="/agent/runs/:id" element={<RunDetailPage />} />
        <Route path="/profile" element={<ProfilePage />} />
        <Route
          path="/admin/users"
          element={
            <RequireRole role="ROLE_ADMIN">
              <UsersPage />
            </RequireRole>
          }
        />
      </Route>
      <Route path="/" element={<RootRedirect />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}