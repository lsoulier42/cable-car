<?php

namespace App\Agent\Tool;

use App\Agent\Runner\AgentLimits;
use App\Agent\Workspace\Workspace;

/**
 * Everything a tool needs to know about the run it belongs to.
 */
final class ToolContext
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly AgentLimits $limits,
        private readonly ?string $runId = null,
    ) {
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getLimits(): AgentLimits
    {
        return $this->limits;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }
}
