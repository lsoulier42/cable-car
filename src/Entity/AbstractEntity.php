<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Trait\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

abstract class AbstractEntity
{
    use Timestampable;

    /**
     * @var int|null $id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column(nullable: false)]
    protected ?int $id = null;

    /**
     * @var Uuid|null $uuid
     */
    #[ORM\Column(type: UuidType::NAME, unique: true, nullable: false)]
    protected ?Uuid $uuid = null;

    public function __construct()
    {
        $this->setTimes();
        $this->uuid = Uuid::v4();
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @param int|null $id
     * @return $this
     */
    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return Uuid|null
     */
    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    /**
     * @param Uuid|null $uuid
     * @return $this
     */
    public function setUuid(?Uuid $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }
}
