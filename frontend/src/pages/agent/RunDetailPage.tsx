import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import {
  ArrowLeft,
  ChevronDown,
  ChevronRight,
  CircleStop,
  Clock,
  FileDiff,
  GitBranch,
  RefreshCw,
  Wrench,
} from 'lucide-react';
import {
  cancelRun,
  getRun,
  getRunChanges,
  isRunActive,
  type AgentChangedFile,
  type AgentRun,
  type AgentRunStatus,
  type AgentStep,
} from '../../api/agent';
import { extractApiError } from '../../api/client';
import { Alert, Badge, Button, Card, CardHeader, ErrorState, Spinner } from '../../components/ui';
import { cn } from '../../lib/utils';

const STATUS_TONES: Record<AgentRunStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  pending: 'neutral',
  running: 'primary',
  completed: 'success',
  failed: 'danger',
  cancelled: 'warning',
  limit_reached: 'warning',
};

const STEP_LABELS: Record<string, string> = {
  model: 'MODEL',
  tool_call: 'TOOL',
  tool_result: 'RESULT',
  final: 'FINAL',
  error: 'ERROR',
};

function formatDuration(seconds: number | null): string {
  if (seconds === null) return '—';
  if (seconds < 60) return `${seconds} s`;
  return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}

/** One entry of the activity stream. Tool events are collapsible. */
function StepItem({ step }: { step: AgentStep }) {
  const [open, setOpen] = useState(false);
  const isTool = step.type === 'tool_call' || step.type === 'tool_result';
  const hasDetails = Boolean(step.resultSummary || step.toolInput);

  const tone = !step.success
    ? 'text-danger'
    : step.type === 'tool_call'
      ? 'text-warning'
      : step.type === 'final'
        ? 'text-success'
        : 'text-ink';

  return (
    <div className="border-b border-line/60 last:border-b-0">
      <button
        type="button"
        className={cn(
          'flex w-full items-start gap-3 px-4 py-2.5 text-left transition',
          hasDetails ? 'hover:bg-white/[0.03]' : 'cursor-default',
        )}
        onClick={() => hasDetails && setOpen((value) => !value)}
        aria-expanded={hasDetails ? open : undefined}
      >
        <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center text-faint">
          {hasDetails ? (
            open ? (
              <ChevronDown className="h-3.5 w-3.5" />
            ) : (
              <ChevronRight className="h-3.5 w-3.5" />
            )
          ) : (
            <span className="h-1.5 w-1.5 rounded-full bg-line-strong" />
          )}
        </span>

        <span
          className={cn(
            'w-16 shrink-0 pt-0.5 text-[10px] font-semibold uppercase tracking-widest',
            isTool ? 'text-warning/80' : 'text-faint',
          )}
        >
          {STEP_LABELS[step.type] ?? step.type}
        </span>

        <span className={cn('min-w-0 flex-1 whitespace-pre-wrap break-words text-sm', tone)}>
          {step.type === 'tool_call' ? (
            <>
              <span className="font-semibold">{step.toolName}</span>
              <span className="ml-2 font-mono text-xs text-muted">
                {Object.entries(step.toolInput ?? {})
                  .map(([key, value]) => `${key}=${typeof value === 'string' ? value.slice(0, 60) : String(value)}`)
                  .join(' ')}
              </span>
            </>
          ) : (
            (step.message ?? step.resultSummary ?? '—')
          )}
        </span>

        {step.durationMs !== null && (
          <span className="shrink-0 pt-0.5 text-xs text-faint">{Math.round(step.durationMs)} ms</span>
        )}
      </button>

      {open && hasDetails && (
        <div className="space-y-2 px-4 pb-3 pl-[4.25rem]">
          {step.toolInput && (
            <pre className="max-h-64 overflow-auto rounded-box bg-app/70 p-3 text-xs text-muted">
              {JSON.stringify(step.toolInput, null, 2)}
            </pre>
          )}
          {step.resultSummary && (
            <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-box bg-app/70 p-3 text-xs text-muted">
              {step.resultSummary}
            </pre>
          )}
        </div>
      )}
    </div>
  );
}

function DiffView({ diff }: { diff: string }) {
  return (
    <pre className="max-h-80 overflow-auto rounded-box bg-app/70 p-3 text-xs leading-relaxed">
      {diff.split('\n').map((line, index) => (
        <div
          key={index}
          className={cn(
            'font-mono',
            line.startsWith('+') && !line.startsWith('+++') ? 'text-success' : undefined,
            line.startsWith('-') && !line.startsWith('---') ? 'text-danger' : undefined,
            line.startsWith('@@') ? 'text-primary' : undefined,
            !line.startsWith('+') && !line.startsWith('-') && !line.startsWith('@@') ? 'text-muted' : undefined,
          )}
        >
          {line || ' '}
        </div>
      ))}
    </pre>
  );
}

export function RunDetailPage() {
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);

  const runQuery = useQuery({
    queryKey: ['agent', 'run', id],
    queryFn: () => getRun(id),
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status && isRunActive(status) ? 1500 : false;
    },
  });

  const run: AgentRun | undefined = runQuery.data;

  const changesQuery = useQuery({
    queryKey: ['agent', 'run', id, 'changes'],
    queryFn: () => getRunChanges(id),
    enabled: Boolean(run && !isRunActive(run.status)),
  });

  const cancelMutation = useMutation({
    mutationFn: () => cancelRun(id),
    onSuccess: () => {
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ['agent', 'run', id] });
    },
    onError: (err) => setError(extractApiError(err).message),
  });

  if (runQuery.isLoading) {
    return <Spinner label="Chargement du run…" />;
  }

  if (runQuery.isError || !run) {
    return <ErrorState message="Ce run est introuvable." />;
  }

  const steps: AgentStep[] = run.steps ?? [];
  const changes: AgentChangedFile[] = changesQuery.data?.member ?? run.changedFiles;
  const active = isRunActive(run.status);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Link to="/agent/runs" className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-primary">
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Historique
          </Link>
          <h1 className="mt-2 text-lg font-bold text-ink">{run.task}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted">
            <Badge tone={STATUS_TONES[run.status]}>{active ? `${run.statusLabel}…` : run.statusLabel}</Badge>
            <span className="inline-flex items-center gap-1">
              <GitBranch className="h-3.5 w-3.5" aria-hidden />
              {run.workspace}
            </span>
            <span className="inline-flex items-center gap-1">
              <Wrench className="h-3.5 w-3.5" aria-hidden />
              {run.model ?? '—'}
            </span>
            <span className="inline-flex items-center gap-1">
              <Clock className="h-3.5 w-3.5" aria-hidden />
              {formatDuration(run.durationSeconds)}
            </span>
            <span>
              {run.iterationCount} itérations · {run.toolCallCount} appels d'outils
            </span>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Actualiser" onClick={() => void runQuery.refetch()}>
            <RefreshCw className="h-4 w-4" />
          </Button>
          {active && (
            <Button variant="danger" loading={cancelMutation.isPending} onClick={() => cancelMutation.mutate()}>
              <CircleStop className="h-4 w-4" aria-hidden />
              Annuler
            </Button>
          )}
        </div>
      </div>

      {error && <Alert kind="error">{error}</Alert>}
      {run.error && <Alert kind="error">{run.error}</Alert>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <Card className="overflow-hidden">
          <CardHeader
            title="Activité"
            aside={<span className="text-xs text-faint">{steps.length} étape(s)</span>}
          />
          {steps.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted">
              {active ? 'L\'agent démarre…' : 'Aucune étape enregistrée.'}
            </p>
          ) : (
            <div>
              {steps.map((step) => (
                <StepItem key={step.id} step={step} />
              ))}
            </div>
          )}
        </Card>

        <div className="space-y-6">
          {run.finalMessage && (
            <Card className="overflow-hidden">
              <CardHeader title="Réponse finale" />
              <div className="whitespace-pre-wrap px-5 py-4 text-sm text-ink">{run.finalMessage}</div>
            </Card>
          )}

          <Card className="overflow-hidden">
            <CardHeader
              title="Fichiers modifiés"
              aside={
                <span className="inline-flex items-center gap-1.5 text-xs text-faint">
                  <FileDiff className="h-3.5 w-3.5" aria-hidden />
                  {changes.length}
                </span>
              }
            />
            {changes.length === 0 ? (
              <p className="px-5 py-6 text-sm text-muted">
                Aucun fichier modifié{active ? ' pour le moment' : ''}.
              </p>
            ) : (
              <div className="divide-y divide-line">
                {changes.map((file) => (
                  <div key={file.path} className="px-5 py-4">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-mono text-xs text-ink">{file.path}</span>
                      <Badge tone={file.status === 'created' ? 'success' : file.status === 'deleted' ? 'danger' : 'accent'}>
                        {file.status}
                      </Badge>
                    </div>
                    {file.diff && (
                      <div className="mt-3">
                        <DiffView diff={file.diff} />
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card className="overflow-hidden">
            <CardHeader title="Repères" />
            <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm">
              <dt className="text-muted">Créé</dt>
              <dd className="text-right text-ink">
                {run.createdAt ? new Date(run.createdAt).toLocaleString('fr-FR') : '—'}
              </dd>
              <dt className="text-muted">Démarré</dt>
              <dd className="text-right text-ink">
                {run.startedAt ? new Date(run.startedAt).toLocaleString('fr-FR') : '—'}
              </dd>
              <dt className="text-muted">Terminé</dt>
              <dd className="text-right text-ink">
                {run.finishedAt ? new Date(run.finishedAt).toLocaleString('fr-FR') : '—'}
              </dd>
              <dt className="text-muted">Motif d'arrêt</dt>
              <dd className="text-right text-ink">{run.stopReasonLabel ?? '—'}</dd>
            </dl>
          </Card>
        </div>
      </div>
    </div>
  );
}
