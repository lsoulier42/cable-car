<?php

namespace App\Tests\Functional\Agent;

use App\Agent\Messenger\RunAgentMessage;
use App\Agent\Messenger\RunAgentMessageHandler;
use App\Entity\AgentRun;
use App\Entity\AgentRunStatus;
use App\Entity\AgentStep;
use App\Entity\AgentStepType;
use App\Entity\User;
use App\Repository\AgentRunRepository;
use App\Tests\AbstractApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ScriptedPlatform;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

/**
 * End-to-end behaviour of the durable harness: HTTP creates the run, the
 * worker (simulated here) executes it with a scripted model, and the API
 * exposes the observable history and the changed files.
 */
final class AgentRunApiTest extends AbstractApiTestCase
{
    private ?string $workspaceName = null;

    protected function setUp(): void
    {
        ScriptedPlatform::script([]);
    }

    protected function tearDown(): void
    {
        if (null !== $this->workspaceName) {
            $path = $this->workspacesRoot() . '/' . $this->workspaceName;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            }
            $this->workspaceName = null;
        }

        parent::tearDown();
    }

    public function testCreatingARunRequiresAuthentication(): void
    {
        static::createClient()->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => 'demo', 'task' => 'Do something'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testItRejectsAnUnknownWorkspace(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => 'does-not-exist', 'task' => 'Do something'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('does not exist', (string) $client->getResponse()->getContent(false));
    }

    public function testItRejectsAnUnknownModel(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Do something', 'model' => 'nope'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains(['message' => 'Unknown model "nope".']);
    }

    public function testItValidatesTheTask(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains([
            'violations' => [['propertyPath' => 'task']],
        ]);
    }

    public function testARunSurvivesTheRequestAndExposesItsHistory(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace([
            'src/Demo.php' => "<?php\n// old\n",
            'README.md' => "# demo\n",
        ]);
        $this->gitInit();

        ScriptedPlatform::script([
            ScriptedPlatform::toolCall('read_file', ['path' => 'README.md'], 'call_1'),
            ScriptedPlatform::toolCall('write_file', [
                'path' => 'src/Generated.php',
                'content' => "<?php\nreturn 'generated';\n",
            ], 'call_2'),
            ScriptedPlatform::text('Created src/Generated.php.'),
        ]);

        // 1. The HTTP request only creates the run and dispatches a message.
        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Create a generated file.'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $created = $client->getResponse()->toArray();
        $this->assertSame(AgentRunStatus::Pending->value, $created['status']);
        $this->assertSame($this->workspaceName, $created['workspace']);
        $this->assertNotEmpty($created['id']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $dispatched = $transport->getSent();
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(RunAgentMessage::class, $dispatched[0]->getMessage());

        // 2. The worker executes the run (same runner as the CLI).
        $handler = self::getContainer()->get(RunAgentMessageHandler::class);
        $handler(new RunAgentMessage((string) $created['id']));

        // 3. The run is finished and its history is retrievable.
        $client->request('GET', '/api/agent/runs/' . $created['id']);

        $this->assertResponseStatusCodeSame(200);
        $run = $client->getResponse()->toArray();
        $this->assertSame(AgentRunStatus::Completed->value, $run['status']);
        $this->assertSame('Created src/Generated.php.', $run['finalMessage']);
        $this->assertSame(2, $run['toolCallCount']);
        $this->assertSame(3, $run['iterationCount']);
        $this->assertNull($run['error']);
        $this->assertNotNull($run['startedAt']);
        $this->assertNotNull($run['finishedAt']);
        $this->assertSame('default', $run['model']);

        $client->request('GET', '/api/agent/runs/' . $created['id'] . '/steps');

        $this->assertResponseStatusCodeSame(200);
        $steps = $client->getResponse()->toArray()['member'];
        $this->assertSame(
            [
                AgentStepType::ToolCall->value,
                AgentStepType::ToolResult->value,
                AgentStepType::ToolCall->value,
                AgentStepType::ToolResult->value,
                AgentStepType::Model->value,
                AgentStepType::Final->value,
            ],
            array_column($steps, 'type'),
        );
        $this->assertSame('read_file', $steps[0]['toolName']);
        $this->assertTrue($steps[1]['success']);
        $this->assertSame('write_file', $steps[2]['toolName']);
        $this->assertStringContainsString('Created src/Generated.php', $steps[3]['resultSummary']);

        // 4. The changed files are exposed with their diff.
        $client->request('GET', '/api/agent/runs/' . $created['id'] . '/changes');

        $this->assertResponseStatusCodeSame(200);
        $changes = $client->getResponse()->toArray()['member'];
        $this->assertCount(1, $changes);
        $this->assertSame('src/Generated.php', $changes[0]['path']);
        $this->assertSame('created', $changes[0]['status']);
        $this->assertStringContainsString("return 'generated';", (string) $changes[0]['diff']);

        $this->assertFileExists($this->workspacesRoot() . '/' . $this->workspaceName . '/src/Generated.php');
    }

    public function testOnlyObservableMessagesAreStored(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        ScriptedPlatform::script([
            ScriptedPlatform::text('The README contains a project title.'),
        ]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Summarise the README.'],
        ]);
        $uuid = (string) $client->getResponse()->toArray()['id'];

        $handler = self::getContainer()->get(RunAgentMessageHandler::class);
        $handler(new RunAgentMessage($uuid));

        $client->request('GET', '/api/agent/runs/' . $uuid . '/steps');
        $stored = (string) $client->getResponse()->getContent(false);

        // The model answer is stored as-is, and nothing else from the prompt or
        // the internal context is persisted.
        $this->assertStringContainsString('The README contains a project title.', $stored);
        $this->assertStringNotContainsString('You are Cable Car', $stored);
        $this->assertStringNotContainsString('chain of thought', $stored);
    }

    public function testAFailingValidationIsFedBackToTheModelForACorrectiveIteration(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace([
            'src/Broken.php' => "<?php\nthis is not valid php\n",
        ]);

        // 1. the model writes a broken file, 2. validates it (failure),
        // 3. fixes it, 4. validates again (success), 5. answers.
        ScriptedPlatform::script([
            ScriptedPlatform::toolCall('write_file', [
                'path' => 'src/Broken.php',
                'content' => "<?php\necho 'broken'\n",
            ], 'call_1'),
            ScriptedPlatform::toolCall('run_command', ['argv' => ['php', '-l', 'src/Broken.php']], 'call_2'),
            ScriptedPlatform::toolCall('write_file', [
                'path' => 'src/Broken.php',
                'content' => "<?php\necho 'fixed';\n",
            ], 'call_3'),
            ScriptedPlatform::toolCall('run_command', ['argv' => ['php', '-l', 'src/Broken.php']], 'call_4'),
            ScriptedPlatform::text('Syntax fixed and validated with php -l.'),
        ]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Fix the syntax error.'],
        ]);
        $uuid = (string) $client->getResponse()->toArray()['id'];

        self::getContainer()->get(RunAgentMessageHandler::class)(new RunAgentMessage($uuid));

        $client->request('GET', '/api/agent/runs/' . $uuid);
        $run = $client->getResponse()->toArray();
        $this->assertSame(AgentRunStatus::Completed->value, $run['status']);
        $this->assertSame(4, $run['toolCallCount']);

        $client->request('GET', '/api/agent/runs/' . $uuid . '/steps');
        $steps = $client->getResponse()->toArray()['member'];

        // The failing validation is visible, with its output…
        $failedCommand = $steps[3];
        $this->assertSame(AgentStepType::ToolResult->value, $failedCommand['type']);
        $this->assertFalse($failedCommand['success']);
        $this->assertStringContainsString('Parse error', (string) $failedCommand['resultSummary']);

        // …and the corrective iteration ends with a successful validation.
        $successfulCommand = $steps[7];
        $this->assertTrue($successfulCommand['success']);
        $this->assertStringContainsString('No syntax errors detected', (string) $successfulCommand['resultSummary']);

        $this->assertSame(
            "<?php\necho 'fixed';\n",
            file_get_contents($this->workspacesRoot() . '/' . $this->workspaceName . '/src/Broken.php'),
        );
    }

    public function testARunningRunIsCancelledWhenTheFlagIsSet(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        ScriptedPlatform::script([
            ScriptedPlatform::toolCall('read_file', ['path' => 'README.md'], 'call_1'),
            ScriptedPlatform::text('This should never be reached.'),
        ]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Read the README.'],
        ]);
        $uuid = (string) $client->getResponse()->toArray()['id'];

        // Simulate a run already picked up by the worker and cancelled meanwhile.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $run = self::getContainer()->get(AgentRunRepository::class)->findOneByUuid($uuid);
        $this->assertInstanceOf(AgentRun::class, $run);
        $run->setStatus(AgentRunStatus::Running);
        $run->requestCancellation();
        $entityManager->flush();

        self::getContainer()->get(RunAgentMessageHandler::class)(new RunAgentMessage($uuid));

        $client->request('GET', '/api/agent/runs/' . $uuid);
        $runPayload = $client->getResponse()->toArray();
        $this->assertSame(AgentRunStatus::Cancelled->value, $runPayload['status']);
        $this->assertSame('user_cancelled', $runPayload['stopReason']);
        $this->assertSame(0, $runPayload['iterationCount']);

        $client->request('GET', '/api/agent/runs/' . $uuid . '/steps');
        $this->assertSame([], $client->getResponse()->toArray()['member']);
    }

    public function testCancellingAPendingRunMarksItCancelled(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Do nothing.'],
        ]);
        $uuid = (string) $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/agent/runs/' . $uuid . '/cancel');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(AgentRunStatus::Cancelled->value, $client->getResponse()->toArray()['status']);

        // Cancelling twice reports a conflict.
        $client->request('POST', '/api/agent/runs/' . $uuid . '/cancel');
        $this->assertResponseStatusCodeSame(409);

        // A cancelled run is never executed, even if the message is still consumed.
        self::getContainer()->get(RunAgentMessageHandler::class)(new RunAgentMessage($uuid));

        $client->request('GET', '/api/agent/runs/' . $uuid . '/steps');
        $this->assertSame([], $client->getResponse()->toArray()['member']);
    }

    public function testARunIsPrivateToItsOwner(): void
    {
        $client = $this->authenticatedClient($this->createUserToken('owner@example.com'));
        $this->createWorkspace(['README.md' => "# demo\n"]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Do nothing.'],
        ]);
        $uuid = (string) $client->getResponse()->toArray()['id'];

        $otherClient = $this->authenticatedClient($this->createUserToken('intruder@example.com'));
        $otherClient->request('GET', '/api/agent/runs/' . $uuid);
        $this->assertResponseStatusCodeSame(403);

        $otherClient->request('GET', '/api/agent/runs/' . $uuid . '/steps');
        $this->assertResponseStatusCodeSame(403);

        $otherClient->request('GET', '/api/agent/runs/' . $uuid . '/changes');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnknownRunsReturn404(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());

        $client->request('GET', '/api/agent/runs/00000000-0000-4000-8000-000000000000');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testWorkspacesAndModelsAreListed(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        $client->request('GET', '/api/agent/workspaces');
        $this->assertResponseStatusCodeSame(200);
        $payload = $client->getResponse()->toArray();
        $this->assertContains($this->workspaceName, array_column($payload['member'], 'id'));
        // The API never leaks absolute paths.
        $this->assertStringNotContainsString($this->workspacesRoot(), $client->getResponse()->getContent() ?: '');

        $client->request('GET', '/api/agent/models');
        $this->assertResponseStatusCodeSame(200);
        $models = $client->getResponse()->toArray();
        $this->assertContains('default', array_column($models['member'], 'name'));
        $this->assertSame('default', $models['default']);
    }

    public function testTheRunHistoryIsListedForTheCurrentUser(): void
    {
        $client = $this->authenticatedClient($this->createUserToken());
        $this->createWorkspace(['README.md' => "# demo\n"]);

        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'First task.'],
        ]);
        $client->request('POST', '/api/agent/runs', [
            'json' => ['workspace' => $this->workspaceName, 'task' => 'Second task.'],
        ]);

        $client->request('GET', '/api/agent/runs');
        $this->assertResponseStatusCodeSame(200);
        $runs = $client->getResponse()->toArray();
        $this->assertSame(2, $runs['totalItems']);
        $this->assertSame('Second task.', $runs['member'][0]['task']);

        // Another user does not see them.
        $otherClient = $this->authenticatedClient($this->createUserToken('intruder@example.com'));
        $otherClient->request('GET', '/api/agent/runs');
        $this->assertSame(0, $otherClient->getResponse()->toArray()['totalItems']);
    }

    /**
     * Creates a real workspace directory under the configured workspaces root.
     *
     * @param array<string, string> $files
     */
    private function createWorkspace(array $files): void
    {
        $root = $this->workspacesRoot();
        if (!is_dir($root)) {
            mkdir($root, 0o775, true);
        }

        $this->workspaceName = 'test-' . bin2hex(random_bytes(4));
        $path = $root . '/' . $this->workspaceName;
        mkdir($path, 0o775, true);

        foreach ($files as $relative => $content) {
            if (!is_dir(\dirname($path . '/' . $relative))) {
                mkdir(\dirname($path . '/' . $relative), 0o775, true);
            }
            file_put_contents($path . '/' . $relative, $content);
        }
    }

    private function gitInit(): void
    {
        $path = $this->workspacesRoot() . '/' . $this->workspaceName;

        $commands = [
            ['git', 'init', '--initial-branch=main'],
            ['git', 'config', 'user.email', 'cable-car@example.com'],
            ['git', 'config', 'user.name', 'Cable Car Tests'],
            ['git', 'add', '-A'],
            ['git', 'commit', '-m', 'Initial commit'],
        ];

        foreach ($commands as $command) {
            $process = new Process($command, $path);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), implode(' ', $command) . ': ' . $process->getErrorOutput());
        }
    }

    private function workspacesRoot(): string
    {
        return (string) realpath(self::getContainer()->getParameter('kernel.project_dir')) . '/var/workspaces-test';
    }

    /**
     * @return string the JWT of a fresh user
     */
    private function createUserToken(string $email = 'user@example.com'): string
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        UserFactory::createOne([
            'email' => $email,
            'password' => $hasher->hashPassword(new User(), 'secret'),
        ]);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => $email, 'password' => 'secret'],
        ]);

        return (string) $response->toArray()['token'];
    }

    private function authenticatedClient(string $token): Client
    {
        return static::createClient([], ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
