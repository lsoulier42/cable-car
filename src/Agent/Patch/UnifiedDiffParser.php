<?php

namespace App\Agent\Patch;

/**
 * Minimal unified diff parser.
 *
 * It accepts the format produced by `git diff` and by language models:
 *
 *   --- a/src/Foo.php
 *   +++ b/src/Foo.php
 *   @@ -12,7 +12,9 @@
 *     context
 *   -removed
 *   +added
 *
 * Anything it does not understand (binary patches, rename headers, …) is
 * rejected instead of being applied blindly.
 */
final class UnifiedDiffParser
{
    private const MAX_FILES = 20;

    /**
     * @return list<FilePatch>
     *
     * @throws PatchException
     */
    public function parse(string $patch): array
    {
        $patch = str_replace("\r\n", "\n", trim($patch, "\n"));
        if ('' === trim($patch)) {
            throw new PatchException('The patch is empty.');
        }

        $lines = explode("\n", $patch);
        $count = \count($lines);

        $files = [];
        $index = 0;

        while ($index < $count) {
            // Skip everything until a file header (some models add prose).
            if (!str_starts_with($lines[$index], '--- ')) {
                ++$index;
                continue;
            }

            [$file, $index] = $this->parseFile($lines, $index, $count);
            $files[] = $file;

            if (\count($files) > self::MAX_FILES) {
                throw new PatchException(sprintf('The patch touches more than %d files; split it.', self::MAX_FILES));
            }
        }

        if ([] === $files) {
            throw new PatchException(
                'No file header found: a unified diff must start with "--- a/path" and "+++ b/path".',
            );
        }

        return $files;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: FilePatch, 1: int}
     */
    private function parseFile(array $lines, int $index, int $count): array
    {
        $oldHeader = $lines[$index];
        ++$index;

        if ($index >= $count || !str_starts_with($lines[$index], '+++ ')) {
            throw new PatchException(sprintf('Missing "+++ " header after "%s".', trim($oldHeader)));
        }

        $newHeader = $lines[$index];
        ++$index;

        $oldPath = $this->normalizePath(substr($oldHeader, 4));
        $newPath = $this->normalizePath(substr($newHeader, 4));

        $isNew = '/dev/null' === $oldPath || null === $oldPath;
        $isDeleted = '/dev/null' === $newPath;

        $path = $isDeleted ? (string) $oldPath : (string) $newPath;
        if ('' === $path || '/dev/null' === $path) {
            throw new PatchException('Unable to determine the file path from the patch headers.');
        }

        if (!$isNew && !$isDeleted && $oldPath !== $newPath) {
            throw new PatchException(sprintf('Renames are not supported ("%s" → "%s").', $oldPath, $newPath));
        }

        $hunks = [];
        while ($index < $count) {
            $line = $lines[$index];

            if (str_starts_with($line, '--- ')) {
                break;
            }

            if (str_starts_with($line, '@@')) {
                [$hunk, $index] = $this->parseHunk($lines, $index, $count);
                $hunks[] = $hunk;
                continue;
            }

            if ('' === trim($line)) {
                ++$index;
                continue;
            }

            // Trailing prose or a footer after the last hunk: the file section is over.
            if (str_starts_with($line, '+') || str_starts_with($line, '-') || str_starts_with($line, '@')) {
                throw new PatchException(sprintf('Unexpected line in patch: "%s".', mb_substr($line, 0, 80)));
            }

            break;
        }

        if ([] === $hunks && !$isNew && !$isDeleted) {
            throw new PatchException(sprintf('No hunk found for "%s".', $path));
        }

        return [new FilePatch($path, $isNew, $isDeleted, $hunks), $index];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: Hunk, 1: int}
     */
    private function parseHunk(array $lines, int $index, int $count): array
    {
        $header = $lines[$index];
        ++$index;

        if (1 !== preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $header, $matches)) {
            throw new PatchException(sprintf('Invalid hunk header "%s".', trim($header)));
        }

        $oldStart = (int) $matches[1];
        $oldCount = '' === $matches[2] ? 1 : (int) $matches[2];
        $newStart = (int) $matches[3];
        $newCount = isset($matches[4]) ? (int) $matches[4] : 1;

        $hunkLines = [];
        $readOld = 0;
        $readNew = 0;

        while ($index < $count && ($readOld < $oldCount || $readNew < $newCount)) {
            $line = $lines[$index];

            if ('' === $line) {
                // An empty line inside a hunk is a context line with a stripped leading space.
                $hunkLines[] = ' ';
                ++$index;
                ++$readOld;
                ++$readNew;
                continue;
            }

            $prefix = $line[0] ?? '';

            if ('\\' === $prefix) {
                // "\ No newline at end of file": informational, not a content line.
                $hunkLines[] = $line;
                ++$index;
                continue;
            }

            if (' ' === $prefix) {
                $hunkLines[] = $line;
                ++$index;
                ++$readOld;
                ++$readNew;
                continue;
            }

            if ('-' === $prefix) {
                $hunkLines[] = $line;
                ++$index;
                ++$readOld;
                continue;
            }

            if ('+' === $prefix) {
                $hunkLines[] = $line;
                ++$index;
                ++$readNew;
                continue;
            }

            break;
        }

        if ([] === $hunkLines) {
            throw new PatchException(sprintf('Empty hunk "%s".', trim($header)));
        }

        return [new Hunk($oldStart, $oldCount, $newStart, $newCount, $hunkLines), $index];
    }

    private function normalizePath(string $header): ?string
    {
        $path = trim($header);
        // Strip tab-separated timestamps ("path\t2024-01-01").
        $path = preg_split('/\t/', $path)[0] ?? $path;
        $path = trim($path);

        if ('' === $path) {
            return null;
        }

        if ('/dev/null' === $path) {
            return $path;
        }

        return (string) preg_replace('#^[ab]/#', '', $path);
    }
}
