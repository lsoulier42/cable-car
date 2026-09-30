<?php

namespace App\Agent\Exception;

/**
 * Thrown when a tool cannot perform its work for an operational reason
 * (unwritable file, disk full, …). The message is returned to the model.
 */
class ToolFailureException extends \RuntimeException
{
}
