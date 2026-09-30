<?php

namespace App\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Adds a unique `slug` column, automatically generated from getSlugSource()
 * on persist/update when none is set.
 *
 * The using entity must implement `getSlugSource(): string`.
 */
trait SlugTrait
{
    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    abstract public function getSlugSource(): string;

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function ensureSlug(): void
    {
        if (($this->slug ?? '') === '') {
            $this->slug = (new AsciiSlugger())
                ->slug($this->getSlugSource())
                ->lower()
                ->toString();
        }
    }
}
