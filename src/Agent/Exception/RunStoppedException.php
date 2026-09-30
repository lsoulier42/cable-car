<?php

namespace App\Agent\Exception;

use App\Agent\Runner\StopReason;

/**
 * Thrown by the runner to stop a run with an explicit reason:
 * cancellation, limit reached, timeout or an empty model response.
 */
class RunStoppedException extends \RuntimeException
{
    public function __construct(private readonly StopReason $stopReason, string $message)
    {
        parent::__construct($message);
    }

    public function getStopReason(): StopReason
    {
        return $this->stopReason;
    }
}
