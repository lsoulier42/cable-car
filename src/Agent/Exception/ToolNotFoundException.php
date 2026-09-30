<?php

namespace App\Agent\Exception;

/**
 * Thrown when the model asks for a tool the harness does not know.
 */
class ToolNotFoundException extends \RuntimeException
{
}
