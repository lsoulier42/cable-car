import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { UserPlus } from 'lucide-react';
import { register } from '../api/auth';
import { extractApiError } from '../api/client';
import { LogoMark } from '../components/LogoMark';
import { Alert, Button, Card, Field, Input } from '../components/ui';

export function RegisterPage() {
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    setFieldErrors({});

    if (password.length < 8) {
      setError('Le mot de passe doit contenir au moins 8 caractères.');
      return;
    }
    if (password !== confirmPassword) {
      setError('La confirmation du mot de passe ne correspond pas.');
      return;
    }

    setSubmitting(true);
    try {
      await register({
        email,
        plainPassword: password,
        firstName: firstName.trim() || undefined,
        lastName: lastName.trim() || undefined,
      });
      navigate('/login', { replace: true, state: { registered: true } });
    } catch (err) {
      const apiError = extractApiError(err);
      setError(apiError.message);
      if (apiError.violations) {
        const mapped: Record<string, string> = {};
        for (const violation of apiError.violations) {
          mapped[violation.propertyPath] = violation.message;
        }
        setFieldErrors(mapped);
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-app px-4 py-10">
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
            <h1 className="text-xl font-bold text-ink">Créer un compte</h1>
            <p className="mt-1 text-sm text-muted">Créez votre compte Cable Car</p>
          </div>
        </div>

        <Card className="p-6 sm:p-7">
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}

            <Field label="Adresse e-mail" htmlFor="email" error={fieldErrors.email}>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                required
                value={email}
                invalid={Boolean(fieldErrors.email)}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="vous@exemple.fr"
              />
            </Field>

            <div className="grid grid-cols-2 gap-4">
              <Field label="Prénom" htmlFor="firstName">
                <Input
                  id="firstName"
                  autoComplete="given-name"
                  value={firstName}
                  onChange={(e) => setFirstName(e.target.value)}
                />
              </Field>
              <Field label="Nom" htmlFor="lastName">
                <Input
                  id="lastName"
                  autoComplete="family-name"
                  value={lastName}
                  onChange={(e) => setLastName(e.target.value)}
                />
              </Field>
            </div>

            <Field label="Mot de passe" htmlFor="password" hint="8 caractères minimum">
              <Input
                id="password"
                type="password"
                autoComplete="new-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </Field>

            <Field label="Confirmer le mot de passe" htmlFor="confirmPassword">
              <Input
                id="confirmPassword"
                type="password"
                autoComplete="new-password"
                required
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
              />
            </Field>

            <Button type="submit" className="w-full" loading={submitting} disabled={submitting}>
              <UserPlus className="h-4 w-4" aria-hidden />
              S'inscrire
            </Button>
          </form>
        </Card>

        <p className="mt-5 text-center text-sm text-muted">
          Déjà inscrit ?{' '}
          <Link to="/login" className="font-semibold text-primary transition hover:text-primary-hover">
            Se connecter
          </Link>
        </p>
      </div>
    </div>
  );
}