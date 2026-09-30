<?php

namespace App\Agent\Git;

use App\Agent\DTO\ChangedFile;
use App\Agent\Process\ProcessResult;
use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Workspace\Workspace;

/**
 * Read-only Git inspection.
 *
 * Git is used to make the agent's work visible (status, diff, stat). Cable Car
 * never commits, never branches and never pushes: the user stays responsible
 * for accepting the result.
 */
final class GitInspector
{
    private const MAX_DIFF_BYTES = 200000;

    public function __construct(private readonly SanitizedProcessRunner $processRunner)
    {
    }

    public function isRepository(Workspace $workspace): bool
    {
        if (!is_dir($workspace->getRoot() . '/.git')) {
            return false;
        }

        $result = $this->git($workspace, ['rev-parse', '--is-inside-work-tree']);

        return $result->isSuccessful();
    }

    /**
     * `git status --short`, with untracked files expanded to a flat list.
     */
    public function status(Workspace $workspace): string
    {
        if (!$this->isRepository($workspace)) {
            return '';
        }

        $result = $this->git($workspace, ['status', '--short', '--untracked-files=all']);

        return trim($result->getStdout());
    }

    public function diffStat(Workspace $workspace): string
    {
        if (!$this->isRepository($workspace)) {
            return '';
        }

        return trim($this->git($workspace, ['diff', '--stat'])->getStdout());
    }

    public function diff(Workspace $workspace, ?string $path = null): string
    {
        if (!$this->isRepository($workspace)) {
            return '';
        }

        $arguments = ['diff', '--no-color'];
        if (null !== $path) {
            $arguments[] = '--';
            $arguments[] = $path;
        }

        $output = $this->git($workspace, $arguments)->getStdout();

        return $this->cap($output);
    }

    /**
     * Files touched since the recorded baseline (HEAD plus untracked files).
     *
     * @return list<ChangedFile>
     */
    public function changedFiles(Workspace $workspace): array
    {
        if (!$this->isRepository($workspace)) {
            return [];
        }

        $status = $this->status($workspace);
        if ('' === $status) {
            return [];
        }

        $files = [];
        foreach (explode("\n", $status) as $line) {
            $line = rtrim($line);
            if ('' === $line) {
                continue;
            }

            $code = substr($line, 0, 2);
            $path = trim(substr($line, 2));

            if (str_contains($path, ' -> ')) {
                [$old, $path] = explode(' -> ', $path, 2);
                $files[] = new ChangedFile(
                    trim($path),
                    'renamed',
                    $this->diffForFile($workspace, trim($path), trim($old)),
                );
                continue;
            }

            $files[] = new ChangedFile($path, $this->statusFromCode($code), $this->diffForFile($workspace, $path));
        }

        return $files;
    }

    /**
     * Diff of a single path; untracked files get a synthetic "new file" diff.
     */
    public function diffForFile(Workspace $workspace, string $path, ?string $from = null): ?string
    {
        $tracked = $this->isTracked($workspace, $path);
        if ($tracked) {
            $arguments = ['diff', '--no-color', '--'];
            $arguments[] = $from ?? $path;
            if (null !== $from) {
                $arguments[] = $path;
            }

            $output = $this->cap($this->git($workspace, $arguments)->getStdout());

            return '' === trim($output) ? null : $output;
        }

        $absolute = $workspace->getRoot() . '/' . $path;
        if (!is_file($absolute)) {
            return null;
        }

        $content = (string) file_get_contents($absolute, false, null, 0, 20000);

        $lines = [];
        foreach (explode("\n", $content) as $line) {
            $lines[] = '+' . $line;
        }

        return $this->cap("--- /dev/null\n+++ b/{$path}\n@@ new file @@\n" . implode("\n", $lines));
    }

    public function isTracked(Workspace $workspace, string $path): bool
    {
        $result = $this->git($workspace, ['ls-files', '--error-unmatch', '--', $path]);

        return $result->isSuccessful();
    }

    /**
     * @param list<string> $arguments
     */
    private function git(Workspace $workspace, array $arguments): ProcessResult
    {
        return $this->processRunner->run(
            $workspace->getRoot(),
            array_merge(['git'], $arguments),
            30,
            200000,
        );
    }

    private function statusFromCode(string $code): string
    {
        return match (true) {
            str_contains($code, 'A') || str_contains($code, '?') => 'created',
            str_contains($code, 'D') => 'deleted',
            str_contains($code, 'R') => 'renamed',
            default => 'modified',
        };
    }

    private function cap(string $output): string
    {
        if (\strlen($output) <= self::MAX_DIFF_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_DIFF_BYTES) . "\n… [diff truncated]";
    }
}
