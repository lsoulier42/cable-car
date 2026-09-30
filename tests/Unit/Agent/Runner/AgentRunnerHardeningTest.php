<?php

namespace App\Tests\Unit\Agent\Runner;

use App\Agent\DTO\AgentRole;
use App\Agent\DTO\ModelTurn;
use App\Agent\Git\GitInspector;
use App\Agent\Model\CodingModelRegistry;
use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Runner\AgentLimits;
use App\Agent\Runner\AgentRunRequest;
use App\Agent\Runner\AgentRunner;
use App\Agent\Runner\StopReason;
use App\Agent\Runner\SystemPromptBuilder;
use App\Agent\Tool\ReadFileTool;
use App\Agent\Tool\ToolRegistry;
use App\Agent\Tool\ToolResult;
use App\Agent\Workspace\PathGuardFactory;
use App\Tests\Support\FakeCodingModel;
use App\Tests\Support\StubTool;
use App\Tests\Support\TempWorkspace;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Hardening cases of the loop: run timeout, oversized outputs, malformed
 * arguments, tool crashes and secret leakage.
 */
final class AgentRunnerHardeningTest extends TestCase
{
    use TempWorkspace;

    private SanitizedProcessRunner $processRunner;

    protected function setUp(): void
    {
        $home = sys_get_temp_dir() . '/cable-car-hardening-home';
        if (!is_dir($home)) {
            mkdir($home, 0o775, true);
        }

        $this->processRunner = new SanitizedProcessRunner('/usr/local/bin:/usr/bin:/bin', $home, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->removeTempWorkspace();
    }

    public function testItStopsWhenTheRunExceedsItsMaximumDuration(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a']);
        $stub = new StubTool();
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('stub_tool', ['value' => 'a'], 'call_1'),
            FakeCodingModel::toolCall('stub_tool', ['value' => 'b'], 'call_2'),
            FakeCodingModel::answer('Done.'),
        ]);

        $runner = $this->runner($model, [$stub], $this->limits(maxRunSeconds: 0));
        $outcome = $runner->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertSame(StopReason::Timeout, $outcome->getStopReason());
        self::assertStringContainsString('exceeded 0 seconds', (string) $outcome->getError());
        self::assertSame([], $stub->calls);
    }

    public function testItTruncatesOversizedToolOutputsBeforeSendingThemToTheModel(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a']);
        $stub = new StubTool(
            'stub_tool',
            static fn (): ToolResult => ToolResult::success(str_repeat('x', 100000)),
        );
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('stub_tool', ['value' => 'a'], 'call_1'),
            FakeCodingModel::answer('Done.'),
        ]);

        $runner = $this->runner($model, [$stub], $this->limits(maxToolOutputBytes: 1024));
        $outcome = $runner->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertTrue($outcome->isSuccess());

        $toolMessages = $model->getObservedContexts()[1]->getMessagesByRole(AgentRole::Tool);
        self::assertCount(1, $toolMessages);
        $content = (string) $toolMessages[0]->getText();
        self::assertLessThan(1200, \strlen($content));
        self::assertStringContainsString('output truncated', $content);
    }

    public function testItReportsMalformedToolArgumentsToTheModel(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', [
                'path' => 'src/Demo.php',
                'start_line' => 'not-a-number',
                'end_line' => [],
            ], 'call_1'),
            FakeCodingModel::answer('Understood.'),
        ]);

        $runner = $this->runner($model, [new ReadFileTool($this->pathGuards())], $this->limits());
        $outcome = $runner->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertTrue($outcome->isSuccess());
        $toolMessages = $model->getObservedContexts()[1]->getMessagesByRole(AgentRole::Tool);
        self::assertStringContainsString('must be an integer', (string) $toolMessages[0]->getText());
        self::assertFalse($toolMessages[0]->isToolSuccess());
    }

    public function testItAbortsTheRunWhenAToolCrashes(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a']);
        $stub = new StubTool('stub_tool', static function (): never {
            throw new \RuntimeException('boom');
        });
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('stub_tool', ['value' => 'a'], 'call_1'),
            FakeCodingModel::answer('Done.'),
        ]);

        $runner = $this->runner($model, [$stub], $this->limits());
        $outcome = $runner->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertSame(StopReason::UnrecoverableToolError, $outcome->getStopReason());
        self::assertStringContainsString('boom', (string) $outcome->getError());
    }

    public function testAWhitespaceOnlyAnswerIsNotAFinalAnswer(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a']);
        $model = new FakeCodingModel([new ModelTurn("   \n  ", [])]);

        $runner = $this->runner($model, [new StubTool()], $this->limits());
        $outcome = $runner->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertSame(StopReason::ModelError, $outcome->getStopReason());
    }

    public function testTheSystemPromptNeverContainsApplicationSecrets(): void
    {
        putenv('APP_SECRET=cable-car-super-secret');
        $_ENV['APP_SECRET'] = 'cable-car-super-secret';
        $_SERVER['APP_SECRET'] = 'cable-car-super-secret';

        $workspace = $this->createTempWorkspace(['.env' => "APP_SECRET=cable-car-super-secret\n"]);
        $model = new FakeCodingModel([FakeCodingModel::answer('Done.')]);

        $runner = $this->runner($model, [new StubTool()], $this->limits());
        $outcome = $runner->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertTrue($outcome->isSuccess());

        $systemMessage = (string) $model->getObservedContexts()[0]->getMessagesByRole(AgentRole::System)[0]->getText();
        self::assertStringNotContainsString('cable-car-super-secret', $systemMessage);
        self::assertStringNotContainsString('APP_SECRET', $systemMessage);

        putenv('APP_SECRET');
        unset($_ENV['APP_SECRET'], $_SERVER['APP_SECRET']);
    }

    /**
     * @param list<\App\Agent\Tool\CodingToolInterface> $tools
     */
    private function runner(FakeCodingModel $model, array $tools, AgentLimits $limits): AgentRunner
    {
        return new AgentRunner(
            new CodingModelRegistry([$model], $model->getName()),
            new ToolRegistry($tools),
            $limits,
            new SystemPromptBuilder(new GitInspector($this->processRunner)),
            new GitInspector($this->processRunner),
            new NullLogger(),
        );
    }

    private function pathGuards(): PathGuardFactory
    {
        return new PathGuardFactory(['ignored_directories' => [], 'denied_paths' => ['.env', '.env.*']]);
    }

    private function limits(
        int $maxRunSeconds = 120,
        int $maxToolOutputBytes = 32768,
    ): AgentLimits {
        return AgentLimits::fromArray([
            'max_iterations' => 10,
            'max_tool_calls' => 20,
            'max_tool_calls_per_turn' => 4,
            'max_run_seconds' => $maxRunSeconds,
            'max_command_seconds' => 30,
            'max_tool_output_bytes' => $maxToolOutputBytes,
            'max_file_read_bytes' => 65536,
            'max_file_write_bytes' => 65536,
            'max_list_entries' => 100,
            'max_search_results' => 50,
        ]);
    }
}
