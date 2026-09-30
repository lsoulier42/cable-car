import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Plus, RefreshCw } from 'lucide-react';
import { getRuns, isRunActive, type AgentRun, type AgentRunStatus } from '../../api/agent';
import { Badge, Button, Card, CardHeader, ErrorState, Spinner } from '../../components/ui';

const STATUS_TONES: Record<AgentRunStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  pending: 'neutral',
  running: 'primary',
  completed: 'success',
  failed: 'danger',
  cancelled: 'warning',
  limit_reached: 'warning',
};

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
}

function formatDuration(seconds: number | null): string {
  if (seconds === null) return '—';
  if (seconds < 60) return `${seconds} s`;
  return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}

export function RunsPage() {
  const runsQuery = useQuery({
    queryKey: ['agent', 'runs'],
    queryFn: () => getRuns(50),
    refetchInterval: 5000,
  });

  const runs: AgentRun[] = runsQuery.data?.member ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-ink">Runs de l'agent</h1>
          <p className="mt-1 text-sm text-muted">
            Historique des tâches confiées à Cable Car et de leurs résultats.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Actualiser" onClick={() => void runsQuery.refetch()}>
            <RefreshCw className="h-4 w-4" />
          </Button>
          <Link
            to="/agent/runs/new"
            className="inline-flex h-9 items-center gap-2 rounded-field bg-primary px-4 text-sm font-semibold text-white shadow-soft transition hover:bg-primary-hover"
          >
            <Plus className="h-4 w-4" aria-hidden />
            Nouveau run
          </Link>
        </div>
      </div>

      {runsQuery.isLoading && <Spinner label="Chargement des runs…" />}
      {runsQuery.isError && <ErrorState message="Impossible de charger l'historique des runs." />}

      {runsQuery.data && (
        <Card className="overflow-hidden">
          <CardHeader title={`${runs.length} run(s)`} />
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-line text-sm">
              <thead>
                <tr className="bg-surface-3/40 text-left text-xs font-semibold uppercase tracking-widest text-faint">
                  <th className="px-5 py-3">Tâche</th>
                  <th className="px-5 py-3">Workspace</th>
                  <th className="px-5 py-3">Statut</th>
                  <th className="px-5 py-3">Modèle</th>
                  <th className="px-5 py-3">Date</th>
                  <th className="px-5 py-3">Durée</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {runs.map((run) => (
                  <tr key={run.id} className="transition hover:bg-white/[0.03]">
                    <td className="max-w-md px-5 py-3.5">
                      <Link to={`/agent/runs/${run.id}`} className="font-medium text-ink hover:text-primary">
                        {run.task.length > 90 ? `${run.task.slice(0, 90)}…` : run.task}
                      </Link>
                      <div className="mt-1 text-xs text-faint">
                        {run.iterationCount} itérations · {run.toolCallCount} outils
                        {run.changedFiles.length > 0 ? ` · ${run.changedFiles.length} fichier(s)` : ''}
                      </div>
                    </td>
                    <td className="px-5 py-3.5 text-muted">{run.workspace}</td>
                    <td className="px-5 py-3.5">
                      <Badge tone={STATUS_TONES[run.status]}>
                        {isRunActive(run.status) ? `${run.statusLabel}…` : run.statusLabel}
                      </Badge>
                    </td>
                    <td className="px-5 py-3.5 text-muted">{run.model ?? '—'}</td>
                    <td className="px-5 py-3.5 text-muted">{formatDate(run.createdAt)}</td>
                    <td className="px-5 py-3.5 text-muted">{formatDuration(run.durationSeconds)}</td>
                  </tr>
                ))}
                {runs.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-5 py-10 text-center text-muted">
                      Aucun run pour le moment. Lancez votre première tâche !
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}
