<?php

namespace App\Agent\Runner;

use App\Agent\DTO\AgentToolCall;
use App\Agent\Tool\ToolResult;
use App\Entity\AgentRun;
use App\Entity\AgentRunStatus;
use App\Entity\AgentStep;
use App\Entity\AgentStepType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists the observable activity of a run so the UI can follow it live and
 * so a finished run stays explainable afterwards.
 *
 * Only user-facing model messages, tool calls, tool results and the final
 * answer are stored — never hidden reasoning.
 */
final class PersistingAgentObserver implements AgentObserverInterface
{
    private const MAX_STORED_TEXT = 4000;

    private int $sequence;

    private bool $cancelled = false;

    private float $lastCancellationCheck = 0.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentRun $run,
        private readonly LoggerInterface $logger,
    ) {
        $this->sequence = $run->getSteps()->count();
    }

    public function isCancelled(): bool
    {
        $now = microtime(true);
        if ($now - $this->lastCancellationCheck < 1.0) {
            return $this->cancelled;
        }

        $this->lastCancellationCheck = $now;

        // Read the flag directly: refreshing the entity would discard pending changes.
        $runId = $this->run->getId();
        if (null === $runId) {
            return $this->cancelled;
        }

        $requested = $this->entityManager->getConnection()->fetchOne(
            'SELECT cancellation_requested FROM agent_run WHERE id = :id',
            ['id' => $runId],
        );

        $this->cancelled = (bool) $requested;

        if ($this->cancelled && AgentRunStatus::Running === $this->run->getStatus()) {
            $this->logger->info('Cancellation requested for run', ['run' => $this->run->getUuid()?->toRfc4122()]);
        }

        return $this->cancelled;
    }

    public function onRunStarted(AgentContext $context): void
    {
        $this->run->setStatus(AgentRunStatus::Running);
        $this->run->setStartedAt(new \DateTimeImmutable());
        $this->run->setLimits($context->getLimits()->toArray());
        $this->persist();
    }

    public function onModelMessage(string $text): void
    {
        $this->addStep(
            AgentStepType::Model,
            message: $text,
        );
    }

    public function onToolCall(AgentToolCall $call): void
    {
        $this->addStep(
            AgentStepType::ToolCall,
            toolName: $call->getName(),
            toolInput: $call->getNormalizedArguments(),
            message: sprintf('%s %s', $call->getName(), $call->getSummary()),
        );
    }

    public function onToolResult(AgentToolCall $call, ToolResult $result, float $durationMs): void
    {
        $this->addStep(
            AgentStepType::ToolResult,
            toolName: $call->getName(),
            toolInput: $call->getNormalizedArguments(),
            resultSummary: $result->isSuccess() ? $result->getOutput() : $result->getError(),
            message: $result->isSuccess() ? 'ok' : 'error',
            success: $result->isSuccess(),
            durationMs: $durationMs,
        );
    }

    public function onRunFinished(AgentOutcome $outcome): void
    {
        $this->run->setStatus($this->statusFromStopReason($outcome->getStopReason()));
        $this->run->setStopReason($outcome->getStopReason());
        $this->run->setFinalMessage($outcome->getFinalMessage());
        $this->run->setError($outcome->getError());
        $this->run->setIterationCount($outcome->getIterations());
        $this->run->setToolCallCount($outcome->getToolCalls());
        $this->run->setFinishedAt(new \DateTimeImmutable());
        $this->run->setChangedFiles(array_map(
            static fn (\App\Agent\DTO\ChangedFile $file): array => $file->toArray(),
            $outcome->getChangedFiles(),
        ));

        if (null !== $outcome->getFinalMessage()) {
            $this->addStep(AgentStepType::Final, message: $outcome->getFinalMessage());
        }

        // A cancellation is visible in the run status: no need for an extra error step.
        if (null !== $outcome->getError() && StopReason::UserCancelled !== $outcome->getStopReason()) {
            $this->addStep(AgentStepType::Error, message: $outcome->getError(), success: false);
        }

        $this->persist();
    }

    private function statusFromStopReason(StopReason $stopReason): AgentRunStatus
    {
        return match ($stopReason) {
            StopReason::Completed => AgentRunStatus::Completed,
            StopReason::UserCancelled => AgentRunStatus::Cancelled,
            StopReason::IterationLimit, StopReason::ToolCallLimit, StopReason::Timeout => AgentRunStatus::LimitReached,
            StopReason::ModelError, StopReason::UnrecoverableToolError => AgentRunStatus::Failed,
        };
    }

    /**
     * @param array<string, mixed>|null $toolInput
     */
    private function addStep(
        AgentStepType $type,
        ?string $toolName = null,
        ?array $toolInput = null,
        ?string $resultSummary = null,
        ?string $message = null,
        bool $success = true,
        ?float $durationMs = null,
    ): void {
        $step = new AgentStep();
        $step->setRun($this->run);
        $step->setSequence(++$this->sequence);
        $step->setType($type);
        $step->setToolName($toolName);
        $step->setToolInput($toolInput);
        $step->setResultSummary($this->truncate($resultSummary));
        $step->setMessage($this->truncate($message));
        $step->setSuccess($success);
        $step->setDurationMs($durationMs);

        $this->entityManager->persist($step);
        $this->persist();
    }

    private function persist(): void
    {
        $this->entityManager->flush();
    }

    private function truncate(?string $text): ?string
    {
        if (null === $text || \strlen($text) <= self::MAX_STORED_TEXT) {
            return $text;
        }

        return substr($text, 0, self::MAX_STORED_TEXT) . sprintf("\n… [truncated, %d bytes total]", \strlen($text));
    }
}
