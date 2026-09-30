<?php

namespace App\Agent\Workspace;

use App\Agent\Exception\WorkspaceException;

/**
 * Resolves the workspaces available to the agent.
 *
 * Workspaces live directly under the configured root: one directory = one
 * workspace. The model never chooses a host path — it only gets a workspace
 * name, and the name is resolved here.
 */
final class WorkspaceManager
{
    public function __construct(private readonly string $workspacesRoot)
    {
    }

    /**
     * Absolute root directory. Created on demand so a fresh install works.
     *
     * @throws WorkspaceException
     */
    public function getRoot(): string
    {
        $root = $this->absolutePath($this->workspacesRoot);

        if (!is_dir($root) && !@mkdir($root, 0o775, true) && !is_dir($root)) {
            throw new WorkspaceException(sprintf('Workspaces root "%s" cannot be created.', $root));
        }

        $real = realpath($root);
        if (false === $real) {
            throw new WorkspaceException(sprintf('Workspaces root "%s" cannot be resolved.', $root));
        }

        return $real;
    }

    /**
     * @return list<Workspace>
     */
    public function listWorkspaces(): array
    {
        $root = $this->getRoot();
        $entries = scandir($root);
        if (false === $entries) {
            return [];
        }

        $workspaces = [];
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $root . '/' . $entry;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }

            $workspaces[] = new Workspace($entry, $path);
        }

        usort($workspaces, static fn (Workspace $a, Workspace $b): int => strcmp($a->getId(), $b->getId()));

        return $workspaces;
    }

    /**
     * @throws WorkspaceException
     */
    public function get(string $id): Workspace
    {
        if (
            '' === $id
            || str_contains($id, '/')
            || str_contains($id, '\\')
            || str_contains($id, "\0")
            || str_starts_with($id, '.')
        ) {
            throw new WorkspaceException(sprintf('Invalid workspace name "%s".', $id));
        }

        $root = $this->getRoot();
        $path = $root . '/' . $id;

        $real = is_dir($path) ? realpath($path) : false;
        if (false === $real || $real !== $path || !str_starts_with($real, $root . '/')) {
            throw new WorkspaceException(sprintf('Workspace "%s" does not exist in %s.', $id, $root));
        }

        return new Workspace($id, $real);
    }

    /**
     * Wraps an arbitrary absolute directory (CLI `--root` override). Used by
     * the console entry point only: the HTTP API always resolves workspaces
     * through {@see self::get()}.
     *
     * @throws WorkspaceException
     */
    public function fromPath(string $path): Workspace
    {
        $real = realpath($this->absolutePath($path));
        if (false === $real || !is_dir($real)) {
            throw new WorkspaceException(sprintf('"%s" is not a directory.', $path));
        }

        return new Workspace(basename($real), $real);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || 1 === preg_match('#^[a-zA-Z]:[\\\\/]#', $path)) {
            return rtrim($path, '/');
        }

        return rtrim(\dirname(__DIR__, 3) . '/' . $path, '/');
    }
}
