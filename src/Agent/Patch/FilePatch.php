<?php

namespace App\Agent\Patch;

/**
 * One file section of a unified diff.
 */
final class FilePatch
{
    /**
     * @param list<Hunk> $hunks
     */
    public function __construct(
        private readonly string $path,
        private readonly bool $isNew,
        private readonly bool $isDeleted,
        private readonly array $hunks,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    /**
     * @return list<Hunk>
     */
    public function getHunks(): array
    {
        return $this->hunks;
    }
}
