<?php

namespace App\Entity;

use App\Repository\AgentStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One observable step of a run (model message, tool call, tool result, final
 * answer or error). Steps are what the UI timeline displays and what makes a
 * past run auditable.
 */
#[ORM\Entity(repositoryClass: AgentStepRepository::class)]
#[ORM\Table(name: 'agent_step')]
class AgentStep extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: AgentRun::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AgentRun $run = null;

    #[ORM\Column]
    private int $sequence = 0;

    #[ORM\Column(length: 20, enumType: AgentStepType::class)]
    private AgentStepType $type = AgentStepType::Model;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $toolName = null;

    /**
     * Normalized tool arguments (long values truncated).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $toolInput = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultSummary = null;

    /**
     * Human readable form of the step (model message, tool summary, …).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $success = true;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $durationMs = null;

    public function getRun(): ?AgentRun
    {
        return $this->run;
    }

    public function setRun(?AgentRun $run): static
    {
        $this->run = $run;

        return $this;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getType(): AgentStepType
    {
        return $this->type;
    }

    public function setType(AgentStepType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function setToolName(?string $toolName): static
    {
        $this->toolName = $toolName;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getToolInput(): ?array
    {
        return $this->toolInput;
    }

    /**
     * @param array<string, mixed>|null $toolInput
     */
    public function setToolInput(?array $toolInput): static
    {
        $this->toolInput = $toolInput;

        return $this;
    }

    public function getResultSummary(): ?string
    {
        return $this->resultSummary;
    }

    public function setResultSummary(?string $resultSummary): static
    {
        $this->resultSummary = $resultSummary;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;

        return $this;
    }

    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    public function setDurationMs(?float $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }
}
