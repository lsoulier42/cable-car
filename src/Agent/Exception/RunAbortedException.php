<?php

namespace App\Agent\Exception;

use App\Agent\Runner\StopReason;

/**
 * Aborts a run with an explicit stop reason (unexpected tool failure, lost
 * workspace, …).
 */
class RunAbortedException extends \RuntimeException
{
    public function __construct(private readonly StopReason $stopReason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStopReason(): StopReason
    {
        return $this->stopReason;
    }
}
