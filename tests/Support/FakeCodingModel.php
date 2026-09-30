<?php

namespace App\Tests\Support;

use App\Agent\DTO\AgentToolCall;
use App\Agent\DTO\ModelTurn;
use App\Agent\Exception\ModelException;
use App\Agent\Model\CodingModelInterface;
use App\Agent\Runner\AgentContext;

/**
 * Deterministic model used by the runner tests: it replays a scripted list of
 * turns (tool calls, text, …) without any provider call.
 */
class FakeCodingModel implements CodingModelInterface
{
    /** @var list<ModelTurn> */
    private array $script;

    private int $cursor = 0;

    /** @var list<AgentContextSnapshot> */
    private array $observedContexts = [];

    /**
     * @param list<ModelTurn> $script
     */
    public function __construct(
        array $script,
        private readonly string $name = 'fake',
        private readonly string $label = 'Fake model',
        private readonly string $modelId = 'fake-model',
    ) {
        $this->script = $script;
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
        $this->observedContexts[] = AgentContextSnapshot::fromContext($context);

        if (!isset($this->script[$this->cursor])) {
            throw new ModelException('The fake model script is exhausted.');
        }

        return $this->script[$this->cursor++];
    }

    /**
     * @return list<AgentContextSnapshot>
     */
    public function getObservedContexts(): array
    {
        return $this->observedContexts;
    }

    public function getRemainingTurns(): int
    {
        return \count($this->script) - $this->cursor;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function toolCall(string $name, array $arguments = [], ?string $id = null): ModelTurn
    {
        return new ModelTurn(null, [
            new AgentToolCall($id ?? 'call_' . $name . '_' . random_int(1000, 9999), $name, $arguments),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $argumentSets
     */
    public static function parallelToolCalls(string $name, array $argumentSets): ModelTurn
    {
        $calls = [];
        foreach ($argumentSets as $index => $arguments) {
            $calls[] = new AgentToolCall('call_' . $index, $name, $arguments);
        }

        return new ModelTurn(null, $calls);
    }

    public static function answer(string $text): ModelTurn
    {
        return new ModelTurn($text, []);
    }
}
