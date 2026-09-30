import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { LogIn } from 'lucide-react';
import { login } from '../api/auth';
import { extractApiError } from '../api/client';
import { useAuth } from '../auth/useAuth';
import { LogoMark } from '../components/LogoMark';
import { Alert, Button, Card, Field, Input } from '../components/ui';
import { parseJwt } from '../lib/utils';

interface LoginLocationState {
  from?: string;
  registered?: boolean;
}

export function LoginPage() {
  const { login: storeToken } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const state = (location.state ?? {}) as LoginLocationState;

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const redirectAfterLogin = (token: string) => {
    if (state.from) {
      navigate(state.from, { replace: true });
      return;
    }
    const isAdmin = parseJwt(token)?.roles.includes('ROLE_ADMIN') ?? false;
    navigate(isAdmin ? '/admin/users' : '/profile', { replace: true });
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      const response = await login(email, password);
      storeToken(response.token);
      redirectAfterLogin(response.token);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-app px-4">
      {/* Soft brand glow */}
      <div
        className="pointer-events-none absolute inset-0"
        aria-hidden="true"
        style={{
          background: 'radial-gradient(ellipse 60% 40% at 50% -10%, rgba(108,140,255,0.10), transparent)',
        }}
      />

      <div className="relative w-full max-w-md">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <LogoMark className="h-14 w-14 rounded-2xl shadow-card" />
          <div>
            <h1 className="text-xl font-bold text-ink">Cable Car</h1>
            <p className="mt-1 text-sm text-muted">Connexion à votre espace</p>
          </div>
        </div>

        {state.registered && (
          <div className="mb-4">
            <Alert kind="success">Compte créé avec succès. Connectez-vous pour continuer.</Alert>
          </div>
        )}

        <Card className="p-6 sm:p-7">
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}

            <Field label="Adresse e-mail" htmlFor="email">
              <Input
                id="email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="vous@exemple.fr"
              />
            </Field>

            <Field label="Mot de passe" htmlFor="password">
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
              />
            </Field>

            <Button type="submit" className="w-full" loading={submitting} disabled={submitting}>
              <LogIn className="h-4 w-4" aria-hidden />
              Se connecter
            </Button>
          </form>
        </Card>

        <p className="mt-5 text-center text-sm text-muted">
          Pas encore de compte ?{' '}
          <Link to="/register" className="font-semibold text-primary transition hover:text-primary-hover">
            Créer un compte
          </Link>
        </p>
      </div>
    </div>
  );
}