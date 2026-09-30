<?php

namespace App\Agent\Tool;

use App\Agent\Exception\ToolFailureException;
use App\Agent\Patch\PatchApplier;
use App\Agent\Patch\PatchException;
use App\Agent\Patch\UnifiedDiffParser;
use App\Agent\Workspace\PathGuardFactory;

/**
 * Applies a unified diff to one or more workspace files.
 *
 * The patch is validated for every file before anything is written: either the
 * whole patch applies or nothing changes.
 */
final class ApplyPatchTool extends AbstractTool
{
    public function __construct(
        PathGuardFactory $pathGuards,
        private readonly UnifiedDiffParser $parser = new UnifiedDiffParser(),
        private readonly PatchApplier $applier = new PatchApplier(),
    ) {
        parent::__construct($pathGuards);
    }

    public function getName(): string
    {
        return 'apply_patch';
    }

    public function getDescription(): string
    {
        return 'Apply a unified diff (the "git diff" format) to existing workspace files. '
            . 'This is the preferred way to modify code: changes stay minimal and reviewable. '
            . 'Each section must start with "--- a/path" / "+++ b/path" headers followed by "@@ ... @@" hunks. '
            . 'Every hunk is applied only where its context matches exactly; if a hunk does not apply, '
            . 'read the file again and produce an up-to-date patch.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patch' => [
                    'type' => 'string',
                    'description' => "Unified diff, e.g.\n--- a/src/Foo.php\n+++ b/src/Foo.php\n"
                        . "@@ -1,3 +1,3 @@\n-old\n+new\n context",
                ],
            ],
            'required' => ['patch'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function doExecute(array $input, ToolContext $context): ToolResult
    {
        $patch = $this->stringInput($input, 'patch', null, true) ?? '';
        $guard = $this->guard($context);
        $limits = $context->getLimits();

        try {
            $files = $this->parser->parse($patch);
        } catch (PatchException $exception) {
            return ToolResult::failure('Invalid patch: ' . $exception->getMessage());
        }

        // 1. Validate and compute every target content before writing anything.
        /** @var list<array{path: string, absolute: string, content: string, deleted: bool, created: bool, before: int}> $results */
        $results = [];
        foreach ($files as $filePatch) {
            $relative = $filePatch->getPath();
            $absolute = $guard->resolve($relative);
            $exists = is_file($absolute);

            if (!$exists && !$filePatch->isNew()) {
                return ToolResult::failure(sprintf(
                    '"%s" does not exist; use a "new file" patch (--- /dev/null) or write_file to create it.',
                    $relative,
                ));
            }

            if ($exists && $filePatch->isNew()) {
                return ToolResult::failure(sprintf(
                    '"%s" already exists: refusing to overwrite it with a new-file patch.',
                    $relative,
                ));
            }

            $original = $exists ? (string) file_get_contents($absolute) : '';

            try {
                $patched = $this->applier->apply($original, $filePatch);
            } catch (PatchException $exception) {
                return ToolResult::failure($exception->getMessage());
            }

            if (\strlen($patched) > $limits->getMaxFileWriteBytes()) {
                return ToolResult::failure(sprintf(
                    'Refusing to write "%s": the result would exceed the %s limit.',
                    $relative,
                    $this->humanSize($limits->getMaxFileWriteBytes()),
                ));
            }

            $results[] = [
                'path' => $relative,
                'absolute' => $absolute,
                'content' => $patched,
                'deleted' => $filePatch->isDeleted(),
                'created' => !$exists,
                'before' => $exists ? \count(explode("\n", $original)) : 0,
            ];
        }

        // 2. Write.
        $summary = [];
        /** @var list<array{path: string, status: string}> $changed */
        $changed = [];
        foreach ($results as $result) {
            $path = $result['path'];

            if ($result['deleted']) {
                if (!@unlink($result['absolute'])) {
                    throw new ToolFailureException(sprintf('Unable to delete "%s".', $path));
                }

                $summary[] = sprintf('deleted %s', $path);
                $changed[] = ['path' => $path, 'status' => 'deleted'];
                continue;
            }

            $content = $result['content'];

            $this->writeFile($result['absolute'], $content, $limits->getMaxFileWriteBytes());

            $after = '' === $content ? 0 : substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
            $delta = $after - $result['before'];

            $summary[] = sprintf(
                '%s %s (%s%d lines)',
                $result['created'] ? 'created' : 'modified',
                $path,
                $delta >= 0 ? '+' : '',
                $delta,
            );
            $changed[] = [
                'path' => $path,
                'status' => $result['created'] ? 'created' : 'modified',
            ];
        }

        return ToolResult::success(
            sprintf("Patch applied:\n- %s", implode("\n- ", $summary)),
            ['changed' => $changed],
        );
    }
}
