<?php

namespace App\Agent\Messenger;

/**
 * Asks the worker to execute an agent run.
 *
 * The HTTP request only creates the run and dispatches this message, so a run
 * survives the request lifetime; the console command does not use Messenger.
 */
final class RunAgentMessage
{
    public function __construct(private readonly string $runUuid)
    {
    }

    public function getRunUuid(): string
    {
        return $this->runUuid;
    }
}
