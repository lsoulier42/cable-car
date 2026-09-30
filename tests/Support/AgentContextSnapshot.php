<?php

namespace App\Tests\Support;

use App\Agent\DTO\AgentMessage;
use App\Agent\DTO\AgentRole;
use App\Agent\Runner\AgentContext;

/**
 * Immutable snapshot of the conversation at a given model turn.
 *
 * The fake model stores these so tests can assert what the runner actually
 * sent to the model (messages, counters, workspace) without keeping a live
 * reference to the run.
 */
final class AgentContextSnapshot
{
    /**
     * @param list<AgentMessage> $messages
     */
    private function __construct(
        private readonly array $messages,
        private readonly int $iteration,
        private readonly int $toolCalls,
        private readonly string $workspaceId,
    ) {
    }

    public static function fromContext(AgentContext $context): self
    {
        return new self(
            $context->getMessages(),
            $context->getIteration(),
            $context->getToolCallCount(),
            $context->getWorkspace()->getId(),
        );
    }

    /**
     * @return list<AgentMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return list<AgentMessage>
     */
    public function getMessagesByRole(AgentRole $role): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (AgentMessage $message): bool => $message->getRole() === $role,
        ));
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getToolCalls(): int
    {
        return $this->toolCalls;
    }

    public function getWorkspaceId(): string
    {
        return $this->workspaceId;
    }
}
