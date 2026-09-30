<?php

namespace App\Agent\Exception;

/**
 * Thrown when the model sends malformed arguments to a tool. The message is
 * returned to the model so it can retry with valid arguments.
 */
class ToolInputException extends \InvalidArgumentException
{
}
