<?php

namespace App\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Adds a nullable `deletedAt` timestamp for soft deletion.
 *
 * Persistence-level only: to automatically exclude soft-deleted rows from
 * queries, enable a Doctrine filter (or a repository scope) in your project.
 */
trait SoftDeleteTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function softDelete(): static
    {
        if ($this->deletedAt === null) {
            $this->deletedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function restore(): static
    {
        $this->deletedAt = null;

        return $this;
    }
}
