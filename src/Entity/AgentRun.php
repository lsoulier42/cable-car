<?php

namespace App\Entity;

use App\Agent\Runner\StopReason;
use App\Repository\AgentRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One coding task given to the agent.
 *
 * The run itself stays independent from HTTP: the console command creates
 * transient outcomes, this entity records the durable history the UI polls.
 */
#[ORM\Entity(repositoryClass: AgentRunRepository::class)]
#[ORM\Table(name: 'agent_run')]
class AgentRun extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 190)]
    #[Groups(['run:read'])]
    private ?string $workspace = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['run:read'])]
    private ?string $task = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['run:read'])]
    private ?string $model = null;

    #[ORM\Column(length: 32, enumType: AgentRunStatus::class)]
    #[Groups(['run:read'])]
    private AgentRunStatus $status = AgentRunStatus::Pending;

    #[ORM\Column(length: 40, nullable: true, enumType: StopReason::class)]
    #[Groups(['run:read'])]
    private ?StopReason $stopReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['run:read'])]
    private ?string $finalMessage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['run:read'])]
    private ?string $error = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['run:read'])]
    private int $iterationCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['run:read'])]
    private int $toolCallCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['run:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['run:read'])]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * Snapshot of the files changed by the run (path, status, diff).
     *
     * @var list<array{path: string, status: string, diff?: string|null}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $changedFiles = [];

    /**
     * Limits the run was executed with, so a past run stays explainable.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $limits = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $cancellationRequested = false;

    /**
     * @var Collection<int, AgentStep>
     */
    #[ORM\OneToMany(
        targetEntity: AgentStep::class,
        mappedBy: 'run',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        parent::__construct();
        $this->steps = new ArrayCollection();
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getWorkspace(): ?string
    {
        return $this->workspace;
    }

    public function setWorkspace(string $workspace): static
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function getTask(): ?string
    {
        return $this->task;
    }

    public function setTask(string $task): static
    {
        $this->task = $task;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getStatus(): AgentRunStatus
    {
        return $this->status;
    }

    public function setStatus(AgentRunStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStopReason(): ?StopReason
    {
        return $this->stopReason;
    }

    public function setStopReason(?StopReason $stopReason): static
    {
        $this->stopReason = $stopReason;

        return $this;
    }

    public function getFinalMessage(): ?string
    {
        return $this->finalMessage;
    }

    public function setFinalMessage(?string $finalMessage): static
    {
        $this->finalMessage = $finalMessage;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getIterationCount(): int
    {
        return $this->iterationCount;
    }

    public function setIterationCount(int $iterationCount): static
    {
        $this->iterationCount = $iterationCount;

        return $this;
    }

    public function getToolCallCount(): int
    {
        return $this->toolCallCount;
    }

    public function setToolCallCount(int $toolCallCount): static
    {
        $this->toolCallCount = $toolCallCount;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /**
     * @return list<array{path: string, status: string, diff?: string|null}>
     */
    public function getChangedFiles(): array
    {
        return $this->changedFiles;
    }

    /**
     * @param list<array{path: string, status: string, diff?: string|null}> $changedFiles
     */
    public function setChangedFiles(array $changedFiles): static
    {
        $this->changedFiles = $changedFiles;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getLimits(): array
    {
        return $this->limits;
    }

    /**
     * @param array<string, int> $limits
     */
    public function setLimits(array $limits): static
    {
        $this->limits = $limits;

        return $this;
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancellationRequested;
    }

    public function requestCancellation(): static
    {
        $this->cancellationRequested = true;

        return $this;
    }

    public function setCancellationRequested(bool $cancellationRequested): static
    {
        $this->cancellationRequested = $cancellationRequested;

        return $this;
    }

    /**
     * @return Collection<int, AgentStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(AgentStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setRun($this);
        }

        return $this;
    }

    public function nextSequence(): int
    {
        return $this->steps->count() + 1;
    }

    public function getDurationSeconds(): ?float
    {
        if (null === $this->startedAt) {
            return null;
        }

        return ($this->finishedAt ?? new \DateTimeImmutable())->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
