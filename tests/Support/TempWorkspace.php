<?php

namespace App\Tests\Support;

use App\Agent\Workspace\Workspace;

/**
 * Creates disposable workspaces for the agent tests.
 */
trait TempWorkspace
{
    private ?string $tempWorkspacePath = null;

    /**
     * @param array<string, string> $files relative path => content
     */
    protected function createTempWorkspace(array $files = []): Workspace
    {
        $path = sys_get_temp_dir() . '/cable-car-test-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create "%s".', $path));
        }

        $this->tempWorkspacePath = $path;

        foreach ($files as $relative => $content) {
            $absolute = $path . '/' . $relative;
            if (!is_dir(\dirname($absolute))) {
                mkdir(\dirname($absolute), 0o775, true);
            }
            file_put_contents($absolute, $content);
        }

        return new Workspace(basename($path), (string) realpath($path));
    }

    protected function tempWorkspaceRoot(): string
    {
        if (null === $this->tempWorkspacePath) {
            throw new \RuntimeException('No temporary workspace has been created.');
        }

        return $this->tempWorkspacePath;
    }

    protected function removeTempWorkspace(): void
    {
        if (null === $this->tempWorkspacePath || !is_dir($this->tempWorkspacePath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempWorkspacePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isLink() || $entry->isFile()) {
                unlink($entry->getPathname());
                continue;
            }

            rmdir($entry->getPathname());
        }

        rmdir($this->tempWorkspacePath);
        $this->tempWorkspacePath = null;
    }
}
