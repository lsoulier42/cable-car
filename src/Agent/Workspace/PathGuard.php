<?php

namespace App\Agent\Workspace;

use App\Agent\Exception\PathViolationException;

/**
 * Enforces the workspace boundary for every filesystem operation.
 *
 * Responsibilities:
 * - reject absolute paths, `..` traversal and NUL bytes;
 * - resolve the path against the workspace root and make sure the *real*
 *   path (symlinks included) stays inside the root;
 * - refuse sensitive paths (secrets, keys, credentials);
 * - tell the traversal tools which directories to skip.
 *
 * All tools must go through this class: it is the single place where the
 * filesystem policy lives.
 */
final class PathGuard
{
    /**
     * @param list<string> $deniedPaths        fnmatch patterns, matched against the relative path
     * @param list<string> $ignoredDirectories relative directories never traversed
     */
    public function __construct(
        private readonly string $root,
        private readonly array $deniedPaths = [],
        private readonly array $ignoredDirectories = [],
    ) {
    }

    public function getRoot(): string
    {
        return $this->root;
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

    /**
     * Resolves a workspace-relative path to a canonical absolute path.
     *
     * The returned path is guaranteed to be inside the workspace: symlinks are
     * resolved for the deepest existing ancestor, so a symlink pointing outside
     * the workspace is rejected instead of being followed.
     *
     * @throws PathViolationException
     */
    public function resolve(string $relativePath, bool $mustExist = false): string
    {
        $relativePath = trim($relativePath);
        if ('' === $relativePath || '.' === $relativePath || './' === $relativePath) {
            return $this->realRoot();
        }

        if (str_contains($relativePath, "\0")) {
            throw new PathViolationException('Path contains a NUL byte.');
        }

        if ($this->isAbsolute($relativePath)) {
            throw new PathViolationException(sprintf(
                '"%s" is an absolute path; paths must be relative to the workspace.',
                $relativePath,
            ));
        }

        $normalized = $this->normalize($relativePath);

        if ('' === $normalized) {
            return $this->realRoot();
        }

        if ($this->isDenied($normalized)) {
            throw new PathViolationException(sprintf(
                '"%s" is excluded by the workspace policy (secrets and credentials).',
                $normalized,
            ));
        }

        $absolute = $this->realRoot() . '/' . $normalized;
        $resolved = $this->resolveExistingAncestors($absolute);
        $this->assertInsideRoot($resolved, $normalized);

        if ($mustExist && !file_exists($resolved)) {
            throw new PathViolationException(sprintf('"%s" does not exist.', $normalized));
        }

        return $resolved;
    }

    /**
     * Path of $absolute relative to the workspace root (used for reporting).
     *
     * @throws PathViolationException
     */
    public function relative(string $absolute): string
    {
        $root = $this->realRoot();
        if ($absolute === $root) {
            return '.';
        }

        if (!str_starts_with($absolute, $root . '/')) {
            throw new PathViolationException(sprintf('"%s" is outside the workspace.', $absolute));
        }

        return substr($absolute, strlen($root) + 1);
    }

    /**
     * Whether the given relative path matches the sensitive-path denylist.
     */
    public function isDenied(string $relativePath): bool
    {
        $relativePath = ltrim($relativePath, '/');
        $basename = basename($relativePath);

        foreach ($this->deniedPaths as $pattern) {
            $pattern = ltrim($pattern, '/');
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the given relative path lives in an ignored directory
     * (`vendor`, `node_modules`, `.git`, `var/cache`, ...).
     */
    public function isIgnored(string $relativePath): bool
    {
        $relativePath = trim($relativePath, '/');

        foreach ($this->ignoredDirectories as $ignored) {
            $ignored = trim($ignored, '/');
            if ('' === $ignored) {
                continue;
            }

            if (
                $relativePath === $ignored
                || str_starts_with($relativePath, $ignored . '/')
                || str_contains($relativePath, '/' . $ignored . '/')
                || str_ends_with($relativePath, '/' . $ignored)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lexically normalizes a relative path: resolves `.` and `..` segments
     * without touching the filesystem. `..` escaping the root is rejected.
     *
     * @throws PathViolationException
     */
    private function normalize(string $relativePath): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $segments) {
                    throw new PathViolationException(sprintf('"%s" escapes the workspace.', $relativePath));
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Resolves symlinks for the deepest existing ancestor and re-appends the
     * non-existing tail, so writes to new files are validated too.
     */
    private function resolveExistingAncestors(string $absolute): string
    {
        $tail = [];
        $probe = $absolute;

        while (!file_exists($probe)) {
            array_unshift($tail, basename($probe));
            $parent = \dirname($probe);
            if ($parent === $probe) {
                throw new PathViolationException(sprintf('"%s" cannot be resolved inside the workspace.', $absolute));
            }
            $probe = $parent;
        }

        $real = realpath($probe);
        if (false === $real) {
            throw new PathViolationException(sprintf('"%s" cannot be resolved inside the workspace.', $absolute));
        }

        return [] === $tail ? $real : $real . '/' . implode('/', $tail);
    }

    /**
     * @throws PathViolationException
     */
    private function assertInsideRoot(string $absolute, string $relativePath): void
    {
        $root = $this->realRoot();

        if ($absolute !== $root && !str_starts_with($absolute, $root . '/')) {
            throw new PathViolationException(sprintf(
                '"%s" resolves outside the workspace (symlink escape).',
                $relativePath,
            ));
        }
    }

    /**
     * @throws PathViolationException
     */
    private function realRoot(): string
    {
        $real = realpath($this->root);
        if (false === $real || !is_dir($real)) {
            throw new PathViolationException(sprintf('Workspace root "%s" is not a directory.', $this->root));
        }

        return $real;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || 1 === preg_match('#^[a-zA-Z]:[\\\\/]#', $path);
    }
}
