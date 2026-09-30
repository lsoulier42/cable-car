<?php

namespace App\Controller\Agent;

use App\Agent\Http\AgentRunAccess;
use App\Dto\AgentStepPayload;
use App\Entity\AgentStep;
use App\Entity\User;
use App\Repository\AgentStepRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Observable history of a run, in execution order.
 */
final class AgentRunStepController extends AbstractController
{
    public function __construct(
        private readonly AgentStepRepository $steps,
        private readonly AgentRunAccess $access,
    ) {
    }

    #[Route('/api/agent/runs/{uuid}/steps', name: 'api_agent_runs_steps', methods: ['GET'])]
    public function list(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        $run = $this->access->find($uuid, $user);
        $steps = $this->steps->findForRun($run);

        return new JsonResponse([
            'member' => array_map(
                static fn (AgentStep $step): array => AgentStepPayload::fromStep($step),
                $steps,
            ),
            'totalItems' => \count($steps),
        ]);
    }
}
