<?php

namespace App\Agent\Messenger;

use App\Agent\Exception\WorkspaceException;
use App\Agent\Runner\AgentLimits;
use App\Agent\Runner\AgentRunRequest;
use App\Agent\Runner\AgentRunner;
use App\Agent\Runner\PersistingAgentObserver;
use App\Entity\AgentRunStatus;
use App\Repository\AgentRunRepository;
use App\Agent\Workspace\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executes an agent run in the worker: the same {@see AgentRunner} the console
 * command uses, with a persistence observer attached.
 */
#[AsMessageHandler]
final class RunAgentMessageHandler
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly WorkspaceManager $workspaces,
        private readonly AgentRunner $runner,
        private readonly AgentLimits $limits,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunAgentMessage $message): void
    {
        $run = $this->runs->findOneByUuid($message->getRunUuid());

        if (null === $run) {
            $this->logger->warning('Agent run not found, message ignored.', ['run' => $message->getRunUuid()]);

            return;
        }

        if ($run->getStatus()->isFinished()) {
            $this->logger->info('Agent run already finished, message ignored.', ['run' => $message->getRunUuid()]);

            return;
        }

        try {
            $workspace = $this->workspaces->get((string) $run->getWorkspace());
        } catch (WorkspaceException $exception) {
            $run->setStatus(AgentRunStatus::Failed);
            $run->setError($exception->getMessage());
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->logger->error('Agent run failed before start.', [
                'run' => $message->getRunUuid(),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $observer = new PersistingAgentObserver($this->entityManager, $run, $this->logger);

        $this->runner->run(
            new AgentRunRequest(
                workspace: $workspace,
                task: (string) $run->getTask(),
                model: $run->getModel(),
                limits: $this->limits,
                runId: $run->getUuid()?->toRfc4122(),
            ),
            $observer,
        );
    }
}
