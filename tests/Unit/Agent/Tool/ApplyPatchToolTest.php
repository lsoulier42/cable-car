<?php

namespace App\Tests\Unit\Agent\Tool;

use App\Agent\Tool\ApplyPatchTool;

final class ApplyPatchToolTest extends ToolTestCase
{
    private const PATCH = <<<'DIFF'
        --- a/src/Demo.php
        +++ b/src/Demo.php
        @@ -1,3 +1,3 @@
         <?php
        -// old comment
        +// new comment
         echo 'demo';
        DIFF;

    public function testItAppliesAPatch(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Demo.php' => "<?php\n// old comment\necho 'demo';\n",
        ]);

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), ['patch' => self::PATCH], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('modified src/Demo.php', $result->getOutput());
        self::assertSame(
            "<?php\n// new comment\necho 'demo';\n",
            file_get_contents($this->tempWorkspaceRoot() . '/src/Demo.php'),
        );
    }

    public function testItCreatesAndDeletesFiles(): void
    {
        $workspace = $this->createTempWorkspace(['src/Old.php' => "<?php\necho 'old';\n"]);

        $patch = <<<'DIFF'
            --- /dev/null
            +++ b/src/New.php
            @@ -0,0 +1,2 @@
            +<?php
            +echo 'new';
            --- a/src/Old.php
            +++ /dev/null
            @@ -1,2 +0,0 @@
            -<?php
            -echo 'old';
            DIFF;

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), ['patch' => $patch], $workspace);

        self::assertTrue($result->isSuccess());
        self::assertFileExists($this->tempWorkspaceRoot() . '/src/New.php');
        self::assertFileDoesNotExist($this->tempWorkspaceRoot() . '/src/Old.php');
    }

    public function testItRefusesToPatchASensitiveFile(): void
    {
        $workspace = $this->createTempWorkspace(['.env' => "APP_SECRET=old\n"]);

        $patch = <<<'DIFF'
            --- a/.env
            +++ b/.env
            @@ -1,1 +1,1 @@
            -APP_SECRET=old
            +APP_SECRET=new
            DIFF;

        $result = $this->execute(new ApplyPatchTool($this->pathGuards(['.env'])), ['patch' => $patch], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('excluded by the workspace policy', (string) $result->getError());
    }

    public function testItRefusesWhenAFileDoesNotApplyAndChangesNothing(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Demo.php' => "<?php\n// something else\necho 'demo';\n",
            'src/Other.php' => "<?php\n// other\n",
        ]);

        $patch = self::PATCH . <<<'DIFF'

            --- a/src/Other.php
            +++ b/src/Other.php
            @@ -1,2 +1,2 @@
             <?php
            -// other
            +// changed
            DIFF;

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), ['patch' => $patch], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('does not apply', (string) $result->getError());
        // The first file must not have been modified: validation happens before any write.
        self::assertSame(
            "<?php\n// something else\necho 'demo';\n",
            file_get_contents($this->tempWorkspaceRoot() . '/src/Demo.php'),
        );
        self::assertSame("<?php\n// other\n", file_get_contents($this->tempWorkspaceRoot() . '/src/Other.php'));
    }

    public function testItRefusesMissingFiles(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), ['patch' => self::PATCH], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('does not exist', (string) $result->getError());
    }

    public function testItRefusesEscapingPaths(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $patch = <<<'DIFF'
            --- /dev/null
            +++ b/../../evil.php
            @@ -0,0 +1,1 @@
            +<?php
            DIFF;

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), ['patch' => $patch], $workspace);

        self::assertFalse($result->isSuccess());
    }

    public function testItReportsGarbagePatches(): void
    {
        $workspace = $this->createTempWorkspace([]);

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), ['patch' => 'not a patch'], $workspace);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Invalid patch', (string) $result->getError());
    }

    public function testItRefusesPatchingADirectory(): void
    {
        $workspace = $this->createTempWorkspace(['src/Demo.php' => "<?php\n// old comment\necho 'demo';\n"]);

        $result = $this->execute(new ApplyPatchTool($this->pathGuards()), [
            'patch' => str_replace('src/Demo.php', 'src', self::PATCH),
        ], $workspace);

        self::assertFalse($result->isSuccess());
    }
}
