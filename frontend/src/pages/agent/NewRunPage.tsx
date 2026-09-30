import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { Play } from 'lucide-react';
import { createRun, getModels, getWorkspaces } from '../../api/agent';
import { extractApiError } from '../../api/client';
import { Alert, Button, Card, CardHeader, Field, Select, Textarea } from '../../components/ui';

const EXAMPLES = [
  'Add a Symfony console command that displays the application version.',
  'Find where JWT authentication is configured and explain it.',
  'Fix the failing test in tests/Api/LoginTest.php.',
];

export function NewRunPage() {
  const navigate = useNavigate();

  const workspacesQuery = useQuery({ queryKey: ['agent', 'workspaces'], queryFn: getWorkspaces });
  const modelsQuery = useQuery({ queryKey: ['agent', 'models'], queryFn: getModels });

  const [workspace, setWorkspace] = useState('');
  const [model, setModel] = useState('');
  const [task, setTask] = useState('');
  const [error, setError] = useState<string | null>(null);

  const selectedWorkspace = workspace || workspacesQuery.data?.member[0]?.id || '';
  const selectedModel = model || modelsQuery.data?.default || '';

  const createMutation = useMutation({
    mutationFn: createRun,
    onSuccess: (run) => {
      void navigate(`/agent/runs/${run.id}`);
    },
    onError: (err) => setError(extractApiError(err).message),
  });

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    setError(null);

    if (!selectedWorkspace) {
      setError('Aucun workspace disponible : ajoutez un dépôt sous le dossier des workspaces.');
      return;
    }

    if (task.trim().length < 4) {
      setError('Décrivez la tâche en quelques mots (4 caractères minimum).');
      return;
    }

    createMutation.mutate({
      workspace: selectedWorkspace,
      task: task.trim(),
      model: selectedModel || null,
    });
  };

  const workspaces = workspacesQuery.data?.member ?? [];
  const models = modelsQuery.data?.member ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">Nouveau run</h1>
        <p className="mt-1 text-sm text-muted">
          Donnez une tâche à l'agent. Il inspectera le dépôt, modifiera les fichiers nécessaires et
          lancera les commandes de validation autorisées — sans jamais committer ni pousser.
        </p>
      </div>

      <Card className="overflow-hidden">
        <CardHeader title="Tâche" />
        <form onSubmit={handleSubmit} className="space-y-5 p-5">
          {error && <Alert kind="error">{error}</Alert>}

          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Workspace" htmlFor="workspace" hint="Un dépôt autorisé dans la configuration.">
              <Select
                id="workspace"
                value={selectedWorkspace}
                onChange={(event) => setWorkspace(event.target.value)}
                disabled={workspaces.length === 0}
              >
                {workspaces.length === 0 && <option value="">Aucun workspace</option>}
                {workspaces.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Modèle" htmlFor="model">
              <Select id="model" value={selectedModel} onChange={(event) => setModel(event.target.value)}>
                {models.map((item) => (
                  <option key={item.name} value={item.name}>
                    {item.label} ({item.model})
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          <Field label="Instruction" htmlFor="task" hint="Une tâche précise donne de meilleurs résultats.">
            <Textarea
              id="task"
              rows={6}
              value={task}
              onChange={(event) => setTask(event.target.value)}
              placeholder="Ex. : Ajoute une commande Symfony qui affiche la version de l'application."
            />
          </Field>

          <div className="flex flex-wrap gap-2">
            {EXAMPLES.map((example) => (
              <button
                key={example}
                type="button"
                onClick={() => setTask(example)}
                className="rounded-full bg-white/5 px-3 py-1 text-xs text-muted transition hover:bg-primary/12 hover:text-primary"
              >
                {example.length > 60 ? `${example.slice(0, 60)}…` : example}
              </button>
            ))}
          </div>

          <div className="flex items-center justify-end gap-3">
            <Button type="submit" loading={createMutation.isPending} disabled={workspaces.length === 0}>
              <Play className="h-4 w-4" aria-hidden />
              Lancer le run
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
