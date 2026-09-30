<?php

namespace App\Agent\Runner;

/**
 * Hard boundaries for a run. Every value comes from `cable_car.limits`:
 * no magic numbers are scattered in the tools or the runner.
 */
final class AgentLimits
{
    public function __construct(
        private readonly int $maxIterations,
        private readonly int $maxToolCalls,
        private readonly int $maxToolCallsPerTurn,
        private readonly int $maxRunSeconds,
        private readonly int $maxCommandSeconds,
        private readonly int $maxToolOutputBytes,
        private readonly int $maxFileReadBytes,
        private readonly int $maxFileWriteBytes,
        private readonly int $maxListEntries,
        private readonly int $maxSearchResults,
    ) {
    }

    /**
     * @param array{
     *     max_iterations: int,
     *     max_tool_calls: int,
     *     max_tool_calls_per_turn: int,
     *     max_run_seconds: int,
     *     max_command_seconds: int,
     *     max_tool_output_bytes: int,
     *     max_file_read_bytes: int,
     *     max_file_write_bytes: int,
     *     max_list_entries: int,
     *     max_search_results: int
     * } $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            maxIterations: $config['max_iterations'],
            maxToolCalls: $config['max_tool_calls'],
            maxToolCallsPerTurn: $config['max_tool_calls_per_turn'],
            maxRunSeconds: $config['max_run_seconds'],
            maxCommandSeconds: $config['max_command_seconds'],
            maxToolOutputBytes: $config['max_tool_output_bytes'],
            maxFileReadBytes: $config['max_file_read_bytes'],
            maxFileWriteBytes: $config['max_file_write_bytes'],
            maxListEntries: $config['max_list_entries'],
            maxSearchResults: $config['max_search_results'],
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'max_iterations' => $this->maxIterations,
            'max_tool_calls' => $this->maxToolCalls,
            'max_tool_calls_per_turn' => $this->maxToolCallsPerTurn,
            'max_run_seconds' => $this->maxRunSeconds,
            'max_command_seconds' => $this->maxCommandSeconds,
            'max_tool_output_bytes' => $this->maxToolOutputBytes,
            'max_file_read_bytes' => $this->maxFileReadBytes,
            'max_file_write_bytes' => $this->maxFileWriteBytes,
            'max_list_entries' => $this->maxListEntries,
            'max_search_results' => $this->maxSearchResults,
        ];
    }

    public function getMaxIterations(): int
    {
        return $this->maxIterations;
    }

    public function getMaxToolCalls(): int
    {
        return $this->maxToolCalls;
    }

    public function getMaxToolCallsPerTurn(): int
    {
        return $this->maxToolCallsPerTurn;
    }

    public function getMaxRunSeconds(): int
    {
        return $this->maxRunSeconds;
    }

    public function getMaxCommandSeconds(): int
    {
        return $this->maxCommandSeconds;
    }

    public function getMaxToolOutputBytes(): int
    {
        return $this->maxToolOutputBytes;
    }

    public function getMaxFileReadBytes(): int
    {
        return $this->maxFileReadBytes;
    }

    public function getMaxFileWriteBytes(): int
    {
        return $this->maxFileWriteBytes;
    }

    public function getMaxListEntries(): int
    {
        return $this->maxListEntries;
    }

    public function getMaxSearchResults(): int
    {
        return $this->maxSearchResults;
    }
}
