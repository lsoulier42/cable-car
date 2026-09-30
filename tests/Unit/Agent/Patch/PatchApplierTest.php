<?php

namespace App\Tests\Unit\Agent\Patch;

use App\Agent\Patch\PatchApplier;
use App\Agent\Patch\PatchException;
use App\Agent\Patch\UnifiedDiffParser;
use PHPUnit\Framework\TestCase;

final class PatchApplierTest extends TestCase
{
    private UnifiedDiffParser $parser;

    private PatchApplier $applier;

    protected function setUp(): void
    {
        $this->parser = new UnifiedDiffParser();
        $this->applier = new PatchApplier();
    }

    public function testItReplacesLines(): void
    {
        $original = "<?php\n\nclass Demo\n{\n    public function run(): void\n    {\n        return;\n    }\n}\n";

        $patch = <<<'DIFF'
            --- a/src/Demo.php
            +++ b/src/Demo.php
            @@ -5,5 +5,5 @@
                 public function run(): void
                 {
            -        return;
            +        // nothing to do
                 }
             }
            DIFF;

        $patched = $this->applier->apply($original, $this->parser->parse($patch)[0]);

        self::assertStringContainsString('// nothing to do', $patched);
        self::assertStringNotContainsString('return;', $patched);
        self::assertStringEndsWith("}\n", $patched);
    }

    public function testItCreatesAFile(): void
    {
        $patch = <<<'DIFF'
            --- /dev/null
            +++ b/src/Command/VersionCommand.php
            @@ -0,0 +1,4 @@
            +<?php
            +
            +// version command
            +echo "1.0";
            DIFF;

        $filePatch = $this->parser->parse($patch)[0];
        $patched = $this->applier->apply('', $filePatch);

        self::assertTrue($filePatch->isNew());
        self::assertSame("<?php\n\n// version command\necho \"1.0\";\n", $patched);
    }

    public function testItDeletesAFile(): void
    {
        $patch = <<<'DIFF'
            --- a/src/Old.php
            +++ /dev/null
            @@ -1,2 +0,0 @@
            -<?php
            -echo 'old';
            DIFF;

        $filePatch = $this->parser->parse($patch)[0];

        self::assertTrue($filePatch->isDeleted());
        self::assertSame('', $this->applier->apply("<?php\necho 'old';\n", $filePatch));
    }

    public function testItAppliesSeveralHunks(): void
    {
        $original = implode("\n", array_map(static fn (int $i): string => 'line ' . $i, range(1, 30))) . "\n";

        $patch = <<<'DIFF'
            --- a/file.txt
            +++ b/file.txt
            @@ -2,3 +2,3 @@
             line 2
            -line 3
            +line three
             line 4
            @@ -20,3 +20,3 @@
             line 20
            -line 21
            +line twenty-one
             line 22
            DIFF;

        $patched = $this->applier->apply($original, $this->parser->parse($patch)[0]);

        self::assertStringContainsString('line three', $patched);
        self::assertStringContainsString('line twenty-one', $patched);
        self::assertStringNotContainsString('line 21', $patched);
    }

    public function testItToleratesAnOffsetInTheAnnouncedLineNumbers(): void
    {
        $original = "alpha\nbeta\ngamma\ndelta\n";

        $patch = <<<'DIFF'
            --- a/file.txt
            +++ b/file.txt
            @@ -10,3 +10,3 @@
             beta
            -gamma
            +GAMMA
             delta
            DIFF;

        $patched = $this->applier->apply($original, $this->parser->parse($patch)[0]);

        self::assertSame("alpha\nbeta\nGAMMA\ndelta\n", $patched);
    }

    public function testItRefusesAHunkWhoseContextDoesNotMatch(): void
    {
        $original = "alpha\nbeta\ngamma\n";

        $patch = <<<'DIFF'
            --- a/file.txt
            +++ b/file.txt
            @@ -1,3 +1,3 @@
             alpha
            -beta
            +BETA
             delta
            DIFF;

        $this->expectException(PatchException::class);
        $this->expectExceptionMessageMatches('/does not apply/');

        $this->applier->apply($original, $this->parser->parse($patch)[0]);
    }

    public function testItRefusesAnExistingFileAsNewFile(): void
    {
        $patch = <<<'DIFF'
            --- /dev/null
            +++ b/src/Demo.php
            @@ -0,0 +1,1 @@
            +<?php
            DIFF;

        $this->expectException(PatchException::class);
        $this->expectExceptionMessageMatches('/already exists/');

        $this->applier->apply("<?php\n", $this->parser->parse($patch)[0]);
    }

    public function testItKeepsTheTrailingNewlineState(): void
    {
        $patch = <<<'DIFF'
            --- a/file.txt
            +++ b/file.txt
            @@ -1,2 +1,2 @@
             alpha
            -beta
            +BETA
            DIFF;

        $withNewline = $this->applier->apply("alpha\nbeta\n", $this->parser->parse($patch)[0]);
        $withoutNewline = $this->applier->apply("alpha\nbeta", $this->parser->parse($patch)[0]);

        self::assertSame("alpha\nBETA\n", $withNewline);
        self::assertSame("alpha\nBETA", $withoutNewline);
    }

    public function testItRejectsGarbage(): void
    {
        $this->expectException(PatchException::class);

        $this->parser->parse('this is not a diff at all');
    }

    public function testItRejectsRenames(): void
    {
        $patch = <<<'DIFF'
            --- a/src/Old.php
            +++ b/src/New.php
            @@ -1,1 +1,1 @@
            -<?php
            +<?php
            DIFF;

        $this->expectException(PatchException::class);
        $this->expectExceptionMessageMatches('/Renames are not supported/');

        $this->parser->parse($patch);
    }

    public function testItStripsTheAbPrefixFromPaths(): void
    {
        $patch = <<<'DIFF'
            --- a/src/Demo.php
            +++ b/src/Demo.php
            @@ -1,1 +1,1 @@
            -<?php
            +<?php
            DIFF;

        self::assertSame('src/Demo.php', $this->parser->parse($patch)[0]->getPath());
    }

    public function testItIgnoresProseAroundTheDiff(): void
    {
        $patch = <<<'DIFF'
            Here is the patch you asked for:

            --- a/file.txt
            +++ b/file.txt
            @@ -1,1 +1,1 @@
            -hello
            +HELLO

            Let me know if it works!
            DIFF;

        $patched = $this->applier->apply("hello\n", $this->parser->parse($patch)[0]);

        self::assertSame("HELLO\n", $patched);
    }
}
