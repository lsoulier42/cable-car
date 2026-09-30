<?php

namespace App\Agent\Runner;

use App\Agent\DTO\ChangedFile;

/**
 * Result of a run: concise, observable and persistable.
 */
final class AgentOutcome
{
    /**
     * @param list<ChangedFile> $changedFiles
     */
    public function __construct(
        private readonly StopReason $stopReason,
        private readonly ?string $finalMessage,
        private readonly ?string $error,
        private readonly int $iterations,
        private readonly int $toolCalls,
        private readonly float $durationSeconds,
        private readonly array $changedFiles = [],
        private readonly ?string $modelName = null,
        private readonly ?string $workspaceId = null,
    ) {
    }

    public function getStopReason(): StopReason
    {
        return $this->stopReason;
    }

    public function getFinalMessage(): ?string
    {
        return $this->finalMessage;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function getToolCalls(): int
    {
        return $this->toolCalls;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    /**
     * @return list<ChangedFile>
     */
    public function getChangedFiles(): array
    {
        return $this->changedFiles;
    }

    public function getModelName(): ?string
    {
        return $this->modelName;
    }

    public function getWorkspaceId(): ?string
    {
        return $this->workspaceId;
    }

    public function isSuccess(): bool
    {
        return StopReason::Completed === $this->stopReason;
    }
}
