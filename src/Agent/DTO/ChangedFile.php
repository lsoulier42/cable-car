<?php

namespace App\Agent\DTO;

/**
 * A file created, modified or deleted by a run.
 */
final class ChangedFile
{
    public function __construct(
        private readonly string $path,
        private readonly string $status,
        private readonly ?string $diff = null,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDiff(): ?string
    {
        return $this->diff;
    }

    /**
     * @return array{path: string, status: string, diff: string|null}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status,
            'diff' => $this->diff,
        ];
    }
}
