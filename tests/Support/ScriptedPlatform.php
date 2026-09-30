<?php

namespace App\Tests\Support;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * Test-only Symfony AI platform: replays scripted responses instead of calling
 * a provider.
 *
 * It is wired as the platform of the `default` model in the test environment
 * (see config/packages/cable_car.yaml), so functional tests exercise the real
 * model implementation, the real runner and the real persistence without any
 * network access.
 */
final class ScriptedPlatform implements PlatformInterface
{
    /** @var list<ResultInterface> */
    private static array $script = [];

    private ?InMemoryPlatform $catalog = null;

    /**
     * @param list<ResultInterface> $results
     */
    public static function script(array $results): void
    {
        self::$script = $results;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function toolCall(string $name, array $arguments = [], string $id = 'call_1'): ToolCallResult
    {
        return new ToolCallResult([new ToolCall($id, $name, $arguments)]);
    }

    public static function text(string $text): TextResult
    {
        return new TextResult($text);
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $result = array_shift(self::$script);

        if (null === $result) {
            throw new \RuntimeException('The scripted platform has no response left.');
        }

        // One throw-away platform per call: InMemoryPlatform resolves its script
        // eagerly, so it cannot be reused for the next turn.
        return (new InMemoryPlatform(static fn (): ResultInterface => $result))->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return ($this->catalog ??= new InMemoryPlatform(''))->getModelCatalog();
    }

    /**
     * @param list<ResultInterface> $results
     */
    public static function multiPart(array $results): MultiPartResult
    {
        return new MultiPartResult($results);
    }
}
