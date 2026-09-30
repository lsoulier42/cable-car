<?php

namespace App\Agent\Model;

use App\Agent\DTO\ModelTurn;
use App\Agent\Runner\AgentContext;

/**
 * The only model interface the harness knows about.
 *
 * Symfony AI (or any future gateway) is an implementation detail: the runner
 * depends on this contract, so tests can inject a scripted fake model and no
 * provider-specific assumption leaks into the domain.
 */
interface CodingModelInterface
{
    /**
     * Configuration name of the model (`default`, `golden_gate`, …).
     */
    public function getName(): string;

    /**
     * Human readable label shown in the CLI and the UI.
     */
    public function getLabel(): string;

    /**
     * Provider model identifier (e.g. `gpt-5-mini`, `gemma4:12b`).
     */
    public function getModelId(): string;

    /**
     * Produces the next turn: text and/or tool calls.
     *
     * @throws \App\Agent\Exception\ModelException
     */
    public function respond(AgentContext $context): ModelTurn;
}
