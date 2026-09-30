<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Tool\Command\CommandPolicy;
use App\Agent\Tool\RunCommandTool;
use App\DependencyInjection\Configuration;
use Psr\Log\NullLogger;

final class RunCommandToolTest extends ToolTestCase
{
    private function tool(): RunCommandTool
    {
        $home = sys_get_temp_dir() . '/cable-car-command-home';
        if (!is_dir($home)) {
            mkdir($home, 0o775, true);
        }

        return new RunCommandTool(
            $this->pathGuards(),
            new CommandPolicy([
                'allow' => Configuration::DEFAULT_ALLOWED_COMMANDS,
                'deny_patterns' => Configuration::DEFAULT_DENIED_COMMAND_PATTERNS,
                'git_subcommands' => Configuration::DEFAULT_GIT_SUBCOMMANDS,
                'composer_subcommands' => Configuration::DEFAULT_COMPOSER_SUBCOMMANDS,
                'npm_subcommands' => Configuration::DEFAULT_NPM_SUBCOMMANDS,
                'path' => '/usr/local/bin:/usr/bin:/bin',
            ]),
            new SanitizedProcessRunner('/usr/local/bin:/usr/bin:/bin', $home, new NullLogger()),
        );
    }

    public function testItRunsAnAllowedCommand(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n// fine\n"]);

        $result = $this->execute($this->tool(), ['argv' => ['php', '-l', 'src/Demo.php']], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('No syntax errors detected', $result->getOutput());
        self::assertSame(0, $result->getMetadata()['exit_code']);
    }

    public function testItReportsAFailingValidationWithItsOutput(): void
    {
        $workspace = $this->createTempWorkspace(['broken.php' => "<?php\nthis is not php\n"]);

        $result = $this->execute($this->tool(), ['argv' => ['php', '-l', 'broken.php']], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Parse error', (string) $result->getError());
        self::assertSame(255, $result->getMetadata()['exit_code']);
    }

    public function testItRefusesABlockedCommand(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute($this->tool(), ['command' => 'git push origin main'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('refused by policy', (string) $result->getError());
    }

    public function testItRefusesAnUnknownExecutable(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute($this->tool(), ['argv' => ['ruby', '-e', 'puts 1']], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('not allowed', (string) $result->getError());
    }

    public function testItRefusesAWorkspaceRelativeExecutableThatDoesNotExist(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute($this->tool(), ['argv' => ['vendor/bin/phpunit']], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('does not exist', (string) $result->getError());
    }

    public function testItRunsAWorkspaceRelativeExecutable(): void
    {
        $workspace = $this->createTempWorkspace([
            'vendor/bin/phpunit' => "#!/usr/bin/env php\n<?php echo \"all good\\n\"; exit(0);\n",
        ]);
        chmod($this->tempWorkspaceRoot() . '/vendor/bin/phpunit', 0o755);

        $result = $this->execute($this->tool(), ['argv' => ['vendor/bin/phpunit']], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('all good', $result->getOutput());
        self::assertSame(0, $result->getMetadata()['exit_code']);
    }

    public function testItStopsACommandThatExceedsItsTimeout(): void
    {
        $workspace = $this->createTempWorkspace([
            'vendor/bin/phpunit' => "#!/usr/bin/env php\n<?php sleep(30);\n",
        ]);
        chmod($this->tempWorkspaceRoot() . '/vendor/bin/phpunit', 0o755);

        $result = $this->execute($this->tool(), ['argv' => ['vendor/bin/phpunit'], 'timeout_seconds' => 1], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('timed out', (string) $result->getError());
        self::assertTrue($result->getMetadata()['timed_out']);
    }

    public function testItRefusesArgumentsWithShellSyntax(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute($this->tool(), ['command' => 'git status; rm -rf /'], $workspace);

        self::assertFalse($result->isSuccess());
    }
}
