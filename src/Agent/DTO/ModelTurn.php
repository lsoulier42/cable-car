<?php

namespace App\Agent\DTO;

/**
 * One model turn: optional text plus the tool calls the model requested.
 */
final class ModelTurn
{
    /**
     * @param list<AgentToolCall> $toolCalls
     */
    public function __construct(
        private readonly ?string $text,
        private readonly array $toolCalls = [],
    ) {
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * @return list<AgentToolCall>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->toolCalls;
    }

    /**
     * A turn without tool calls is the model's final answer.
     */
    public function isFinal(): bool
    {
        return !$this->hasToolCalls() && null !== $this->text && '' !== trim($this->text);
    }
}
