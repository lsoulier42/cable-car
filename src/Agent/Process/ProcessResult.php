<?php

namespace App\Agent\Process;

/**
 * Outcome of a sanitized child process.
 */
final class ProcessResult
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $stdout,
        private readonly string $stderr,
        private readonly bool $truncated,
        private readonly bool $timedOut,
        private readonly float $durationMs,
    ) {
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function isTimedOut(): bool
    {
        return $this->timedOut;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode && !$this->timedOut;
    }

    /**
     * Combined output, stderr included: LLMs and users both want the full story.
     */
    public function getCombinedOutput(): string
    {
        $output = $this->stdout;

        if ('' !== trim($this->stderr)) {
            $output = '' === $output
                ? $this->stderr
                : rtrim($output, "\n") . "\n\n[stderr]\n" . $this->stderr;
        }

        return $output;
    }
}
