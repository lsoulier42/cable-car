<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Tool\WriteFileTool;

final class WriteFileToolTest extends ToolTestCase
{
    public function testItCreatesAFileAndItsDirectories(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new WriteFileTool($this->pathGuards()), [
            'path' => 'src/Command/VersionCommand.php',
            'content' => "<?php\n// version\n",
        ], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('Created src/Command/VersionCommand.php', $result->getOutput());
        self::assertSame('created', $result->getMetadata()['status']);
        self::assertFileExists($this->tempWorkspaceRoot() . '/src/Command/VersionCommand.php');
        self::assertSame(
            "<?php\n// version\n",
            file_get_contents($this->tempWorkspaceRoot() . '/src/Command/VersionCommand.php'),
        );
    }

    public function testItReplacesAnExistingFile(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n// old\n"]);

        $result = $this->execute(new WriteFileTool($this->pathGuards()), [
            'path' => 'src/Demo.php',
            'content' => "<?php\n// new\n",
        ], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('Updated src/Demo.php', $result->getOutput());
        self::assertSame("<?php\n// new\n", file_get_contents($this->tempWorkspaceRoot() . '/src/Demo.php'));
    }

    public function testItRefusesSensitivePaths(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new WriteFileTool($this->pathGuards(['.env', '*.pem'])), [
            'path' => '.env',
            'content' => "APP_SECRET=x\n",
        ], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('excluded by the workspace policy', (string) $result->getError());
        self::assertFileDoesNotExist($this->tempWorkspaceRoot() . '/.env');
    }

    public function testItRefusesToEscapeTheWorkspace(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new WriteFileTool($this->pathGuards()), [
            'path' => '../outside.php',
            'content' => "<?php\n",
        ], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('escapes the workspace', (string) $result->getError());
    }

    public function testItEnforcesTheSizeLimit(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new WriteFileTool($this->pathGuards()), [
            'path' => 'big.txt',
            'content' => str_repeat('a', 5000),
        ], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('larger than', (string) $result->getError());
    }

    public function testItRefusesDirectories(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);

        $result = $this->execute(new WriteFileTool($this->pathGuards()), [
            'path' => 'src',
            'content' => 'x',
        ], $workspace);

        self::assertFalse($result->isSuccess());
    }

    public function testItRejectsNonStringContent(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new WriteFileTool($this->pathGuards()), [
            'path' => 'src/Demo.php',
            'content' => 42,
        ], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('must be a string', (string) $result->getError());
    }
}
