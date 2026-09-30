<?php

namespace App\Agent\Runner;

use App\Agent\DTO\AgentMessage;
use App\Agent\Exception\WorkspaceException;
use App\Agent\Model\CodingModelInterface;
use App\Agent\Workspace\Workspace;

/**
 * Mutable state of one agent run: the conversation, the counters and the
 * cancellation flag. This is what the runner owns and the model observes.
 */
final class AgentContext
{
    /** @var list<AgentMessage> */
    private array $messages = [];

    private int $iteration = 0;

    private int $toolCallCount = 0;

    private bool $cancelled = false;

    private readonly float $startedAt;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $task,
        private readonly CodingModelInterface $model,
        private readonly AgentLimits $limits,
        private readonly ?string $runId = null,
    ) {
        $this->startedAt = microtime(true);
    }

    public function addMessage(AgentMessage $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return list<AgentMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getModel(): CodingModelInterface
    {
        return $this->model;
    }

    public function getLimits(): AgentLimits
    {
        return $this->limits;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function incrementIteration(): int
    {
        return ++$this->iteration;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function addToolCalls(int $count): void
    {
        $this->toolCallCount += $count;
    }

    public function getToolCallCount(): int
    {
        return $this->toolCallCount;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getElapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function isTimedOut(): bool
    {
        return $this->getElapsedSeconds() > $this->limits->getMaxRunSeconds();
    }

    /**
     * @throws WorkspaceException
     */
    public function assertUsable(): void
    {
        if (!$this->workspace->exists()) {
            throw new WorkspaceException(sprintf(
                'Workspace "%s" is not available anymore.',
                $this->workspace->getId(),
            ));
        }
    }
}
