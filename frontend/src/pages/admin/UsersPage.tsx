import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, Search, Shield, ShieldOff, Trash2, Power, PowerOff } from 'lucide-react';
import { deleteUser, getUsers, patchUser } from '../../api/users';
import { extractApiError } from '../../api/client';
import { useAuth } from '../../auth/useAuth';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  ErrorState,
  Input,
  Spinner,
} from '../../components/ui';
import { cn } from '../../lib/utils';

const ITEMS_PER_PAGE = 10;

type StatusFilter = 'all' | 'active' | 'inactive';

const STATUS_OPTIONS: Array<{ value: StatusFilter; label: string }> = [
  { value: 'all', label: 'Tous' },
  { value: 'active', label: 'Actifs' },
  { value: 'inactive', label: 'Désactivés' },
];

export function UsersPage() {
  const queryClient = useQueryClient();
  const { email: currentEmail } = useAuth();

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<StatusFilter>('all');
  const [error, setError] = useState<string | null>(null);

  const usersQuery = useQuery({
    queryKey: ['users', { page, search, status }],
    queryFn: () =>
      getUsers({
        page,
        itemsPerPage: ITEMS_PER_PAGE,
        email: search || undefined,
        isActive: status === 'all' ? undefined : status === 'active',
      }),
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['users'] });
  };

  const mutation = useMutation({
    mutationFn: ({ id, input }: { id: number; input: Parameters<typeof patchUser>[1] }) => patchUser(id, input),
    onSuccess: invalidate,
    onError: (err) => setError(extractApiError(err).message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteUser(id),
    onSuccess: invalidate,
    onError: (err) => setError(extractApiError(err).message),
  });

  const handleSearchSubmit = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };

  const handleStatusChange = (next: StatusFilter) => {
    setStatus(next);
    setPage(1);
  };

  const isSelf = (userEmail: string) => userEmail === currentEmail;

  const toggleActive = (id: number, isActive: boolean) => {
    setError(null);
    mutation.mutate({ id, input: { isActive: !isActive } });
  };

  const toggleAdmin = (id: number, roles: string[]) => {
    setError(null);
    const isAdmin = roles.includes('ROLE_ADMIN');
    mutation.mutate({ id, input: { roles: isAdmin ? ['ROLE_USER'] : ['ROLE_ADMIN'] } });
  };

  const handleDelete = (id: number, userEmail: string) => {
    setError(null);
    if (!window.confirm(`Supprimer définitivement le compte ${userEmail} ?`)) {
      return;
    }
    deleteMutation.mutate(id);
  };

  const data = usersQuery.data;
  const totalPages = data ? Math.max(1, Math.ceil(data.totalItems / ITEMS_PER_PAGE)) : 1;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-ink">Utilisateurs inscrits</h1>
          <p className="mt-1 text-sm text-muted">Gestion des comptes inscrits.</p>
        </div>
        {data && <Badge tone="primary">{data.totalItems} utilisateur(s)</Badge>}
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
        <form onSubmit={handleSearchSubmit} className="relative flex-1">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-faint"
            aria-hidden
          />
          <Input
            type="search"
            placeholder="Rechercher par e-mail…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            className="pl-9"
          />
        </form>
        <Button type="button" variant="secondary" onClick={handleSearchSubmit}>
          Rechercher
        </Button>
        <div className="flex gap-1 rounded-box border border-line bg-surface-2 p-1">
          {STATUS_OPTIONS.map(({ value, label }) => (
            <button
              key={value}
              type="button"
              onClick={() => handleStatusChange(value)}
              className={cn(
                'rounded-field px-3 py-1.5 text-sm font-medium transition',
                status === value ? 'bg-primary text-white shadow-soft' : 'text-muted hover:bg-white/5 hover:text-ink',
              )}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {error && <Alert kind="error">{error}</Alert>}

      {usersQuery.isLoading && <Spinner label="Chargement des utilisateurs…" />}

      {usersQuery.isError && <ErrorState message="Impossible de charger les utilisateurs." />}

      {data && (
        <Card className="overflow-hidden">
          <CardHeader
            title="Liste des comptes"
            aside={
              <Button variant="ghost" size="icon" title="Actualiser" onClick={() => void invalidate()}>
                <RefreshCw className="h-4 w-4" />
              </Button>
            }
          />

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-line text-sm">
              <thead>
                <tr className="bg-surface-3/40 text-left text-xs font-semibold uppercase tracking-widest text-faint">
                  <th className="px-5 py-3">Utilisateur</th>
                  <th className="px-5 py-3">Rôle</th>
                  <th className="px-5 py-3">Statut</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {data.member.map((user) => {
                  const self = isSelf(user.email);
                  const isAdmin = user.roles.includes('ROLE_ADMIN');
                  return (
                    <tr key={user.id} className="transition hover:bg-white/[0.03]">
                      <td className="px-5 py-3.5">
                        <div className="flex items-center gap-3">
                          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-box bg-primary/12 text-xs font-bold text-primary">
                            {(user.firstName?.[0] ?? user.email[0] ?? '?').toUpperCase()}
                          </span>
                          <div>
                            <div className="font-medium text-ink">
                              {user.firstName || user.lastName
                                ? `${user.firstName ?? ''} ${user.lastName ?? ''}`.trim()
                                : '—'}
                              {self && <span className="ml-2 text-xs text-faint">(vous)</span>}
                            </div>
                            <div className="text-xs text-muted">{user.email}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-5 py-3.5">
                        <Badge tone={isAdmin ? 'primary' : 'neutral'}>
                          {isAdmin ? 'Administrateur' : 'Utilisateur'}
                        </Badge>
                      </td>
                      <td className="px-5 py-3.5">
                        {user.isActive ? <Badge tone="success">Actif</Badge> : <Badge tone="danger">Désactivé</Badge>}
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center justify-end gap-1.5">
                          <Button
                            variant="ghost"
                            size="icon"
                            title={user.isActive ? 'Désactiver le compte' : 'Réactiver le compte'}
                            disabled={self || mutation.isPending}
                            onClick={() => toggleActive(user.id, user.isActive)}
                          >
                            {user.isActive ? <PowerOff className="h-4 w-4" /> : <Power className="h-4 w-4" />}
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title={isAdmin ? 'Retirer le rôle administrateur' : 'Promouvoir administrateur'}
                            disabled={self || mutation.isPending}
                            onClick={() => toggleAdmin(user.id, user.roles)}
                          >
                            {isAdmin ? <ShieldOff className="h-4 w-4 text-warning" /> : <Shield className="h-4 w-4" />}
                          </Button>
                          <Button
                            variant="dangerGhost"
                            size="icon"
                            title="Supprimer le compte"
                            disabled={self || deleteMutation.isPending}
                            onClick={() => handleDelete(user.id, user.email)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
                {data.member.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-5 py-10 text-center text-muted">
                      Aucun utilisateur ne correspond à cette recherche.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="flex items-center justify-between border-t border-line px-5 py-3">
            <p className="text-xs text-faint">
              Page {page} sur {totalPages}
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Précédent
              </Button>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= totalPages}
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              >
                Suivant
              </Button>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
}