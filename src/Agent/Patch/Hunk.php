<?php

namespace App\Agent\Patch;

/**
 * One `@@ -a,b +c,d @@` block. Lines keep their diff prefix (' ', '+', '-', '\').
 */
final class Hunk
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        private readonly int $oldStart,
        private readonly int $oldCount,
        private readonly int $newStart,
        private readonly int $newCount,
        private readonly array $lines,
    ) {
    }

    public function getOldStart(): int
    {
        return $this->oldStart;
    }

    public function getOldCount(): int
    {
        return $this->oldCount;
    }

    public function getNewStart(): int
    {
        return $this->newStart;
    }

    public function getNewCount(): int
    {
        return $this->newCount;
    }

    /**
     * @return list<string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Lines expected to be found in the original file (' ' and '-' lines).
     *
     * @return list<string>
     */
    public function getOldLines(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $prefix = $line[0] ?? ' ';

            if ('+' === $prefix || '\\' === $prefix) {
                continue;
            }

            $lines[] = substr($line, 1);
        }

        return $lines;
    }

    /**
     * Lines the file must contain once the hunk is applied (' ' and '+' lines).
     *
     * @return list<string>
     */
    public function getNewLines(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $prefix = $line[0] ?? ' ';

            if ('-' === $prefix || '\\' === $prefix) {
                continue;
            }

            $lines[] = substr($line, 1);
        }

        return $lines;
    }
}
