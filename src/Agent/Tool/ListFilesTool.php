<?php

namespace App\Agent\Tool;

use App\Agent\Workspace\PathGuard;

/**
 * Lists the contents of a workspace directory.
 */
final class ListFilesTool extends AbstractTool
{
    private const MAX_DEPTH = 3;

    public function getName(): string
    {
        return 'list_files';
    }

    public function getDescription(): string
    {
        return 'List files and directories of the workspace, optionally recursing up to 3 levels. '
            . 'Use it to discover the project structure before reading files. Directories end with "/". '
            . 'Heavy directories (.git, vendor, node_modules, var/cache, …) are always skipped.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory path relative to the workspace root. Defaults to "." (workspace root).',
                ],
                'depth' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_DEPTH,
                    'description' => 'Recursion depth, between 1 and 3. Defaults to 1 (immediate children).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function doExecute(array $input, ToolContext $context): ToolResult
    {
        $relative = $this->stringInput($input, 'path', '.') ?? '.';
        $depth = $this->intInput($input, 'depth', 1, 1, self::MAX_DEPTH);

        $guard = $this->guard($context);
        $directory = $guard->resolve($relative, true);

        if (!is_dir($directory)) {
            return ToolResult::failure(sprintf('"%s" is not a directory. Use read_file for files.', $relative));
        }

        $limit = $context->getLimits()->getMaxListEntries();
        $lines = [];
        $count = 0;
        $truncated = false;

        $this->walk($guard, $directory, $depth, 0, $lines, $count, $limit, $truncated);

        if ([] === $lines) {
            return ToolResult::success(sprintf('"%s" is empty (or only contains skipped directories).', $relative));
        }

        $output = implode("\n", $lines);
        if ($truncated) {
            $output .= sprintf("\n… [listing truncated at %d entries]", $limit);
        }

        return ToolResult::success(
            $output,
            ['path' => $relative, 'entries' => $count, 'truncated' => $truncated],
        );
    }

    /**
     * @param list<string> $lines
     */
    private function walk(
        PathGuard $guard,
        string $directory,
        int $maxDepth,
        int $currentDepth,
        array &$lines,
        int &$count,
        int $limit,
        bool &$truncated,
    ): void {
        if ($count >= $limit) {
            $truncated = true;

            return;
        }

        $entries = scandir($directory);
        if (false === $entries) {
            return;
        }

        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        $directories = [];
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $absolute = $directory . '/' . $entry;
            $relative = $guard->relative($absolute);

            if ($guard->isDenied($relative) || $guard->isIgnored($relative)) {
                continue;
            }

            if (is_dir($absolute) && !is_link($absolute)) {
                $directories[] = [$relative, $absolute];
                continue;
            }

            $indent = str_repeat('  ', $currentDepth);
            $suffix = is_link($absolute) ? ' @link' : '';
            $lines[] = $indent . $entry . $suffix;
            ++$count;

            if ($count >= $limit) {
                $truncated = true;

                return;
            }
        }

        foreach ($directories as [$relative, $absolute]) {
            $indent = str_repeat('  ', $currentDepth);
            $lines[] = $indent . basename($relative) . '/';
            ++$count;

            if ($currentDepth + 1 >= $maxDepth) {
                continue;
            }

            $this->walk($guard, $absolute, $maxDepth, $currentDepth + 1, $lines, $count, $limit, $truncated);
        }
    }
}
