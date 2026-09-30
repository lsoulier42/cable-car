<?php

namespace App\Agent\Tool;

use App\Agent\Exception\CommandPolicyException;
use App\Agent\Exception\PathViolationException;
use App\Agent\Exception\ToolFailureException;
use App\Agent\Exception\ToolInputException;
use App\Agent\Workspace\PathGuard;
use App\Agent\Workspace\PathGuardFactory;

/**
 * Base class for the coding tools.
 *
 * It centralizes the two failure modes the model must be able to see and
 * correct: malformed arguments and rejected paths. Tools only implement
 * {@see self::doExecute()}.
 */
abstract class AbstractTool implements CodingToolInterface
{
    public function __construct(private readonly PathGuardFactory $pathGuards)
    {
    }

    final public function execute(array $input, ToolContext $context): ToolResult
    {
        try {
            return $this->doExecute($input, $context);
        } catch (
            ToolInputException | PathViolationException | CommandPolicyException | ToolFailureException $exception
        ) {
            return ToolResult::failure($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    abstract protected function doExecute(array $input, ToolContext $context): ToolResult;

    /**
     * Writes a file inside the workspace, creating missing directories.
     *
     * The path must already have been validated by the {@see PathGuard}.
     *
     * @throws ToolFailureException
     */
    protected function writeFile(string $absolute, string $content, int $maxBytes): void
    {
        if (\strlen($content) > $maxBytes) {
            throw new ToolFailureException(sprintf(
                'Refusing to write %s: content is larger than the %s limit.',
                basename($absolute),
                $this->humanSize($maxBytes),
            ));
        }

        $directory = \dirname($absolute);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new ToolFailureException(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (is_dir($absolute)) {
            throw new ToolFailureException(sprintf('"%s" is a directory.', $absolute));
        }

        if (false === @file_put_contents($absolute, $content)) {
            throw new ToolFailureException(sprintf('Unable to write "%s".', $absolute));
        }
    }

    protected function guard(ToolContext $context): PathGuard
    {
        return $this->pathGuards->forWorkspace($context->getWorkspace());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ToolInputException
     */
    protected function stringInput(array $input, string $key, ?string $default = null, bool $required = false): ?string
    {
        $value = $input[$key] ?? null;

        if (null === $value || '' === $value) {
            if ($required) {
                throw new ToolInputException(sprintf('Missing required argument "%s".', $key));
            }

            return $default;
        }

        if (!is_string($value)) {
            throw new ToolInputException(sprintf('Argument "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ToolInputException
     */
    protected function intInput(array $input, string $key, int $default, int $min, int $max): int
    {
        $value = $input[$key] ?? null;

        if (null === $value) {
            return $default;
        }

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new ToolInputException(sprintf('Argument "%s" must be an integer.', $key));
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * Truncates a payload for the model, with an explicit marker.
     */
    protected function truncate(string $text, int $maxBytes, string $label): string
    {
        if (\strlen($text) <= $maxBytes) {
            return $text;
        }

        $kept = substr($text, 0, $maxBytes);
        $lastNewline = strrpos($kept, "\n");
        if (false !== $lastNewline && $lastNewline > $maxBytes / 2) {
            $kept = substr($kept, 0, $lastNewline);
        }

        return rtrim($kept, "\n") . sprintf(
            "\n… [%s truncated: %d bytes kept of %d]",
            $label,
            \strlen($kept),
            \strlen($text),
        );
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
