<?php

namespace App\Agent\Tool\Command;

use App\Agent\Exception\CommandPolicyException;

/**
 * Decides whether a command may run, and in which shape.
 *
 * The policy is an allow-list: an executable is accepted only when it is
 * declared in `cable_car.commands.allow`, git/composer/npm subcommands are
 * checked against their own lists, and a set of regexes rejects anything that
 * would mutate remote state (push, remote, …) or shell out.
 */
final class CommandPolicy
{
    /** @var list<string> */
    private readonly array $allowedExecutables;

    /** @var list<string> */
    private readonly array $deniedPatterns;

    /** @var list<string> */
    private readonly array $gitSubcommands;

    /** @var list<string> */
    private readonly array $composerSubcommands;

    /** @var list<string> */
    private readonly array $npmSubcommands;

    private readonly string $path;

    /**
     * @param array{
     *     allow: list<string>,
     *     deny_patterns: list<string>,
     *     git_subcommands: list<string>,
     *     composer_subcommands: list<string>,
     *     npm_subcommands: list<string>,
     *     path: string
     * } $commandsConfig
     */
    public function __construct(array $commandsConfig)
    {
        $this->allowedExecutables = $commandsConfig['allow'];
        $this->deniedPatterns = $commandsConfig['deny_patterns'];
        $this->gitSubcommands = $commandsConfig['git_subcommands'];
        $this->composerSubcommands = $commandsConfig['composer_subcommands'];
        $this->npmSubcommands = $commandsConfig['npm_subcommands'];
        $this->path = $commandsConfig['path'];
    }

    /**
     * Normalizes the model input into an argv array.
     *
     * Refuses anything that looks like shell syntax: Cable Car never starts a
     * shell, so `;`, `|`, `>`, backticks, `$VAR` or `&&` have no meaning.
     *
     * @param array<string, mixed> $input
     *
     * @return list<string>
     *
     * @throws CommandPolicyException
     */
    public function normalize(array $input): array
    {
        $arguments = $input['argv'] ?? null;

        if (\is_array($arguments)) {
            $argv = [];
            foreach ($arguments as $argument) {
                if (!\is_string($argument)) {
                    throw new CommandPolicyException('Every entry of "argv" must be a string.');
                }

                $argv[] = $argument;
            }
        } elseif (isset($input['command']) && \is_string($input['command'])) {
            $argv = $this->tokenize($input['command']);
        } else {
            throw new CommandPolicyException(
                'Provide the command as "argv" (array of arguments, preferred) or as a "command" string.',
            );
        }

        if ([] === $argv || '' === trim($argv[0])) {
            throw new CommandPolicyException('The command is empty.');
        }

        if (\count($argv) > 24) {
            throw new CommandPolicyException('Too many arguments (limit 24); split the command.');
        }

        foreach ($argv as $argument) {
            if (\strlen($argument) > 512) {
                throw new CommandPolicyException('An argument is longer than 512 characters.');
            }

            if (str_contains($argument, "\0") || str_contains($argument, "\n")) {
                throw new CommandPolicyException('Arguments must not contain control characters.');
            }
        }

        return $argv;
    }

    /**
     * @param list<string> $argv
     *
     * @throws CommandPolicyException
     */
    public function assertAllowed(array $argv): void
    {
        $commandLine = implode(' ', $argv);

        foreach ($this->deniedPatterns as $pattern) {
            if (1 === @preg_match($pattern, $commandLine)) {
                throw new CommandPolicyException(sprintf(
                    'Command refused by policy (matches "%s"). '
                    . 'Cable Car never pushes, commits, downloads or shells out.',
                    $pattern,
                ));
            }
        }

        $executable = $argv[0];
        $normalized = ltrim($executable, './');

        if (!\in_array($normalized, $this->allowedExecutables, true)) {
            throw new CommandPolicyException(sprintf(
                'Executable "%s" is not allowed. Allowed executables: %s.',
                $executable,
                implode(', ', $this->allowedExecutables),
            ));
        }

        $this->assertSubcommand($normalized, $argv);
    }

    /**
     * @param list<string> $argv
     *
     * @throws CommandPolicyException
     */
    private function assertSubcommand(string $executable, array $argv): void
    {
        $subcommand = $this->firstNonOption($argv, 1);

        $lists = [
            'git' => [$this->gitSubcommands, 'git'],
            'composer' => [$this->composerSubcommands, 'composer'],
            'npm' => [$this->npmSubcommands, 'npm'],
            'npx' => [$this->npmSubcommands, 'npx'],
        ];

        if (isset($lists[$executable]) && null !== $subcommand) {
            [$allowed, $label] = $lists[$executable];
            if (!\in_array($subcommand, $allowed, true)) {
                throw new CommandPolicyException(sprintf(
                    '"%s %s" is not allowed. Allowed %s subcommands: %s.',
                    $executable,
                    $subcommand,
                    $label,
                    implode(', ', $allowed),
                ));
            }
        }

        // "php" is only allowed to lint a file or run the project's own scripts/binaries.
        if ('php' === $executable) {
            $arguments = \array_slice($argv, 1);
            $target = ltrim($this->firstNonOption($argv, 1) ?? '', './');

            $isLint = \in_array('-l', $arguments, true) || \in_array('--lint', $arguments, true);
            $isProjectScript = false;

            foreach (['bin/console', 'bin/composer', 'vendor/bin/'] as $allowedPhpTarget) {
                if (str_starts_with($target, $allowedPhpTarget)) {
                    $isProjectScript = true;
                    break;
                }
            }

            if (!$isLint && !$isProjectScript) {
                throw new CommandPolicyException(sprintf(
                    '"php %s" is not allowed: use "php bin/console …", "php vendor/bin/…" or "php -l <file>".',
                    $target,
                ));
            }
        }
    }

    /**
     * First argument that is not an option (`-x`, `--xyz`).
     *
     * @param list<string> $argv
     */
    private function firstNonOption(array $argv, int $offset): ?string
    {
        for ($index = $offset, $count = \count($argv); $index < $count; ++$index) {
            $argument = $argv[$index];
            if (!str_starts_with($argument, '-')) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Splits a command string into arguments without ever invoking a shell.
     *
     * @return list<string>
     *
     * @throws CommandPolicyException
     */
    private function tokenize(string $command): array
    {
        $arguments = [];
        $current = '';
        $quote = null;
        $length = \strlen($command);

        for ($index = 0; $index < $length; ++$index) {
            $character = $command[$index];

            if (null !== $quote) {
                if ($character === $quote) {
                    $quote = null;
                    continue;
                }

                $current .= $character;
                continue;
            }

            if ('"' === $character || "'" === $character) {
                $quote = $character;
                continue;
            }

            if (ctype_space($character)) {
                if ('' !== $current) {
                    $arguments[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $character;
        }

        if (null !== $quote) {
            throw new CommandPolicyException('Unbalanced quote in the command.');
        }

        if ('' !== $current) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * @return list<string>
     */
    public function getAllowedExecutables(): array
    {
        return $this->allowedExecutables;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
