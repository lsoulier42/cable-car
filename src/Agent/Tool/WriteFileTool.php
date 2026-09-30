<?php

namespace App\Agent\Tool;

use App\Agent\Exception\ToolInputException;

/**
 * Creates or replaces a text file inside the workspace.
 */
final class WriteFileTool extends AbstractTool
{
    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Create or fully replace a text file in the workspace. The parent directories are created '
            . 'automatically. Prefer apply_patch for targeted edits of existing files: rewriting a whole '
            . 'file is only appropriate for new or very small files.';
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
                'content' => [
                    'type' => 'string',
                    'description' => 'Complete content of the file (UTF-8).',
                ],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function doExecute(array $input, ToolContext $context): ToolResult
    {
        $relative = $this->stringInput($input, 'path', null, true) ?? '';

        $content = $input['content'] ?? null;
        if (!is_string($content)) {
            throw new ToolInputException('Argument "content" must be a string.');
        }

        $guard = $this->guard($context);
        $absolute = $guard->resolve($relative);
        $existed = file_exists($absolute);

        $this->writeFile($absolute, $content, $context->getLimits()->getMaxFileWriteBytes());

        $lines = '' === $content ? 0 : substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);

        return ToolResult::success(
            sprintf(
                '%s %s (%d lines, %s).',
                $existed ? 'Updated' : 'Created',
                $relative,
                $lines,
                $this->humanSize(\strlen($content)),
            ),
            ['path' => $relative, 'status' => $existed ? 'modified' : 'created', 'bytes' => \strlen($content)],
        );
    }
}
