<?php

namespace App\Agent\Tool;

use App\Agent\Exception\ToolFailureException;
use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Tool\Command\CommandPolicy;
use App\Agent\Workspace\PathGuard;
use App\Agent\Workspace\PathGuardFactory;

/**
 * Runs an allowed project command (tests, linters, static analysis, builds).
 *
 * This is the highest-risk tool of the harness, so it is constrained on every
 * axis: allow-listed executables, no shell, workspace as working directory,
 * sanitized environment, timeout and output cap.
 */
final class RunCommandTool extends AbstractTool
{
    public function __construct(
        PathGuardFactory $pathGuards,
        private readonly CommandPolicy $policy,
        private readonly SanitizedProcessRunner $processRunner,
    ) {
        parent::__construct($pathGuards);
    }

    public function getName(): string
    {
        return 'run_command';
    }

    public function getDescription(): string
    {
        return 'Run an allowed project command (tests, linters, static analysis, builds, read-only git commands) '
            . 'inside the workspace and return its output. Prefer "argv", an array of arguments, without shell. '
            . 'Allowed: ' . implode(', ', $this->policy->getAllowedExecutables()) . '. '
            . 'Always refused: git push/commit/reset, network downloads, shell pipes and redirections. '
            . 'The command never sees the application environment or its secrets.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'argv' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Command and arguments, e.g. ["vendor/bin/phpunit", "--filter", "MeTest"].',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Alternative string form, e.g. "vendor/bin/phpunit --filter MeTest".',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional shorter timeout; the configured maximum always applies.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function doExecute(array $input, ToolContext $context): ToolResult
    {
        $argv = $this->policy->normalize($input);
        $this->policy->assertAllowed($argv);

        $limits = $context->getLimits();
        $maxCommandSeconds = $limits->getMaxCommandSeconds();
        $timeout = $this->intInput($input, 'timeout_seconds', $maxCommandSeconds, 1, $maxCommandSeconds);

        $workspace = $context->getWorkspace();
        $guard = $this->guard($context);

        $executable = $this->resolveExecutable($argv, $workspace->getRoot(), $guard);
        $argv[0] = $executable;

        $result = $this->processRunner->run(
            $workspace->getRoot(),
            $argv,
            $timeout,
            $limits->getMaxToolOutputBytes(),
        );

        $output = trim($result->getCombinedOutput());
        if ($result->isTruncated()) {
            $output .= sprintf("\n… [output truncated at %s]", $this->humanSize($limits->getMaxToolOutputBytes()));
        }

        $metadata = [
            'argv' => $argv,
            'exit_code' => $result->getExitCode(),
            'duration_ms' => (int) round($result->getDurationMs()),
            'timed_out' => $result->isTimedOut(),
        ];

        if ($result->isTimedOut()) {
            return ToolResult::failure(sprintf(
                "Command timed out after %d seconds:\n%s\n%s",
                $timeout,
                implode(' ', $argv),
                $output,
            ), $metadata);
        }

        $summary = sprintf(
            '$ %s\nexit code: %d (%.1f s)',
            implode(' ', $argv),
            $result->getExitCode(),
            $result->getDurationMs() / 1000,
        );

        $body = $summary . ("\n\n" . $output);

        if (!$result->isSuccessful()) {
            return ToolResult::failure($body, $metadata);
        }

        return ToolResult::success($body, $metadata);
    }

    /**
     * Resolves the executable to an absolute path.
     *
     * Workspace-relative paths (`bin/console`, `vendor/bin/phpunit`) must stay
     * inside the workspace; bare names (`php`, `git`) are looked up in the
     * sanitized PATH only — never in the caller's environment.
     *
     * @param list<string> $argv
     *
     * @throws ToolFailureException
     */
    private function resolveExecutable(array $argv, string $workspaceRoot, PathGuard $guard): string
    {
        $executable = ltrim($argv[0], './');

        if (str_contains($executable, '/')) {
            $absolute = $guard->resolve($executable, true);
            if (!is_file($absolute) || !is_executable($absolute)) {
                throw new ToolFailureException(sprintf(
                    '"%s" is not an executable file in the workspace.',
                    $executable,
                ));
            }

            return $absolute;
        }

        $located = $this->processRunner->locate($executable);
        if (null === $located) {
            throw new ToolFailureException(sprintf(
                '"%s" is not available on this system (PATH: %s).',
                $executable,
                $this->processRunner->getPath(),
            ));
        }

        return $located;
    }
}
