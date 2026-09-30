<?php

namespace App\Agent\Tool;

/**
 * A capability given to the model.
 *
 * Implementations must never perform I/O outside the workspace: all paths go
 * through the {@see \App\Agent\Workspace\PathGuard} and all processes through
 * the {@see \App\Agent\Process\SanitizedProcessRunner}.
 *
 * Adding a tool is meant to be a local change: implement this interface, and
 * the registry picks it up (interface auto-tagging).
 */
interface CodingToolInterface
{
    /**
     * Name exposed to the model (snake_case, stable).
     */
    public function getName(): string;

    /**
     * Description exposed to the model: what it does and when to use it.
     */
    public function getDescription(): string;

    /**
     * JSON schema of the input object.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * @param array<string, mixed> $input raw arguments coming from the model
     */
    public function execute(array $input, ToolContext $context): ToolResult;
}
