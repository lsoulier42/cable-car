<?php

namespace App\Tests\Support;

use App\Agent\Tool\CodingToolInterface;
use App\Agent\Tool\ToolContext;
use App\Agent\Tool\ToolResult;

/**
 * Configurable tool used by the runner tests: it can record what it was asked
 * to do, return a huge output or crash on purpose.
 */
final class StubTool implements CodingToolInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param \Closure(array<string, mixed>, ToolContext): ToolResult|null $handler
     */
    public function __construct(
        private readonly string $name = 'stub_tool',
        private readonly ?\Closure $handler = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Stub tool for tests.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]];
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $this->calls[] = $input;

        if (null !== $this->handler) {
            return ($this->handler)($input, $context);
        }

        return ToolResult::success('stub result');
    }
}
