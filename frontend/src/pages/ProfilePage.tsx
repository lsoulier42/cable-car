import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, Save } from 'lucide-react';
import { getMe, updateMe } from '../api/auth';
import { extractApiError } from '../api/client';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  ErrorState,
  Field,
  Input,
  Spinner,
} from '../components/ui';

export function ProfilePage() {
  const queryClient = useQueryClient();
  const meQuery = useQuery({ queryKey: ['me'], queryFn: getMe });

  const [email, setEmail] = useState('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [plainPassword, setPlainPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Synchronize the form once the profile is loaded (and after each update).
  const [hydrated, setHydrated] = useState(false);
  const me = meQuery.data;
  if (me && !hydrated) {
    setEmail(me.email);
    setFirstName(me.firstName ?? '');
    setLastName(me.lastName ?? '');
    setHydrated(true);
  }

  const updateMutation = useMutation({
    mutationFn: updateMe,
    onSuccess: (updated) => {
      queryClient.setQueryData(['me'], updated);
      setEmail(updated.email);
      setFirstName(updated.firstName ?? '');
      setLastName(updated.lastName ?? '');
      setPlainPassword('');
      setConfirmPassword('');
      setSuccess(true);
      setError(null);
    },
    onError: (err) => setError(extractApiError(err).message),
  });

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    setSuccess(false);
    setError(null);

    if (plainPassword && plainPassword.length < 8) {
      setError('Le nouveau mot de passe doit contenir au moins 8 caractères.');
      return;
    }
    if (plainPassword !== confirmPassword) {
      setError('La confirmation du nouveau mot de passe ne correspond pas.');
      return;
    }

    updateMutation.mutate({
      email,
      firstName: firstName.trim() || null,
      lastName: lastName.trim() || null,
      plainPassword: plainPassword || undefined,
    });
  };

  if (meQuery.isLoading) {
    return <Spinner label="Chargement de votre profil…" />;
  }

  if (meQuery.isError || !me) {
    return <ErrorState message="Impossible de charger votre profil." />;
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">Mon profil</h1>
        <p className="mt-1 text-sm text-muted">Consultez et modifiez vos informations personnelles.</p>
      </div>

      <Card>
        <CardHeader title="Informations" />
        <dl className="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 sm:p-6">
          <div>
            <dt className="text-xs font-medium uppercase tracking-widest text-faint">E-mail</dt>
            <dd className="mt-1.5 text-sm font-semibold text-ink">{me.email}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-widest text-faint">Rôle</dt>
            <dd className="mt-1.5 flex flex-wrap gap-1.5">
              {me.roles.map((role) => (
                <Badge key={role} tone={role === 'ROLE_ADMIN' ? 'primary' : 'neutral'}>
                  {role}
                </Badge>
              ))}
            </dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-widest text-faint">Identifiant</dt>
            <dd className="mt-1.5 font-mono text-xs text-muted">{me.uuid}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-widest text-faint">Statut du compte</dt>
            <dd className="mt-1.5">
              {me.isActive ? <Badge tone="success">Actif</Badge> : <Badge tone="danger">Désactivé</Badge>}
            </dd>
          </div>
        </dl>
      </Card>

      <Card>
        <CardHeader title="Modifier mes informations" />
        <form onSubmit={handleSubmit} className="space-y-4 p-5 sm:p-6">
          {success && <Alert kind="success">Profil mis à jour avec succès.</Alert>}
          {error && <Alert kind="error">{error}</Alert>}

          <Field label="Adresse e-mail" htmlFor="email">
            <Input id="email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Prénom" htmlFor="firstName">
              <Input id="firstName" value={firstName} onChange={(e) => setFirstName(e.target.value)} />
            </Field>
            <Field label="Nom" htmlFor="lastName">
              <Input id="lastName" value={lastName} onChange={(e) => setLastName(e.target.value)} />
            </Field>
          </div>

          <div className="border-t border-line pt-4">
            <p className="mb-3 flex items-center gap-2 text-sm font-medium text-muted">
              <KeyRound className="h-4 w-4 text-faint" aria-hidden />
              Changer mon mot de passe (optionnel)
            </p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Nouveau mot de passe" htmlFor="plainPassword">
                <Input
                  id="plainPassword"
                  type="password"
                  autoComplete="new-password"
                  value={plainPassword}
                  onChange={(e) => setPlainPassword(e.target.value)}
                />
              </Field>
              <Field label="Confirmer" htmlFor="confirmPassword">
                <Input
                  id="confirmPassword"
                  type="password"
                  autoComplete="new-password"
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                />
              </Field>
            </div>
          </div>

          <Button type="submit" loading={updateMutation.isPending} disabled={updateMutation.isPending}>
            <Save className="h-4 w-4" aria-hidden />
            Enregistrer
          </Button>
        </form>
      </Card>
    </div>
  );
}