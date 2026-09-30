<?php

namespace App\Agent\Runner;

use App\Agent\Git\GitInspector;

/**
 * Builds the system instructions for a run.
 *
 * The instructions are deliberately explicit: the model must inspect before
 * editing, stay inside the workspace, validate its work and finish with a
 * concise summary. No chain-of-thought is requested or stored.
 */
final class SystemPromptBuilder
{
    public function __construct(private readonly GitInspector $git)
    {
    }

    public function build(AgentContext $context): string
    {
        $workspace = $context->getWorkspace();
        $limits = $context->getLimits();

        $sections = [
            $this->identity(),
            $this->environment($context),
            $this->method(),
            $this->limits($limits),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function identity(): string
    {
        return <<<'PROMPT'
        You are Cable Car, a coding agent working on a local software repository through tools.

        You never guess file contents: you inspect the repository with your tools, and you only
        state facts you observed through a tool result. Every tool call is executed for real;
        tool errors are reported back to you so you can correct yourself.
        PROMPT;
    }

    private function environment(AgentContext $context): string
    {
        $workspace = $context->getWorkspace();
        $lines = [
            '# Environment',
            sprintf('- Workspace: %s (a self-contained directory; it is your whole world)', $workspace->getId()),
        ];

        if ($this->git->isRepository($workspace)) {
            $lines[] = '- The workspace is a Git repository. Cable Car never commits, branches or pushes:';
            $lines[] = '  you only prepare changes, the user reviews and commits them.';

            $status = $this->git->status($workspace);
            if ('' !== $status) {
                $lines[] = '- Current `git status --short`:`';
                $lines[] = $this->indent($this->truncate($status, 40));
            }
        } else {
            $lines[] = '- The workspace is not a Git repository, so no diff will be available.';
        }

        return implode("\n", $lines);
    }

    private function method(): string
    {
        return <<<'PROMPT'
        # Method
        - Inspect before editing: use list_files, read_file and search to ground every decision.
        - Make the smallest reasonable change that fulfils the task; avoid unrelated refactors,
          renames, reformatting or dependency changes.
        - Stay inside the workspace: paths are relative to its root, and anything outside is refused.
        - Validate your work when a validation command is available (tests, linters, static analysis)
          and fix what you broke. Never claim a validation passed if you did not run it.
        - If a tool call fails, read the error and adjust your approach instead of repeating it.
        - Keep tool inputs small: read line ranges for large files, use globs to narrow searches.
        - Do not try to commit, push, or publish anything.
        - Answer directly and concisely: never narrate your internal reasoning, and do not restate
          tool outputs. The user only sees your final message.
        - Finish with a concise final message that summarises the change, the files touched and the
          validation results. Do not include hidden reasoning.
        PROMPT;
    }

    private function limits(\App\Agent\Runner\AgentLimits $limits): string
    {
        return sprintf(
            "# Budget\nYou have at most %d tool calls (max %d per turn) and %d model turns for this run.\n"
            . 'Spend them on the task; stop and report when you are done.',
            $limits->getMaxToolCalls(),
            $limits->getMaxToolCallsPerTurn(),
            $limits->getMaxIterations(),
        );
    }

    private function indent(string $text, string $prefix = '```'): string
    {
        return $prefix . "\n" . $text . "\n```";
    }

    private function truncate(string $text, int $maxLines): string
    {
        $lines = explode("\n", $text);
        if (\count($lines) <= $maxLines) {
            return $text;
        }

        return implode("\n", \array_slice($lines, 0, $maxLines)) . "\n… (truncated)";
    }
}
