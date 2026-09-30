<?php

namespace App\Agent\Process;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs child processes with a strictly controlled environment.
 *
 * Everything spawned by Cable Car goes through this class:
 * - no shell is ever involved (`/usr/bin/env -i <args…> <command…>`);
 * - the environment is emptied and rebuilt from a small allow-list, so
 *   provider secrets, JWT keys and database credentials can never leak into a
 *   command output or a build log;
 * - output is capped and the process is killed when it overflows or times out.
 */
final class SanitizedProcessRunner
{
    public function __construct(
        private readonly string $path,
        private readonly string $home,
        private readonly LoggerInterface $logger,
    ) {
        if (!is_dir($this->home) && !@mkdir($this->home, 0o775, true) && !is_dir($this->home)) {
            $this->logger->warning('Unable to create the agent home directory.', ['home' => $this->home]);
        }
    }

    /**
     * @param list<string>          $argv        command and arguments, never a shell string
     * @param array<string, string> $environment extra variables (the base env is always minimal)
     */
    public function run(
        string $cwd,
        array $argv,
        int $timeoutSeconds,
        int $maxOutputBytes,
        array $environment = [],
    ): ProcessResult {
        $command = [
            '/usr/bin/env',
            '-i',
            'PATH=' . $this->path,
            'HOME=' . $this->home,
            'LANG=C.UTF-8',
            'LC_ALL=C.UTF-8',
            'TERM=dumb',
            'CI=1',
            'GIT_TERMINAL_PROMPT=0',
            'GIT_CONFIG_NOSYSTEM=1',
            'GIT_EDITOR=true',
        ];

        foreach ($environment as $name => $value) {
            $command[] = $name . '=' . $value;
        }

        foreach ($argv as $argument) {
            $command[] = $argument;
        }

        $process = new Process($command, $cwd, null, null, $timeoutSeconds);
        $startedAt = microtime(true);
        $stdout = '';
        $stderr = '';
        $truncated = false;
        $timedOut = false;

        $this->logger->debug('Running command', ['argv' => $argv, 'cwd' => $cwd]);

        try {
            $process->start();

            while ($process->isRunning()) {
                $process->checkTimeout();

                $stdout .= $process->getIncrementalOutput();
                $stderr .= $process->getIncrementalErrorOutput();

                if (\strlen($stdout) + \strlen($stderr) > $maxOutputBytes) {
                    $truncated = true;
                    $process->stop(0.2);
                    break;
                }

                usleep(15000);
            }
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        $stdout .= $process->getIncrementalOutput();
        $stderr .= $process->getIncrementalErrorOutput();

        if ($truncated) {
            $stdout = substr($stdout, 0, $maxOutputBytes);
            $stderr = substr($stderr, 0, $maxOutputBytes);
        }

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? -1,
            stdout: $stdout,
            stderr: $stderr,
            truncated: $truncated,
            timedOut: $timedOut,
            durationMs: (microtime(true) - $startedAt) * 1000,
        );
    }

    /**
     * Locates an executable in the sanitized PATH.
     *
     * @return string|null absolute path when found
     */
    public function locate(string $binary): ?string
    {
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        foreach (explode(':', $this->path) as $directory) {
            if ('' === $directory) {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHome(): string
    {
        return $this->home;
    }
}
