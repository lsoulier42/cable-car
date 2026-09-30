<?php

namespace App\Agent\DTO;

/**
 * Provider-agnostic conversation entry.
 *
 * The runner and the tools only know about this type; mapping to the Symfony
 * AI message objects is the responsibility of the model implementation.
 */
final class AgentMessage
{
    /**
     * @param list<AgentToolCall> $toolCalls
     */
    private function __construct(
        private readonly AgentRole $role,
        private readonly ?string $text,
        private readonly array $toolCalls = [],
        private readonly ?string $toolCallId = null,
        private readonly ?string $toolName = null,
        private readonly bool $toolSuccess = true,
    ) {
    }

    public static function system(string $text): self
    {
        return new self(AgentRole::System, $text);
    }

    public static function user(string $text): self
    {
        return new self(AgentRole::User, $text);
    }

    /**
     * @param list<AgentToolCall> $toolCalls
     */
    public static function assistant(?string $text, array $toolCalls = []): self
    {
        return new self(AgentRole::Assistant, $text, $toolCalls);
    }

    public static function toolResult(string $toolCallId, string $toolName, string $content, bool $success): self
    {
        return new self(AgentRole::Tool, $content, [], $toolCallId, $toolName, $success);
    }

    public function getRole(): AgentRole
    {
        return $this->role;
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

    public function getToolCallId(): ?string
    {
        return $this->toolCallId;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function isToolSuccess(): bool
    {
        return $this->toolSuccess;
    }
}
