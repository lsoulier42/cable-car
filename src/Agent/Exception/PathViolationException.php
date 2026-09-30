<?php

namespace App\Agent\Exception;

/**
 * Thrown when a path escapes the workspace, is denied by policy or cannot be resolved.
 */
class PathViolationException extends WorkspaceException
{
}
