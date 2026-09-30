<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Tool\SearchTool;
use Psr\Log\NullLogger;

final class SearchToolTest extends ToolTestCase
{
    private function tool(): SearchTool
    {
        $home = sys_get_temp_dir() . '/cable-car-search-home';
        if (!is_dir($home)) {
            mkdir($home, 0o775, true);
        }

        return new SearchTool(
            $this->pathGuards(['.env', '.env.*', '*.pem'], ['vendor', 'node_modules', '.git']),
            new SanitizedProcessRunner('/usr/local/bin:/usr/bin:/bin', $home, new NullLogger()),
        );
    }

    public function testItFindsMatchesAcrossTheWorkspace(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Controller/MeController.php' => "<?php\nclass MeController\n{\n    public function show() {}\n}\n",
            'src/Entity/User.php' => "<?php\nclass User {}\n",
            'README.md' => "# Demo\n",
        ]);

        $result = $this->execute($this->tool(), ['query' => 'class \w+'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('src/Controller/MeController.php:2:', $result->getOutput());
        self::assertStringContainsString('src/Entity/User.php:2:', $result->getOutput());
    }

    public function testItSupportsGlobs(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Demo.php' => "<?php\nMARKER\n",
            'frontend/demo.ts' => "MARKER\n",
        ]);

        $result = $this->execute($this->tool(), ['query' => 'MARKER', 'glob' => '*.ts'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('frontend/demo.ts', $result->getOutput());
        self::assertStringNotContainsString('src/Demo.php', $result->getOutput());
    }

    public function testItNeverReturnsMatchesFromSensitiveFiles(): void
    {
        $workspace = $this->createTempWorkspace([
            '.env' => "SECRET_MARKER=abc\n",
            'src/Demo.php' => "<?php // SECRET_MARKER\n",
        ]);

        $result = $this->execute($this->tool(), ['query' => 'SECRET_MARKER'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('src/Demo.php', $result->getOutput());
        self::assertStringNotContainsString('.env', $result->getOutput());
        self::assertStringNotContainsString('SECRET_MARKER=abc', $result->getOutput());
    }

    public function testItSkipsIgnoredDirectories(): void
    {
        $workspace = $this->createTempWorkspace([
            'vendor/lib/Demo.php' => "<?php // MARKER\n",
            'src/Demo.php' => "<?php // MARKER\n",
        ]);

        $result = $this->execute($this->tool(), ['query' => 'MARKER'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('src/Demo.php', $result->getOutput());
        self::assertStringNotContainsString('vendor/', $result->getOutput());
    }

    public function testItCapsTheNumberOfResults(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Demo.php' => implode("\n", array_fill(0, 50, 'MATCH')) . "\n",
        ]);

        $result = $this->execute($this->tool(), ['query' => 'MATCH', 'max_results' => 5], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertSame(5, $result->getMetadata()['matches']);
        self::assertStringContainsString('limited to 5 matches', $result->getOutput());
    }

    public function testItReportsWhenNothingMatches(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);

        $result = $this->execute($this->tool(), ['query' => 'NOTHING_HERE'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->getMetadata()['matches']);
    }

    public function testItRefusesToEscapeTheWorkspace(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);

        $result = $this->execute($this->tool(), ['query' => 'php', 'path' => '../..'], $workspace);

        self::assertFalse($result->isSuccess());
    }

    public function testItRejectsAnEmptyQuery(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);

        $result = $this->execute($this->tool(), ['query' => '  '], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('must not be empty', (string) $result->getError());
    }
}
