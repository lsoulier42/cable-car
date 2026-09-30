<?php

namespace App\Agent\Runner;

use App\Agent\Workspace\Workspace;

/**
 * What a caller asks the runner to do. The HTTP layer and the console command
 * both build this object, so they exercise the exact same code path.
 */
final class AgentRunRequest
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $task,
        private readonly ?string $model = null,
        private readonly ?AgentLimits $limits = null,
        private readonly ?string $runId = null,
    ) {
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getLimits(): ?AgentLimits
    {
        return $this->limits;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }
}
