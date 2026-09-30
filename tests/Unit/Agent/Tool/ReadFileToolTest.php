<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Tool\ReadFileTool;

final class ReadFileToolTest extends ToolTestCase
{
    public function testItReadsAFile(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n\necho 'hi';\n"]);

        $result = $this->execute(new ReadFileTool($this->pathGuards(['.env'])), ['path' => 'src/Demo.php'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString("echo 'hi';", $result->getOutput());
        self::assertStringContainsString('src/Demo.php (lines 1-3 of 3):', $result->getOutput());
        self::assertSame(3, $result->getMetadata()['lines']);
    }

    public function testItReadsALineRange(): void
    {
        $workspace = $this->createTempWorkspace([
            'file.txt' => implode("\n", ['line 1', 'line 2', 'line 3', 'line 4']) . "\n",
        ]);

        $result = $this->execute(new ReadFileTool($this->pathGuards()), [
            'path' => 'file.txt',
            'start_line' => 2,
            'end_line' => 3,
        ], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('line 2', $result->getOutput());
        self::assertStringContainsString('line 3', $result->getOutput());
        self::assertStringNotContainsString('line 1', $result->getOutput());
        self::assertStringNotContainsString('line 4', $result->getOutput());
    }

    public function testItRefusesSensitiveFiles(): void
    {
        $workspace = $this->createTempWorkspace(['.env' => "APP_SECRET=abc\n"]);

        $result = $this->execute(
            new ReadFileTool($this->pathGuards(['.env', '.env.*'])),
            ['path' => '.env'],
            $workspace,
        );

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('excluded by the workspace policy', (string) $result->getError());
    }

    public function testItRefusesMissingFiles(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);

        $result = $this->execute(new ReadFileTool($this->pathGuards()), ['path' => 'src/Nope.php'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('does not exist', (string) $result->getError());
    }

    public function testItRefusesFilesLargerThanTheLimit(): void
    {
        $workspace = $this->createTempWorkspace([
            'big.txt' => str_repeat("0123456789\n", 1000),
        ]);

        $tool = new ReadFileTool($this->pathGuards());

        $result = $this->execute($tool, ['path' => 'big.txt'], $workspace);
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('too large to read at once', (string) $result->getError());
        self::assertStringContainsString('start_line/end_line', (string) $result->getError());

        $range = $this->execute($tool, ['path' => 'big.txt', 'start_line' => 1, 'end_line' => 10], $workspace);
        self::assertTrue($range->isSuccess());
        self::assertSame(10, $range->getMetadata()['lines']);
    }

    public function testItRefusesBinaryFiles(): void
    {
        $workspace = $this->createTempWorkspace(['bin.dat' => "PK\0\0binary" . random_bytes(10)]);

        $result = $this->execute(new ReadFileTool($this->pathGuards()), ['path' => 'bin.dat'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('binary file', (string) $result->getError());
    }

    public function testItRefusesDirectories(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n"]);

        $result = $this->execute(new ReadFileTool($this->pathGuards()), ['path' => 'src'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('is a directory', (string) $result->getError());
    }
}
