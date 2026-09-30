<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Runner\AgentLimits;
use App\Agent\Tool\CodingToolInterface;
use App\Agent\Tool\ToolContext;
use App\Agent\Tool\ToolResult;
use App\Agent\Workspace\PathGuardFactory;
use App\Agent\Workspace\Workspace;
use App\Tests\Support\TempWorkspace;
use PHPUnit\Framework\TestCase;

abstract class ToolTestCase extends TestCase
{
    use TempWorkspace;

    protected function tearDown(): void
    {
        $this->removeTempWorkspace();
    }

    protected function toolContext(Workspace $workspace): ToolContext
    {
        return new ToolContext($workspace, $this->limits());
    }

    protected function limits(): AgentLimits
    {
        return AgentLimits::fromArray([
            'max_iterations' => 5,
            'max_tool_calls' => 20,
            'max_tool_calls_per_turn' => 4,
            'max_run_seconds' => 120,
            'max_command_seconds' => 30,
            'max_tool_output_bytes' => 32768,
            'max_file_read_bytes' => 4096,
            'max_file_write_bytes' => 4096,
            'max_list_entries' => 50,
            'max_search_results' => 20,
        ]);
    }

    /**
     * @param list<string> $denied
     * @param list<string> $ignored
     */
    protected function pathGuards(array $denied = [], array $ignored = []): PathGuardFactory
    {
        return new PathGuardFactory([
            'ignored_directories' => $ignored,
            'denied_paths' => $denied,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function execute(CodingToolInterface $tool, array $input, Workspace $workspace): ToolResult
    {
        return $tool->execute($input, $this->toolContext($workspace));
    }
}
