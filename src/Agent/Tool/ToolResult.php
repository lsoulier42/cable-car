<?php

namespace App\Agent\Tool;

/**
 * Result of a tool call.
 *
 * A failure is *not* an exception: the message is handed back to the model so
 * it can correct itself (wrong path, invalid patch, failing test…). Only
 * unexpected errors abort the run.
 */
final class ToolResult
{
    /**
     * @param array<string, mixed> $metadata observable details (path, exit code, changed files…)
     */
    private function __construct(
        private readonly bool $success,
        private readonly string $output,
        private readonly ?string $error,
        private readonly array $metadata,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(string $output, array $metadata = []): self
    {
        return new self(true, $output, null, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(string $error, array $metadata = []): self
    {
        return new self(false, $error, $error, $metadata);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Content handed back to the model for the next turn.
     */
    public function toModelContent(): string
    {
        if ($this->success) {
            return $this->output;
        }

        return 'ERROR: ' . ($this->error ?? $this->output);
    }
}
