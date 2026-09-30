<?php

namespace App\Agent\Runner;

use App\Agent\DTO\AgentToolCall;
use App\Agent\Tool\ToolResult;

/**
 * Observes a run without interfering with it: the CLI prints events, the
 * asynchronous runner persists them, and the cancellation flag is polled by
 * the runner between steps.
 *
 * Implementations must be cheap: they are called on every loop tick.
 */
interface AgentObserverInterface
{
    public function isCancelled(): bool;

    public function onRunStarted(AgentContext $context): void;

    public function onModelMessage(string $text): void;

    public function onToolCall(AgentToolCall $call): void;

    public function onToolResult(AgentToolCall $call, ToolResult $result, float $durationMs): void;

    public function onRunFinished(AgentOutcome $outcome): void;
}
