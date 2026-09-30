<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Tool\ListFilesTool;

final class ListFilesToolTest extends ToolTestCase
{
    public function testItListsTheWorkspaceRoot(): void
    {
        $workspace = $this->createTempWorkspace([
            'composer.json' => '{}',
            'README.md' => '# Demo',
            'src/Controller/MeController.php' => "<?php\n",
            'vendor/autoload.php' => "<?php\n",
        ]);

        $result = $this->execute(new ListFilesTool($this->pathGuards([], ['vendor'])), ['path' => '.'], $workspace);

        self::assertTrue($result->isSuccess());
        $output = $result->getOutput();
        self::assertStringContainsString('composer.json', $output);
        self::assertStringContainsString('README.md', $output);
        self::assertStringContainsString('src/', $output);
        self::assertStringNotContainsString('vendor/', $output);
        self::assertSame('list_files', (new ListFilesTool($this->pathGuards()))->getName());
    }

    public function testItRecursesUpToTheRequestedDepth(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Controller/MeController.php' => "<?php\n",
            'src/Entity/User.php' => "<?php\n",
        ]);

        $tool = new ListFilesTool($this->pathGuards());

        $shallow = $this->execute($tool, ['path' => '.', 'depth' => 1], $workspace);
        self::assertStringContainsString('src/', $shallow->getOutput());
        self::assertStringNotContainsString('MeController.php', $shallow->getOutput());

        $deep = $this->execute($tool, ['path' => '.', 'depth' => 3], $workspace);
        self::assertStringContainsString('src/', $deep->getOutput());
        self::assertStringContainsString('Controller/', $deep->getOutput());
        self::assertStringContainsString('MeController.php', $deep->getOutput());
    }

    public function testItRefusesToEscapeTheWorkspace(): void
    {
        $workspace = $this->createTempWorkspace(['src/File.php' => "<?php\n"]);

        $result = $this->execute(new ListFilesTool($this->pathGuards()), ['path' => '../../etc'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('escapes the workspace', (string) $result->getError());
    }

    public function testItRefusesFiles(): void
    {
        $workspace = $this->createTempWorkspace(['composer.json' => '{}']);

        $result = $this->execute(new ListFilesTool($this->pathGuards()), ['path' => 'composer.json'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('not a directory', (string) $result->getError());
    }

    public function testItCapsTheNumberOfEntries(): void
    {
        $files = [];
        for ($i = 0; $i < 60; ++$i) {
            $files[sprintf('file-%02d.txt', $i)] = 'x';
        }

        $workspace = $this->createTempWorkspace($files);

        $result = $this->execute(new ListFilesTool($this->pathGuards()), ['path' => '.'], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('listing truncated at 50 entries', $result->getOutput());
        self::assertSame(50, $result->getMetadata()['entries']);
    }
}
