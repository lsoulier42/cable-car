<?php

namespace App\Tests\Unit\Agent\Model;

use App\Agent\DTO\AgentMessage;
use App\Agent\Exception\ModelException;
use App\Agent\Model\SymfonyAiCodingModel;
use App\Agent\Runner\AgentContext;
use App\Agent\Runner\AgentLimits;
use App\Agent\Tool\ReadFileTool;
use App\Agent\Tool\ToolRegistry;
use App\Agent\Workspace\PathGuardFactory;
use App\Tests\Support\FakeCodingModel;
use App\Tests\Support\TempWorkspace;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * Verifies the Symfony AI integration (message mapping and result mapping)
 * without any provider call.
 */
final class SymfonyAiCodingModelTest extends TestCase
{
    use TempWorkspace;

    protected function tearDown(): void
    {
        $this->removeTempWorkspace();
    }

    public function testItMapsToolCallsFromThePlatform(): void
    {
        $platform = new InMemoryPlatform(static fn (): ToolCallResult => new ToolCallResult([
            new ToolCall('call_42', 'read_file', ['path' => 'src/Demo.php']),
        ]));

        $model = $this->model($platform);
        $turn = $model->respond($this->context($model));

        self::assertTrue($turn->hasToolCalls());
        self::assertFalse($turn->isFinal());
        self::assertNull($turn->getText());
        self::assertCount(1, $turn->getToolCalls());
        self::assertSame('read_file', $turn->getToolCalls()[0]->getName());
        self::assertSame('call_42', $turn->getToolCalls()[0]->getId());
        self::assertSame(['path' => 'src/Demo.php'], $turn->getToolCalls()[0]->getArguments());
    }

    public function testItMapsTextAndToolCallsFromAMultiPartResult(): void
    {
        $platform = new InMemoryPlatform(static fn (): MultiPartResult => new MultiPartResult([
            new TextResult('Let me look at the file.'),
            new ToolCallResult([new ToolCall('call_1', 'read_file', ['path' => 'a.txt'])]),
        ]));

        $model = $this->model($platform);
        $turn = $model->respond($this->context($model));

        self::assertSame('Let me look at the file.', $turn->getText());
        self::assertCount(1, $turn->getToolCalls());
    }

    public function testItMapsFinalTextAnswers(): void
    {
        $platform = new InMemoryPlatform('The version command is registered in config/services.yaml.');

        $model = $this->model($platform);
        $turn = $model->respond($this->context($model));

        self::assertTrue($turn->isFinal());
        self::assertSame('The version command is registered in config/services.yaml.', $turn->getText());
        self::assertSame([], $turn->getToolCalls());
    }

    public function testItSendsTheToolDefinitionsAndTheConversation(): void
    {
        $captured = null;
        $platform = new InMemoryPlatform(static function (
            Model $model,
            MessageBag $messages,
            array $options
        ) use (
            &$captured,
        ): string {
            $captured = ['messages' => $messages, 'options' => $options];

            return 'ok';
        });

        $model = $this->model($platform);
        $context = $this->context($model);
        $context->addMessage(AgentMessage::assistant(null, [
            new \App\Agent\DTO\AgentToolCall('call_1', 'read_file', ['path' => 'a.txt']),
        ]));
        $context->addMessage(AgentMessage::toolResult('call_1', 'read_file', 'file content', true));

        $model->respond($context);

        self::assertNotNull($captured);
        self::assertArrayHasKey('tools', $captured['options']);
        self::assertSame('read_file', $captured['options']['tools'][0]->getName());
        self::assertSame(['type' => 'object'], ['type' => $captured['options']['tools'][0]->getParameters()['type']]);

        $messages = $captured['messages']->getMessages();
        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        self::assertInstanceOf(UserMessage::class, $messages[1]);
        self::assertInstanceOf(ToolCallMessage::class, $messages[3]);
        /** @var ToolCallMessage $toolMessage */
        $toolMessage = $messages[3];
        self::assertSame('call_1', $toolMessage->getToolCall()->getId());
        self::assertSame('file content', $toolMessage->asText());
    }

    public function testItWrapsPlatformFailuresInAModelException(): void
    {
        $platform = new InMemoryPlatform(static function (): never {
            throw new \RuntimeException('Connection refused.');
        });

        $model = $this->model($platform);

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('Connection refused.');

        $model->respond($this->context($model));
    }

    private function model(InMemoryPlatform $platform): SymfonyAiCodingModel
    {
        return new SymfonyAiCodingModel(
            'default',
            'Fake platform',
            'fake-model',
            $platform,
            new ToolRegistry([
                new ReadFileTool(new PathGuardFactory(['denied_paths' => [], 'ignored_directories' => []])),
            ]),
            new NullLogger(),
        );
    }

    private function context(SymfonyAiCodingModel $model): AgentContext
    {
        $workspace = $this->createTempWorkspace(['a.txt' => 'a']);

        $context = new AgentContext($workspace, 'Do something.', $model, AgentLimits::fromArray([
            'max_iterations' => 5,
            'max_tool_calls' => 10,
            'max_tool_calls_per_turn' => 2,
            'max_run_seconds' => 60,
            'max_command_seconds' => 10,
            'max_tool_output_bytes' => 8192,
            'max_file_read_bytes' => 8192,
            'max_file_write_bytes' => 8192,
            'max_list_entries' => 50,
            'max_search_results' => 20,
        ]));

        $context->addMessage(AgentMessage::system('You are a coding agent.'));
        $context->addMessage(AgentMessage::user('Do something.'));

        return $context;
    }
}
