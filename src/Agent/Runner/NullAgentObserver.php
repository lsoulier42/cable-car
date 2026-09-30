<?php

namespace App\Agent\Runner;

use App\Agent\DTO\AgentToolCall;
use App\Agent\Tool\ToolResult;

/**
 * Default observer: does nothing and never cancels.
 */
class NullAgentObserver implements AgentObserverInterface
{
    public function isCancelled(): bool
    {
        return false;
    }

    public function onRunStarted(AgentContext $context): void
    {
    }

    public function onModelMessage(string $text): void
    {
    }

    public function onToolCall(AgentToolCall $call): void
    {
    }

    public function onToolResult(AgentToolCall $call, ToolResult $result, float $durationMs): void
    {
    }

    public function onRunFinished(AgentOutcome $outcome): void
    {
    }
}
