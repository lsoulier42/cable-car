<?php

namespace App\Tests\Unit\Agent\Tool\Command;

use App\DependencyInjection\Configuration;
use App\Agent\Exception\CommandPolicyException;
use App\Agent\Tool\Command\CommandPolicy;
use PHPUnit\Framework\TestCase;

final class CommandPolicyTest extends TestCase
{
    private function policy(): CommandPolicy
    {
        return new CommandPolicy([
            'allow' => ['php', 'bin/console', 'composer', 'vendor/bin/phpunit', 'node', 'npm', 'git'],
            'deny_patterns' => Configuration::DEFAULT_DENIED_COMMAND_PATTERNS,
            'git_subcommands' => Configuration::DEFAULT_GIT_SUBCOMMANDS,
            'composer_subcommands' => Configuration::DEFAULT_COMPOSER_SUBCOMMANDS,
            'npm_subcommands' => Configuration::DEFAULT_NPM_SUBCOMMANDS,
            'path' => '/usr/bin:/bin',
        ]);
    }

    public function testItAcceptsAnArgvArray(): void
    {
        $argv = $this->policy()->normalize(['argv' => ['vendor/bin/phpunit', '--filter', 'MeTest']]);

        self::assertSame(['vendor/bin/phpunit', '--filter', 'MeTest'], $argv);
    }

    public function testItTokenizesACommandString(): void
    {
        $argv = $this->policy()->normalize(['command' => 'vendor/bin/phpunit --filter "Me Test"']);

        self::assertSame(['vendor/bin/phpunit', '--filter', 'Me Test'], $argv);
    }

    public function testItRejectsUnbalancedQuotes(): void
    {
        $this->expectException(CommandPolicyException::class);

        $this->policy()->normalize(['command' => 'vendor/bin/phpunit --filter "Me Test']);
    }

    public function testItRejectsUnknownExecutables(): void
    {
        $this->expectException(CommandPolicyException::class);
        $this->expectExceptionMessageMatches('/is not allowed/');

        $this->policy()->assertAllowed(['ruby', 'script.rb']);
    }

    public function testItBlocksGitPush(): void
    {
        $this->expectException(CommandPolicyException::class);

        $this->policy()->assertAllowed(['git', 'push', 'origin', 'main']);
    }

    public function testItBlocksMutatingGitSubcommands(): void
    {
        $mutating = [['commit', '-m', 'x'], ['reset', '--hard'], ['clean', '-fd'], ['checkout', '--', 'src']];

        foreach ($mutating as $subcommand) {
            try {
                $this->policy()->assertAllowed(array_merge(['git'], $subcommand));
                self::fail(sprintf('git %s should be refused.', $subcommand[0]));
            } catch (CommandPolicyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItAllowsReadOnlyGitSubcommands(): void
    {
        $this->policy()->assertAllowed(['git', 'status', '--short']);
        $this->policy()->assertAllowed(['git', 'diff']);
        $this->policy()->assertAllowed(['git', 'log', '--oneline', '-5']);

        $this->addToAssertionCount(3);
    }

    public function testItBlocksShellCharacters(): void
    {
        $shellCommands = [
            'git status; rm -rf /',
            'git status | tee x',
            'php -r "system(\'ls\')"',
            'git log `whoami`',
        ];

        foreach ($shellCommands as $command) {
            try {
                $argv = $this->policy()->normalize(['command' => $command]);
                $this->policy()->assertAllowed($argv);
                self::fail(sprintf('"%s" should be refused.', $command));
            } catch (CommandPolicyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItBlocksNetworkDownloads(): void
    {
        foreach ([['curl', 'https://example.com'], ['wget', 'https://example.com']] as $argv) {
            try {
                $this->policy()->assertAllowed($argv);
                self::fail(sprintf('"%s" should be refused.', implode(' ', $argv)));
            } catch (CommandPolicyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItRestrictsComposerAndNpmSubcommands(): void
    {
        $this->policy()->assertAllowed(['composer', 'install', '--no-interaction']);
        $this->policy()->assertAllowed(['npm', 'run', 'build']);
        $this->addToAssertionCount(2);

        $this->expectException(CommandPolicyException::class);
        $this->policy()->assertAllowed(['composer', 'config', '--global', 'github-oauth.github.com', 'x']);
    }

    public function testItRestrictsNpxToKnownSubcommands(): void
    {
        $this->expectException(CommandPolicyException::class);

        $this->policy()->assertAllowed(['npx', 'dangerous-tool']);
    }

    public function testItRestrictsPhpToProjectScripts(): void
    {
        $this->policy()->assertAllowed(['php', 'bin/console', 'list']);
        $this->policy()->assertAllowed(['php', '-l', 'src/Demo.php']);
        $this->addToAssertionCount(2);

        foreach ([['php', '-r', 'readfile("/etc/passwd");'], ['php', 'arbitrary.php']] as $argv) {
            try {
                $this->policy()->assertAllowed($argv);
                self::fail(sprintf('"%s" should be refused.', implode(' ', $argv)));
            } catch (CommandPolicyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItRejectsTooManyArguments(): void
    {
        $this->expectException(CommandPolicyException::class);

        $this->policy()->normalize(['argv' => array_fill(0, 30, 'x')]);
    }

    public function testItRejectsControlCharactersInArguments(): void
    {
        $this->expectException(CommandPolicyException::class);

        $this->policy()->normalize(['argv' => ['git', "status\nrm -rf /"]]);
    }
}
