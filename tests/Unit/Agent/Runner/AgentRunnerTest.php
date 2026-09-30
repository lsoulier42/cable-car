<?php

namespace App\Tests\Unit\Agent\Runner;

use App\Agent\DTO\AgentRole;
use App\Agent\DTO\ModelTurn;
use App\Agent\DTO\AgentToolCall;
use App\Agent\Exception\ModelException;
use App\Agent\Git\GitInspector;
use App\Agent\Model\CodingModelRegistry;
use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Runner\AgentLimits;
use App\Agent\Runner\AgentContext;
use App\Agent\Runner\AgentOutcome;
use App\Agent\Runner\AgentRunRequest;
use App\Agent\Runner\AgentRunner;
use App\Agent\Runner\NullAgentObserver;
use App\Agent\Runner\StopReason;
use App\Agent\Runner\SystemPromptBuilder;
use App\Agent\Tool\ListFilesTool;
use App\Agent\Tool\ReadFileTool;
use App\Agent\Tool\SearchTool;
use App\Agent\Tool\ToolRegistry;
use App\Agent\Tool\ToolResult;
use App\Agent\Workspace\PathGuardFactory;
use App\Agent\Workspace\Workspace;
use App\Tests\Support\FakeCodingModel;
use App\Tests\Support\TempWorkspace;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AgentRunnerTest extends TestCase
{
    use TempWorkspace;

    private SanitizedProcessRunner $processRunner;

    protected function setUp(): void
    {
        $home = sys_get_temp_dir() . '/cable-car-runner-home';
        if (!is_dir($home)) {
            mkdir($home, 0o775, true);
        }

        $this->processRunner = new SanitizedProcessRunner('/usr/local/bin:/usr/bin:/bin', $home, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->removeTempWorkspace();
    }

    public function testItRunsAToolCallAndCompletesWithTheFinalAnswer(): void
    {
        $workspace = $this->createTempWorkspace(['composer.json' => '{"name":"demo/app"}']);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_1'),
            FakeCodingModel::answer('The project is demo/app.'),
        ]);

        $outcome = $this->runner($model)->run(new AgentRunRequest($workspace, 'What is this project?'));

        self::assertTrue($outcome->isSuccess());
        self::assertSame(StopReason::Completed, $outcome->getStopReason());
        self::assertSame('The project is demo/app.', $outcome->getFinalMessage());
        self::assertSame(1, $outcome->getToolCalls());
        self::assertSame(2, $outcome->getIterations());

        // The second turn must contain the tool result produced by the first one.
        $turns = $model->getObservedContexts();
        self::assertCount(2, $turns);

        $toolMessages = $turns[1]->getMessagesByRole(AgentRole::Tool);
        self::assertCount(1, $toolMessages);
        self::assertStringContainsString('demo/app', (string) $toolMessages[0]->getText());
        self::assertSame('read_file', $toolMessages[0]->getToolName());
        self::assertSame('call_1', $toolMessages[0]->getToolCallId());
        self::assertTrue($toolMessages[0]->isToolSuccess());

        // The system prompt and the task are the first two messages.
        $messages = $turns[0]->getMessages();
        self::assertSame(AgentRole::System, $messages[0]->getRole());
        self::assertStringContainsString('Cable Car', (string) $messages[0]->getText());
        self::assertStringContainsString($workspace->getId(), (string) $messages[0]->getText());
        self::assertSame(AgentRole::User, $messages[1]->getRole());
        self::assertSame('What is this project?', $messages[1]->getText());
    }

    public function testItFeedsToolFailuresBackToTheModel(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', ['path' => 'src/Missing.php'], 'call_1'),
            FakeCodingModel::toolCall('read_file', ['path' => '../etc/passwd'], 'call_2'),
            FakeCodingModel::toolCall('unknown_tool', ['foo' => 'bar'], 'call_3'),
            FakeCodingModel::answer('Done.'),
        ]);

        $outcome = $this->runner($model)->run(new AgentRunRequest($workspace, 'Read a file.'));

        self::assertTrue($outcome->isSuccess());
        self::assertSame(3, $outcome->getToolCalls());

        $toolMessages = $model->getObservedContexts()[3]->getMessagesByRole(AgentRole::Tool);
        self::assertCount(3, $toolMessages);
        self::assertStringContainsString('does not exist', (string) $toolMessages[0]->getText());
        self::assertStringContainsString('escapes the workspace', (string) $toolMessages[1]->getText());
        self::assertStringContainsString('Unknown tool', (string) $toolMessages[2]->getText());
        self::assertFalse($toolMessages[2]->isToolSuccess());
    }

    public function testItStopsAtTheIterationLimit(): void
    {
        $workspace = $this->createTempWorkspace(['composer.json' => '{}']);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_1'),
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_2'),
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_3'),
        ]);

        $outcome = $this->runner($model, limits: $this->limits(maxIterations: 2))
            ->run(new AgentRunRequest($workspace, 'Loop.'));

        self::assertSame(StopReason::IterationLimit, $outcome->getStopReason());
        self::assertSame(2, $outcome->getIterations());
        self::assertNull($outcome->getFinalMessage());
        self::assertNotNull($outcome->getError());
    }

    public function testItStopsAtTheToolCallLimit(): void
    {
        $workspace = $this->createTempWorkspace(['composer.json' => '{}']);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_1'),
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_2'),
            FakeCodingModel::toolCall('read_file', ['path' => 'composer.json'], 'call_3'),
        ]);

        $outcome = $this->runner($model, limits: $this->limits(maxToolCalls: 2))
            ->run(new AgentRunRequest($workspace, 'Loop.'));

        self::assertSame(StopReason::ToolCallLimit, $outcome->getStopReason());
        self::assertSame(2, $outcome->getToolCalls());
    }

    public function testItRejectsExtraToolCallsInASingleTurn(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c']);
        $model = new FakeCodingModel([
            FakeCodingModel::parallelToolCalls('read_file', [
                ['path' => 'a.txt'],
                ['path' => 'b.txt'],
                ['path' => 'c.txt'],
            ]),
            FakeCodingModel::answer('Done.'),
        ]);

        $outcome = $this->runner($model, limits: $this->limits(maxToolCallsPerTurn: 2))
            ->run(new AgentRunRequest($workspace, 'Read everything.'));

        self::assertTrue($outcome->isSuccess());
        self::assertSame(2, $outcome->getToolCalls());

        $toolMessages = $model->getObservedContexts()[1]->getMessagesByRole(AgentRole::Tool);
        self::assertCount(3, $toolMessages);
        self::assertStringContainsString('too many tool calls', (string) $toolMessages[2]->getText());
    }

    public function testItStopsOnAModelError(): void
    {
        $workspace = $this->createTempWorkspace(['composer.json' => '{}']);
        $model = new class extends FakeCodingModel {
            public function __construct()
            {
                parent::__construct([]);
            }

            public function respond(AgentContext $context): ModelTurn
            {
                throw new ModelException('Provider unreachable.');
            }
        };

        $outcome = $this->runner($model)->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertSame(StopReason::ModelError, $outcome->getStopReason());
        self::assertStringContainsString('Provider unreachable', (string) $outcome->getError());
    }

    public function testItStopsOnAnEmptyModelResponse(): void
    {
        $workspace = $this->createTempWorkspace(['composer.json' => '{}']);
        $model = new FakeCodingModel([new ModelTurn(null, [])]);

        $outcome = $this->runner($model)->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertSame(StopReason::ModelError, $outcome->getStopReason());
    }

    public function testItStopsWhenTheObserverCancelsTheRun(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a', 'b.txt' => 'b']);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', ['path' => 'a.txt'], 'call_1'),
            FakeCodingModel::toolCall('read_file', ['path' => 'b.txt'], 'call_2'),
            FakeCodingModel::answer('Done.'),
        ]);

        $observer = new class extends NullAgentObserver {
            private bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }

            public function onToolResult(
                AgentToolCall $call,
                ToolResult $result,
                float $durationMs,
            ): void {
                $this->cancelled = true;
            }
        };

        $outcome = $this->runner($model)->run(new AgentRunRequest($workspace, 'Anything.'), $observer);

        self::assertSame(StopReason::UserCancelled, $outcome->getStopReason());
        self::assertSame(1, $outcome->getToolCalls());
        self::assertSame(1, $outcome->getIterations());
    }

    public function testItNotifiesTheObserverOfEverythingThatHappens(): void
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a']);
        $model = new FakeCodingModel([
            FakeCodingModel::toolCall('read_file', ['path' => 'a.txt'], 'call_1'),
            FakeCodingModel::answer('All good.'),
        ]);

        $observer = new class extends NullAgentObserver {
            /** @var list<string> */
            public array $events = [];

            public function onRunStarted(AgentContext $context): void
            {
                $this->events[] = 'started';
            }

            public function onModelMessage(string $text): void
            {
                $this->events[] = 'model:' . $text;
            }

            public function onToolCall(\App\Agent\DTO\AgentToolCall $call): void
            {
                $this->events[] = 'call:' . $call->getName();
            }

            public function onToolResult(
                AgentToolCall $call,
                ToolResult $result,
                float $durationMs,
            ): void {
                $this->events[] = 'result:' . ($result->isSuccess() ? 'ok' : 'ko');
            }

            public function onRunFinished(AgentOutcome $outcome): void
            {
                $this->events[] = 'finished:' . $outcome->getStopReason()->value;
            }
        };

        $this->runner($model)->run(new AgentRunRequest($workspace, 'Read a.txt.'), $observer);

        self::assertSame([
            'started',
            'call:read_file',
            'result:ok',
            'model:All good.',
            'finished:completed',
        ], $observer->events);
    }

    public function testItFailsWhenTheWorkspaceDoesNotExist(): void
    {
        $workspace = new Workspace('ghost', sys_get_temp_dir() . '/cable-car-ghost-' . bin2hex(random_bytes(4)));
        $model = new FakeCodingModel([FakeCodingModel::answer('Done.')]);

        $outcome = $this->runner($model)->run(new AgentRunRequest($workspace, 'Anything.'));

        self::assertSame(StopReason::UnrecoverableToolError, $outcome->getStopReason());
        self::assertNotNull($outcome->getError());
    }

    /**
     * @param list<\App\Agent\Tool\CodingToolInterface>|null $tools
     */
    private function runner(
        FakeCodingModel $model,
        ?AgentLimits $limits = null,
        ?array $tools = null,
    ): AgentRunner {
        $pathGuards = new PathGuardFactory([
            'ignored_directories' => ['vendor', 'node_modules', '.git'],
            'denied_paths' => ['.env', '.env.*', '*.pem'],
        ]);

        $toolList = $tools ?? [
            new ListFilesTool($pathGuards),
            new ReadFileTool($pathGuards),
            new SearchTool($pathGuards, $this->processRunner),
        ];

        $registry = new CodingModelRegistry([$model], $model->getName());

        return new AgentRunner(
            $registry,
            new ToolRegistry($toolList),
            $limits ?? $this->limits(),
            new SystemPromptBuilder($this->git()),
            $this->git(),
            new NullLogger(),
        );
    }

    private function git(): GitInspector
    {
        return new GitInspector($this->processRunner);
    }

    private function limits(
        int $maxIterations = 10,
        int $maxToolCalls = 20,
        int $maxToolCallsPerTurn = 4,
    ): AgentLimits {
        return AgentLimits::fromArray([
            'max_iterations' => $maxIterations,
            'max_tool_calls' => $maxToolCalls,
            'max_tool_calls_per_turn' => $maxToolCallsPerTurn,
            'max_run_seconds' => 120,
            'max_command_seconds' => 30,
            'max_tool_output_bytes' => 32768,
            'max_file_read_bytes' => 65536,
            'max_file_write_bytes' => 65536,
            'max_list_entries' => 100,
            'max_search_results' => 50,
        ]);
    }
}
