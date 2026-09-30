<?php

namespace App\Agent\Workspace;

/**
 * Builds a {@see PathGuard} for a given workspace from the application
 * configuration (`cable_car.workspace`).
 */
final class PathGuardFactory
{
    /** @var list<string> */
    private readonly array $deniedPaths;

    /** @var list<string> */
    private readonly array $ignoredDirectories;

    /**
     * @param array{ignored_directories: list<string>, denied_paths: list<string>} $workspaceConfig
     */
    public function __construct(array $workspaceConfig)
    {
        $this->deniedPaths = $workspaceConfig['denied_paths'];
        $this->ignoredDirectories = $workspaceConfig['ignored_directories'];
    }

    public function forRoot(string $root): PathGuard
    {
        return new PathGuard($root, $this->deniedPaths, $this->ignoredDirectories);
    }

    public function forWorkspace(Workspace $workspace): PathGuard
    {
        return $this->forRoot($workspace->getRoot());
    }

    /**
     * @return list<string>
     */
    public function getDeniedPaths(): array
    {
        return $this->deniedPaths;
    }

    /**
     * @return list<string>
     */
    public function getIgnoredDirectories(): array
    {
        return $this->ignoredDirectories;
    }
}
