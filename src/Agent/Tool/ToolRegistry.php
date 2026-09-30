<?php

namespace App\Agent\Tool;

use App\Agent\Exception\ToolNotFoundException;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The only thing the runner and the model know about tools.
 *
 * Tools are injected from the DI container (tag `cable_car.tool`), so adding a
 * tool never requires touching the runner.
 */
final class ToolRegistry
{
    /** @var array<string, CodingToolInterface>|null */
    private ?array $indexed = null;

    /**
     * @param iterable<CodingToolInterface> $tools
     */
    public function __construct(private readonly iterable $tools)
    {
    }

    /**
     * @return list<CodingToolInterface>
     */
    public function all(): array
    {
        return array_values($this->index());
    }

    public function has(string $name): bool
    {
        return isset($this->index()[$name]);
    }

    /**
     * @throws ToolNotFoundException
     */
    public function get(string $name): CodingToolInterface
    {
        return $this->index()[$name] ?? throw new ToolNotFoundException(sprintf('Unknown tool "%s".', $name));
    }

    /**
     * Tool definitions in the format expected by Symfony AI platforms.
     *
     * The execution reference is never used by Cable Car (the runner executes
     * tools itself) but the platform contract requires one.
     *
     * @return list<Tool>
     */
    public function getAiTools(): array
    {
        $definitions = [];
        foreach ($this->all() as $tool) {
            $definitions[] = new Tool(
                new ExecutionReference($tool::class, 'execute'),
                $tool->getName(),
                $tool->getDescription(),
                $tool->getInputSchema(),
            );
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->index());
    }

    /**
     * @return array<string, CodingToolInterface>
     */
    private function index(): array
    {
        if (null === $this->indexed) {
            $this->indexed = [];
            foreach ($this->tools as $tool) {
                $this->indexed[$tool->getName()] = $tool;
            }
        }

        return $this->indexed;
    }
}
