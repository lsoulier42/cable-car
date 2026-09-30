<?php

namespace App\Agent\Runner;

/**
 * Cancellation flag shared between an observer and the process that owns the
 * run (SIGINT handler in the console, database flag for asynchronous runs).
 */
final class CancellationToken
{
    public function __construct(private bool $cancelled = false)
    {
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
