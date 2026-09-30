<?php

namespace App\Command;

use App\Agent\Exception\WorkspaceException;
use App\Agent\Model\CodingModelRegistry;
use App\Agent\Runner\AgentRunRequest;
use App\Agent\Runner\AgentRunner;
use App\Agent\Runner\CancellationToken;
use App\Agent\Runner\ConsoleAgentObserver;
use App\Agent\Workspace\Workspace;
use App\Agent\Workspace\WorkspaceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console entry point of the harness.
 *
 * It is the fastest way to exercise the exact same runner the HTTP API uses:
 *
 *   php bin/console cable-car:run ./var/workspaces/demo "Fix the failing test"
 */
#[AsCommand(
    name: 'cable-car:run',
    description: 'Run a coding task with the Cable Car agent in a workspace.',
)]
final class RunAgentCommand extends Command
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly WorkspaceManager $workspaces,
        private readonly CodingModelRegistry $models,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'workspace',
                InputArgument::OPTIONAL,
                'Workspace name (directory under the workspaces root) or a path when it contains "/".',
            )
            ->addArgument('task', InputArgument::OPTIONAL, 'The coding task to perform.')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model name from cable_car.models.', null)
            ->addOption('list-models', null, InputOption::VALUE_NONE, 'List the configured models and exit.')
            ->setHelp(<<<'HELP'
                The agent inspects the workspace with read-only tools, edits files with the write
                tools and runs allowed validation commands. Every filesystem operation stays inside
                the workspace, and Cable Car never commits or pushes anything.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list-models')) {
            foreach ($this->models->list() as $model) {
                $output->writeln(sprintf(
                    '%s %s <fg=gray>(%s)%s</>',
                    $model['name'] === $this->models->getDefaultName() ? '*' : ' ',
                    $model['name'],
                    $model['model'],
                    $model['label'] === $model['name'] ? '' : ' — ' . $model['label'],
                ));
            }

            return Command::SUCCESS;
        }

        $task = (string) $input->getArgument('task');
        $workspaceArgument = (string) $input->getArgument('workspace');

        if ('' === $task || '' === $workspaceArgument) {
            $output->writeln('<fg=red>Missing arguments: workspace and task are required.</>');
            $output->writeln('Usage: php bin/console cable-car:run <workspace> "<task>"');

            return Command::INVALID;
        }

        $model = $input->getOption('model');
        $model = \is_string($model) ? $model : null;

        try {
            $workspace = $this->resolveWorkspace($workspaceArgument);
        } catch (WorkspaceException $exception) {
            $output->writeln(sprintf('<fg=red>%s</>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $cancellation = new CancellationToken();
        $this->installSignalHandler($cancellation);

        $observer = new ConsoleAgentObserver($output, $cancellation);

        $outcome = $this->runner->run(
            new AgentRunRequest($workspace, $task, $model),
            $observer,
        );

        return $outcome->isSuccess() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * A leading `./`, `/` or `~`, or any slash, means "this is a path".
     *
     * @throws WorkspaceException
     */
    private function resolveWorkspace(string $argument): Workspace
    {
        if (str_contains($argument, '/') || str_starts_with($argument, '~')) {
            $path = str_starts_with($argument, '~')
                ? (getenv('HOME') ?: '') . substr($argument, 1)
                : $argument;

            return $this->workspaces->fromPath($path);
        }

        return $this->workspaces->get($argument);
    }

    private function installSignalHandler(CancellationToken $cancellation): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($cancellation): void {
            $cancellation->cancel();
        });
        pcntl_signal(SIGTERM, static function () use ($cancellation): void {
            $cancellation->cancel();
        });
    }
}
