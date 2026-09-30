<?php

namespace App\Agent\Model;

use App\Agent\DTO\AgentMessage;
use App\Agent\DTO\AgentRole;
use App\Agent\DTO\AgentToolCall;
use App\Agent\DTO\ModelTurn;
use App\Agent\Exception\ModelException;
use App\Agent\Runner\AgentContext;
use App\Agent\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Symfony AI implementation of {@see CodingModelInterface}.
 *
 * It owns the translation between Cable Car's provider-agnostic messages and
 * Symfony AI message/result objects. The loop, the limits and the tool
 * execution stay in Cable Car.
 */
final class SymfonyAiCodingModel implements CodingModelInterface
{
    /**
     * @param array<string, mixed> $options provider options forwarded as-is (num_predict, temperature, …)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $modelId,
        private readonly PlatformInterface $platform,
        private readonly ToolRegistry $tools,
        private readonly LoggerInterface $logger,
        private readonly array $options = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    public function respond(AgentContext $context): ModelTurn
    {
        $messages = $this->toMessageBag($context);

        try {
            $options = array_merge($this->options, ['tools' => $this->tools->getAiTools()]);

            $result = $this->platform
                ->invoke($this->modelId, $messages, $options)
                ->getResult();

            $assistant = Message::ofAssistant($result);
        } catch (\Throwable $exception) {
            throw new ModelException(
                sprintf('Model "%s" (%s) failed: %s', $this->name, $this->modelId, $exception->getMessage()),
                0,
                $exception,
            );
        }

        $toolCalls = [];
        foreach ($assistant->getToolCalls() as $toolCall) {
            $toolCalls[] = new AgentToolCall(
                $toolCall->getId(),
                $toolCall->getName(),
                $toolCall->getArguments(),
            );
        }

        $text = $assistant->asText();

        $this->logger->debug('Model turn received', [
            'model' => $this->name,
            'tool_calls' => \count($toolCalls),
            'has_text' => null !== $text && '' !== trim($text),
        ]);

        return new ModelTurn($text, $toolCalls);
    }

    private function toMessageBag(AgentContext $context): MessageBag
    {
        $messages = [];
        foreach ($context->getMessages() as $message) {
            $messages[] = $this->toSymfonyMessage($message);
        }

        return new MessageBag(...$messages);
    }

    private function toSymfonyMessage(AgentMessage $message): \Symfony\AI\Platform\Message\MessageInterface
    {
        return match ($message->getRole()) {
            AgentRole::System => new SystemMessage($message->getText() ?? ''),
            AgentRole::User => Message::ofUser($message->getText() ?? ''),
            AgentRole::Assistant => Message::ofAssistant(...array_merge(
                null === $message->getText() || '' === $message->getText() ? [] : [$message->getText()],
                array_map(
                    static fn (AgentToolCall $call): ToolCall => new ToolCall(
                        $call->getId(),
                        $call->getName(),
                        $call->getArguments(),
                    ),
                    $message->getToolCalls(),
                ),
            )),
            AgentRole::Tool => Message::ofToolCall(
                new ToolCall($message->getToolCallId() ?? '', $message->getToolName() ?? '', []),
                $message->getText() ?? '',
            ),
        };
    }
}
