<?php

namespace App\Controller\Agent;

use App\Agent\Model\CodingModelRegistry;
use App\Agent\Workspace\WorkspaceManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Workspaces and models the authenticated user may choose from.
 *
 * Only workspace *names* are exposed: the model and the API never see host
 * paths, and the agent can only be pointed at a directory that is explicitly
 * allowed in configuration.
 */
final class AgentWorkspacesController
{
    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly CodingModelRegistry $models,
    ) {
    }

    #[Route('/api/agent/workspaces', name: 'api_agent_workspaces', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $workspaces = [];
        foreach ($this->workspaces->listWorkspaces() as $workspace) {
            $workspaces[] = [
                'id' => $workspace->getId(),
                'name' => $workspace->getName(),
            ];
        }

        return new JsonResponse([
            'member' => $workspaces,
            'totalItems' => \count($workspaces),
        ]);
    }

    #[Route('/api/agent/models', name: 'api_agent_models', methods: ['GET'])]
    public function models(): JsonResponse
    {
        $models = [];
        foreach ($this->models->list() as $model) {
            $models[] = $model + ['default' => $model['name'] === $this->models->getDefaultName()];
        }

        return new JsonResponse([
            'member' => $models,
            'default' => $this->models->getDefaultName(),
            'totalItems' => \count($models),
        ]);
    }
}
