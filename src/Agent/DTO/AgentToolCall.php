<?php

namespace App\Agent\DTO;

/**
 * A tool call requested by the model, normalized for the harness.
 */
final class AgentToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array $arguments = [],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Short, loggable rendering of the arguments (never the full content).
     */
    public function getSummary(): string
    {
        $parts = [];
        foreach ($this->arguments as $key => $value) {
            if (is_string($value)) {
                $preview = trim($value);
                if (str_contains($preview, "\n") || \strlen($preview) > 60) {
                    $parts[] = sprintf('%s="%s…"', $key, mb_substr(strtok($preview, "\n") ?: $preview, 0, 60));
                    continue;
                }

                $parts[] = sprintf('%s="%s"', $key, $preview);
                continue;
            }

            if (is_bool($value)) {
                $parts[] = sprintf('%s=%s', $key, $value ? 'true' : 'false');
                continue;
            }

            if (null === $value) {
                continue;
            }

            if (is_scalar($value)) {
                $parts[] = sprintf('%s=%s', $key, (string) $value);
                continue;
            }

            $encoded = json_encode($value);
            $parts[] = sprintf('%s=%s', $key, mb_substr(false === $encoded ? '…' : $encoded, 0, 60));
        }

        return implode(' ', $parts);
    }

    /**
     * Arguments with long values truncated, for persistence in the audit trail.
     *
     * @return array<string, mixed>
     */
    public function getNormalizedArguments(int $maxLength = 2000): array
    {
        $normalized = [];
        foreach ($this->arguments as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value, $maxLength);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, int $maxLength): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $maxLength
                ? mb_substr($value, 0, $maxLength) . sprintf("\n… [truncated, %d bytes total]", \strlen($value))
                : $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item, $maxLength);
            }

            return $normalized;
        }

        return $value;
    }
}
