<?php

namespace App\Agent\Tool;

/**
 * Reads a UTF-8 text file, optionally a line range.
 */
final class ReadFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Read a text file from the workspace. Always read a file before editing it. '
            . 'For large files, read a line range with start_line/end_line (1-based, inclusive). '
            . 'Binary files and sensitive files (.env, keys, credentials) are refused.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path relative to the workspace root.',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'First line to read (1-based). Optional.',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Last line to read (inclusive). Optional.',
                ],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function doExecute(array $input, ToolContext $context): ToolResult
    {
        $relative = $this->stringInput($input, 'path', null, true) ?? '';
        $guard = $this->guard($context);
        $absolute = $guard->resolve($relative, true);

        if (is_dir($absolute)) {
            return ToolResult::failure(sprintf('"%s" is a directory. Use list_files instead.', $relative));
        }

        if (!is_readable($absolute)) {
            return ToolResult::failure(sprintf('"%s" is not readable.', $relative));
        }

        $size = (int) filesize($absolute);
        $maxBytes = $context->getLimits()->getMaxFileReadBytes();
        $startLine = $this->intInput($input, 'start_line', 1, 1, PHP_INT_MAX);
        $endLine = $this->optionalLine($input, 'end_line');
        $requestedRange = isset($input['start_line']) || isset($input['end_line']);

        if (!$requestedRange && $size > $maxBytes) {
            $total = $this->countLines($absolute);

            return ToolResult::failure(sprintf(
                '"%s" is too large to read at once (%s, %d lines, limit %s). '
                . 'Read it in chunks with start_line/end_line.',
                $relative,
                $this->humanSize($size),
                $total,
                $this->humanSize($maxBytes),
            ));
        }

        if ($this->looksBinary($absolute)) {
            return ToolResult::failure(sprintf('"%s" looks like a binary file and cannot be read as text.', $relative));
        }

        $totalLines = $this->countLines($absolute);
        if ($startLine > $totalLines) {
            return ToolResult::failure(sprintf(
                '"%s" only has %d lines; start_line %d is out of range.',
                $relative,
                $totalLines,
                $startLine,
            ));
        }

        $endLine = null === $endLine ? $totalLines : min($endLine, $totalLines);
        if ($endLine < $startLine) {
            return ToolResult::failure(sprintf(
                'end_line (%d) must be greater than or equal to start_line (%d).',
                $endLine,
                $startLine,
            ));
        }

        $content = $this->readLines($absolute, $startLine, $endLine);

        $header = sprintf(
            '%s (lines %d-%d of %d):',
            $relative,
            $startLine,
            $endLine,
            $totalLines,
        );

        $output = $this->truncate($header . "\n" . $content, $maxBytes, $relative);

        return ToolResult::success($output, [
            'path' => $relative,
            'lines' => $endLine - $startLine + 1,
            'total_lines' => $totalLines,
            'bytes' => $size,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalLine(array $input, string $key): ?int
    {
        return isset($input[$key]) ? $this->intInput($input, $key, 1, 1, PHP_INT_MAX) : null;
    }

    private function countLines(string $file): int
    {
        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return 0;
        }

        $lines = 0;
        while (false !== fgets($handle)) {
            ++$lines;
        }

        fclose($handle);

        return $lines;
    }

    private function readLines(string $file, int $startLine, int $endLine): string
    {
        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return '';
        }

        $lines = [];
        $number = 0;
        while (false !== ($line = fgets($handle))) {
            ++$number;
            if ($number < $startLine) {
                continue;
            }

            $lines[] = rtrim($line, "\n");
            if ($number >= $endLine) {
                break;
            }
        }

        fclose($handle);

        return implode("\n", $lines);
    }

    private function looksBinary(string $file): bool
    {
        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return false;
        }

        $chunk = (string) fread($handle, 8192);
        fclose($handle);

        if ('' === $chunk) {
            return false;
        }

        return str_contains($chunk, "\0") || 0 === preg_match('//u', $chunk);
    }
}
