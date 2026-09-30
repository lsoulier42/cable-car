<?php

namespace App\Tests\Unit\Agent\Process;

use App\Agent\Process\SanitizedProcessRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SanitizedProcessRunnerTest extends TestCase
{
    private string $home;

    private SanitizedProcessRunner $runner;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/cable-car-process-home-' . bin2hex(random_bytes(4));
        $this->runner = new SanitizedProcessRunner('/usr/local/bin:/usr/bin:/bin', $this->home, new NullLogger());
    }

    public function testItRunsACommandAndCapturesTheOutput(): void
    {
        $result = $this->runner->run(sys_get_temp_dir(), ['/usr/bin/printf', 'hello world'], 10, 1024);

        self::assertTrue($result->isSuccessful());
        self::assertSame('hello world', $result->getStdout());
        self::assertSame(0, $result->getExitCode());
        self::assertFalse($result->isTimedOut());
        self::assertGreaterThan(0, $result->getDurationMs());
    }

    public function testItDoesNotLeakTheApplicationEnvironment(): void
    {
        putenv('CABLE_CAR_TEST_SECRET=super-secret');

        $result = $this->runner->run(sys_get_temp_dir(), ['/usr/bin/printenv', 'CABLE_CAR_TEST_SECRET'], 10, 1024);

        self::assertSame(1, $result->getExitCode());
        self::assertSame('', trim($result->getStdout()));
        self::assertStringNotContainsString('super-secret', $result->getCombinedOutput());

        $path = $this->runner->run(sys_get_temp_dir(), ['/usr/bin/printenv', 'PATH'], 10, 1024);
        self::assertSame('/usr/local/bin:/usr/bin:/bin', trim($path->getStdout()));
    }

    public function testItIsNotAffectedByTheCallersWorkingDirectory(): void
    {
        $workspace = sys_get_temp_dir() . '/cable-car-cwd-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0o775, true);
        file_put_contents($workspace . '/marker.txt', 'x');

        $result = $this->runner->run($workspace, ['/bin/ls', '-1'], 10, 1024);

        self::assertSame('marker.txt', trim($result->getStdout()));

        unlink($workspace . '/marker.txt');
        rmdir($workspace);
    }

    public function testItStopsACommandThatExceedsItsTimeout(): void
    {
        $result = $this->runner->run(sys_get_temp_dir(), [PHP_BINARY, '-r', 'sleep(30);'], 1, 1024);

        self::assertTrue($result->isTimedOut());
        self::assertFalse($result->isSuccessful());
    }

    public function testItCapsTheOutputSize(): void
    {
        $result = $this->runner->run(
            sys_get_temp_dir(),
            [PHP_BINARY, '-r', 'echo str_repeat("x", 200000);'],
            30,
            1000,
        );

        self::assertTrue($result->isTruncated());
        self::assertLessThanOrEqual(2000, \strlen($result->getStdout()));
    }

    public function testItReportsTheExitCodeAndErrors(): void
    {
        $result = $this->runner->run(sys_get_temp_dir(), ['/usr/bin/printf', 'boom'], 10, 1024);
        self::assertTrue($result->isSuccessful());

        $failure = $this->runner->run(
            sys_get_temp_dir(),
            [PHP_BINARY, '-r', 'fwrite(STDERR, "kaboom"); exit(3);'],
            10,
            1024,
        );

        self::assertSame(3, $failure->getExitCode());
        self::assertFalse($failure->isSuccessful());
        self::assertStringContainsString('kaboom', $failure->getCombinedOutput());
    }

    public function testItLocatesExecutablesInTheSanitizedPath(): void
    {
        self::assertSame('/usr/bin/printenv', $this->runner->locate('printenv'));
        self::assertNull($this->runner->locate('definitely-not-a-binary'));
    }
}
