<?php

namespace App\Agent\Patch;

/**
 * Applies a parsed {@see FilePatch} to a file content.
 *
 * The algorithm is intentionally conservative: a hunk is applied only where
 * its context matches exactly. When the context is not found at the announced
 * line, a small window around it is searched (patches produced by models are
 * often off by a few lines); if it still does not match, the patch is refused
 * with an explanatory error instead of corrupting the file.
 */
final class PatchApplier
{
    private const SEARCH_WINDOW = 2000;

    /**
     * @throws PatchException
     */
    public function apply(string $original, FilePatch $filePatch): string
    {
        $eol = str_contains($original, "\r\n") ? "\r\n" : "\n";
        $hadTrailingNewline = '' === $original || str_ends_with($original, "\n");
        $lines = '' === $original ? [] : explode("\n", rtrim($original, "\n"));

        if ($filePatch->isNew() && [] !== $lines) {
            throw new PatchException(sprintf(
                '"%s" already exists: a "new file" patch cannot overwrite it.',
                $filePatch->getPath(),
            ));
        }

        $shift = 0;
        foreach ($filePatch->getHunks() as $number => $hunk) {
            $oldLines = $hunk->getOldLines();
            $newLines = $hunk->getNewLines();

            if ([] === $oldLines) {
                // Pure insertion (new file, or an empty hunk header).
                $position = min(\count($lines), max(0, $hunk->getOldStart() - 1 + $shift));
                array_splice($lines, $position, 0, $newLines);
                $shift += \count($newLines);
                continue;
            }

            $found = $this->find($lines, $oldLines, $hunk->getOldStart() - 1 + $shift);

            if (null === $found) {
                throw new PatchException($this->describeFailure($filePatch->getPath(), $number, $hunk, $lines));
            }

            array_splice($lines, $found, \count($oldLines), $newLines);
            $shift += \count($newLines) - \count($oldLines);
        }

        if ($filePatch->isDeleted()) {
            return '';
        }

        $content = implode($eol, $lines);
        if ($hadTrailingNewline && '' !== $content) {
            $content .= $eol;
        }

        return $content;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $expected
     */
    private function find(array $lines, array $expected, int $position): ?int
    {
        $last = max(0, \count($lines) - \count($expected));
        $start = min(max(0, $position), $last);

        if ($this->matches($lines, $expected, $start)) {
            return $start;
        }

        // Look outward from the announced line: models often announce a stale
        // line number, but the context itself stays recognisable.
        $reach = min(max($start, $last - $start), self::SEARCH_WINDOW);

        for ($offset = 1; $offset <= $reach; ++$offset) {
            $before = $start - $offset;
            $after = $start + $offset;

            if ($before >= 0 && $this->matches($lines, $expected, $before)) {
                return $before;
            }

            if ($after <= $last && $this->matches($lines, $expected, $after)) {
                return $after;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $expected
     */
    private function matches(array $lines, array $expected, int $position): bool
    {
        if ($position < 0 || $position + \count($expected) > \count($lines)) {
            return false;
        }

        foreach ($expected as $index => $line) {
            if ($lines[$position + $index] !== $line) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $lines
     */
    private function describeFailure(string $path, int $hunkNumber, Hunk $hunk, array $lines): string
    {
        $expected = $hunk->getOldLines();
        $preview = implode("\n", \array_slice($expected, 0, 5));
        $found = [];

        $position = max(0, $hunk->getOldStart() - 1);
        foreach (\array_slice($lines, $position, 5) as $line) {
            $found[] = $line;
        }

        return sprintf(
            "Hunk #%d of \"%s\" does not apply: expected content was not found near line %d.\n"
            . "Expected (first lines):\n%s\nFound instead:\n%s\n"
            . 'Re-read the file and produce an up-to-date patch.',
            $hunkNumber + 1,
            $path,
            $hunk->getOldStart(),
            $preview,
            implode("\n", $found),
        );
    }
}
