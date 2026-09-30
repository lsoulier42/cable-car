<?php

namespace App\Agent\Workspace;

/**
 * A directory the agent is allowed to work in.
 *
 * A workspace is always identified by a name (its directory name) and rooted
 * at a canonical absolute path. Paths handed to tools are always relative to
 * this root and validated by the {@see PathGuard}.
 */
final class Workspace
{
    public function __construct(
        private readonly string $id,
        private readonly string $root,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Canonical absolute path of the workspace root (no trailing slash).
     */
    public function getRoot(): string
    {
        return $this->root;
    }

    public function exists(): bool
    {
        return is_dir($this->root);
    }

    public function getName(): string
    {
        return basename($this->root);
    }
}
