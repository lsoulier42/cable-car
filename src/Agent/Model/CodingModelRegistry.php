<?php

namespace App\Agent\Model;

use App\Agent\Exception\ModelException;

/**
 * The set of models declared under `cable_car.models`.
 *
 * The runner only sees model names (coming from the CLI or the API); service
 * ids and provider details stay in configuration.
 */
final class CodingModelRegistry
{
    /** @var array<string, CodingModelInterface> */
    private array $models = [];

    /**
     * @param iterable<CodingModelInterface> $models
     */
    public function __construct(iterable $models, private readonly string $defaultName)
    {
        foreach ($models as $model) {
            $this->models[$model->getName()] = $model;
        }
    }

    /**
     * @throws ModelException
     */
    public function get(?string $name = null): CodingModelInterface
    {
        $name ??= $this->defaultName;

        return $this->models[$name] ?? throw new ModelException(sprintf(
            'Unknown model "%s". Available models: %s.',
            $name,
            implode(', ', array_keys($this->models)),
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->models[$name]);
    }

    public function getDefaultName(): string
    {
        return $this->defaultName;
    }

    /**
     * @return list<array{name: string, label: string, model: string}>
     */
    public function list(): array
    {
        $models = [];
        foreach ($this->models as $name => $model) {
            $models[] = [
                'name' => $name,
                'label' => $model->getLabel(),
                'model' => $model->getModelId(),
            ];
        }

        return $models;
    }
}
