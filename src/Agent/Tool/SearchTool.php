<?php

namespace App\Agent\Tool;

use App\Agent\Exception\ToolInputException;
use App\Agent\Process\SanitizedProcessRunner;
use App\Agent\Workspace\PathGuard;
use App\Agent\Workspace\PathGuardFactory;

/**
 * Searches the workspace with ripgrep when available, with a PHP fallback.
 *
 * Both paths enforce the same policy: skipped directories, denied files and a
 * hard result cap.
 */
final class SearchTool extends AbstractTool
{
    private const FALLBACK_MAX_FILE_BYTES = 524288;

    private const FALLBACK_MAX_FILES = 5000;

    public function __construct(
        PathGuardFactory $pathGuards,
        private readonly SanitizedProcessRunner $processRunner,
    ) {
        parent::__construct($pathGuards);
    }

    public function getName(): string
    {
        return 'search';
    }

    public function getDescription(): string
    {
        return 'Search a regular expression across the workspace files and return matching lines '
            . 'as "path:line: content". Use it to locate code before reading specific files. '
            . 'Narrow the search with path (a subdirectory) and/or glob (e.g. "*.php", "src/**/*.ts").';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Regular expression to search for (ripgrep/PCRE syntax).',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory or file to search in, relative to the workspace root.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Optional glob filter applied to file names, e.g. "*.php" or "src/**/*.ts".',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Maximum number of matching lines to return. Defaults to the configured limit.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function doExecute(array $input, ToolContext $context): ToolResult
    {
        $query = $this->stringInput($input, 'query', null, true) ?? '';
        if ('' === trim($query)) {
            throw new ToolInputException('Argument "query" must not be empty.');
        }

        $glob = $this->stringInput($input, 'glob');
        $path = $this->stringInput($input, 'path', '.') ?? '.';
        $maxResults = $this->intInput(
            $input,
            'max_results',
            $context->getLimits()->getMaxSearchResults(),
            1,
            500,
        );

        $guard = $this->guard($context);
        $searchRoot = $guard->resolve($path, true);

        $ripgrep = $this->processRunner->locate('rg');
        $matches = null === $ripgrep
            ? $this->searchWithPhp($guard, $searchRoot, $query, $glob, $maxResults)
            : $this->searchWithRipgrep($context, $guard, $searchRoot, $query, $glob, $maxResults, $ripgrep);

        if (null === $matches) {
            return ToolResult::failure(sprintf('Invalid search pattern: %s', $query));
        }

        if ([] === $matches) {
            return ToolResult::success(
                sprintf('No match for "%s"%s.', $query, null === $glob ? '' : sprintf(' (glob %s)', $glob)),
                ['matches' => 0],
            );
        }

        $output = implode("\n", $matches);
        if (\count($matches) >= $maxResults) {
            $output .= sprintf("\n… [limited to %d matches]", $maxResults);
        }

        return ToolResult::success($output, ['matches' => \count($matches)]);
    }

    /**
     * @return list<string>|null null when the pattern is invalid
     */
    private function searchWithRipgrep(
        ToolContext $context,
        PathGuard $guard,
        string $searchRoot,
        string $query,
        ?string $glob,
        int $maxResults,
        string $ripgrep,
    ): ?array {
        $arguments = [
            $ripgrep,
            '--line-number',
            '--no-heading',
            '--with-filename',
            '--color=never',
            '--max-count=20',
            '--max-columns=400',
            '--max-columns-preview',
        ];

        foreach ($this->ignoredGlobs($guard) as $ignored) {
            $arguments[] = '--glob';
            $arguments[] = '!' . $ignored;
        }

        if (null !== $glob && '' !== $glob) {
            $arguments[] = '--glob';
            $arguments[] = $glob;
        }

        $timeout = min(30, $context->getLimits()->getMaxCommandSeconds());

        // Paths handed to ripgrep are relative to the workspace root: this keeps globs
        // (including our ignore list) and the reported paths consistent. The path is
        // always explicit, otherwise ripgrep would read from stdin.
        $arguments[] = '--';
        $arguments[] = $query;
        $arguments[] = $guard->relative($searchRoot);

        $result = $this->processRunner->run(
            $guard->getRoot(),
            $arguments,
            $timeout,
            $context->getLimits()->getMaxToolOutputBytes() * 2,
        );

        // Exit code 1 simply means "no match"; 2 is a real error (bad pattern, …).
        if ($result->getExitCode() >= 2) {
            return null;
        }

        $matches = [];
        foreach (explode("\n", $result->getStdout()) as $line) {
            if (\count($matches) >= $maxResults) {
                break;
            }

            $line = rtrim($line);
            if ('' === $line) {
                continue;
            }

            if (1 !== preg_match('#^(?<file>[^:]+):(?<line>\d+):(?<content>.*)$#', $line, $found)) {
                continue;
            }

            $relative = str_starts_with($found['file'], '/')
                ? $this->safeRelative($guard, $found['file'])
                : (string) preg_replace('#^\./#', '', $found['file']);

            if ('' === $relative || $guard->isDenied($relative) || $guard->isIgnored($relative)) {
                continue;
            }

            $matches[] = sprintf('%s:%s: %s', $relative, $found['line'], trim($found['content']));
        }

        return $matches;
    }

    /**
     * @return list<string>|null null when the pattern is invalid
     */
    private function searchWithPhp(
        PathGuard $guard,
        string $searchRoot,
        string $query,
        ?string $glob,
        int $maxResults,
    ): ?array {
        $pattern = '#' . str_replace('#', '\\#', $query) . '#u';
        if (false === @preg_match($pattern, '')) {
            return null;
        }

        $matches = [];
        $scannedFiles = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $searchRoot,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                function (\SplFileInfo $file) use ($guard): bool {
                    if ($file->isLink()) {
                        return false;
                    }

                    $relative = $this->safeRelative($guard, $file->getPathname());
                    if (null === $relative) {
                        return false;
                    }

                    if ($file->isDir()) {
                        return !$guard->isDenied($relative) && !$guard->isIgnored($relative);
                    }

                    return true;
                },
            ),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (\count($matches) >= $maxResults || $scannedFiles >= self::FALLBACK_MAX_FILES) {
                break;
            }

            if (!$file->isFile() || $file->getSize() > self::FALLBACK_MAX_FILE_BYTES) {
                continue;
            }

            $relative = $this->safeRelative($guard, $file->getPathname());
            if (null === $relative || $guard->isDenied($relative) || $guard->isIgnored($relative)) {
                continue;
            }

            if (null !== $glob && '' !== $glob && !fnmatch($glob, $relative)) {
                continue;
            }

            ++$scannedFiles;

            $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            if (false === $lines) {
                continue;
            }

            foreach ($lines as $number => $line) {
                if (\count($matches) >= $maxResults) {
                    break 2;
                }

                if (str_contains($line, "\0")) {
                    break;
                }

                if (1 === @preg_match($pattern, $line)) {
                    $matches[] = sprintf('%s:%d: %s', $relative, $number + 1, trim($line));
                }
            }
        }

        return $matches;
    }

    /**
     * Globs excluding the ignored directories, so ripgrep behaves exactly like
     * the PHP fallback regardless of the repository `.gitignore`.
     *
     * @return list<string>
     */
    private function ignoredGlobs(PathGuard $guard): array
    {
        $globs = [];
        foreach ($guard->getIgnoredDirectories() as $directory) {
            $directory = trim($directory, '/');
            $globs[] = $directory . '/**';
            $globs[] = '**/' . $directory . '/**';
        }

        return $globs;
    }

    private function safeRelative(PathGuard $guard, string $absolute): ?string
    {
        try {
            return $guard->relative($absolute);
        } catch (\Throwable) {
            return null;
        }
    }
}
