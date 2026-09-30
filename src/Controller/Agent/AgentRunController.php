<?php

namespace App\Controller\Agent;

use App\Agent\Exception\WorkspaceException;
use App\Agent\Http\AgentRunAccess;
use App\Agent\Messenger\RunAgentMessage;
use App\Agent\Model\CodingModelRegistry;
use App\Agent\Workspace\WorkspaceManager;
use App\Dto\AgentRunPayload;
use App\Dto\RunInput;
use App\Entity\AgentRun;
use App\Entity\AgentRunStatus;
use App\Entity\User;
use App\Repository\AgentRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Run lifecycle: create, list, inspect, cancel and read the changed files.
 *
 * Creating a run only persists it and dispatches a Messenger message: the API
 * request never blocks on the agent loop. The worker (same runner as the CLI)
 * executes it and records the observable steps.
 */
final class AgentRunController extends AbstractController
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly WorkspaceManager $workspaces,
        private readonly CodingModelRegistry $models,
        private readonly AgentRunAccess $access,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/agent/runs', name: 'api_agent_runs_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] RunInput $input, #[CurrentUser] ?User $user): JsonResponse
    {
        try {
            $workspace = $this->workspaces->get($input->workspace);
        } catch (WorkspaceException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null !== $input->model && !$this->models->has($input->model)) {
            return new JsonResponse(
                ['message' => sprintf('Unknown model "%s".', $input->model)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $run = new AgentRun();
        $run->setUser($user);
        $run->setWorkspace($workspace->getId());
        $run->setTask($input->task);
        $run->setModel($input->model ?? $this->models->getDefaultName());
        $run->setStatus(AgentRunStatus::Pending);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->bus->dispatch(new RunAgentMessage((string) $run->getUuid()));

        return new JsonResponse(AgentRunPayload::fromRun($run), Response::HTTP_CREATED);
    }

    #[Route('/api/agent/runs', name: 'api_agent_runs_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query->get('limit', '50')));
        $runs = $this->runs->findRecentForUser($user, $limit);

        return new JsonResponse([
            'member' => array_map(
                static fn (AgentRun $run): array => AgentRunPayload::fromRun($run),
                $runs,
            ),
            'totalItems' => \count($runs),
        ]);
    }

    #[Route('/api/agent/runs/{uuid}', name: 'api_agent_runs_show', methods: ['GET'])]
    public function show(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        $run = $this->access->find($uuid, $user);

        return new JsonResponse(AgentRunPayload::fromRun($run, true));
    }

    #[Route('/api/agent/runs/{uuid}/cancel', name: 'api_agent_runs_cancel', methods: ['POST'])]
    public function cancel(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        $run = $this->access->find($uuid, $user);

        if ($run->getStatus()->isFinished()) {
            return new JsonResponse([
                'message' => 'This run is already finished.',
                'run' => AgentRunPayload::fromRun($run),
            ], Response::HTTP_CONFLICT);
        }

        $run->requestCancellation();

        // A pending run has not been picked up by the worker yet: cancel it outright.
        if (AgentRunStatus::Pending === $run->getStatus()) {
            $run->setStatus(AgentRunStatus::Cancelled);
            $run->setFinishedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();

        return new JsonResponse(AgentRunPayload::fromRun($run));
    }

    #[Route('/api/agent/runs/{uuid}/changes', name: 'api_agent_runs_changes', methods: ['GET'])]
    public function changes(string $uuid, #[CurrentUser] ?User $user): JsonResponse
    {
        $run = $this->access->find($uuid, $user);
        $changedFiles = $run->getChangedFiles();

        return new JsonResponse([
            'member' => $changedFiles,
            'totalItems' => \count($changedFiles),
        ]);
    }
}
