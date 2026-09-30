<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['user:read']],
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['user:read']],
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['user:read']],
            denormalizationContext: ['groups' => ['user:write']],
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    paginationItemsPerPage: 10,
    paginationMaximumItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'email' => 'ipartial',
    'firstName' => 'ipartial',
    'lastName' => 'ipartial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
class User extends AbstractEntity implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['user:read', 'user:write', 'me:read'])]
    private ?string $email = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['user:read', 'user:write', 'me:read'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Groups(['user:read', 'user:write', 'me:read'])]
    private ?string $lastName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['user:read', 'user:write'])]
    private bool $isActive = true;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    #[Groups(['user:read', 'user:write', 'me:read'])]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[Groups(['user:read'])]
    public function getId(): ?int
    {
        return parent::getId();
    }

    #[Groups(['user:read', 'me:read'])]
    public function getUuid(): ?\Symfony\Component\Uid\Uuid
    {
        return parent::getUuid();
    }

    #[Groups(['user:read', 'me:read'])]
    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    #[Groups(['user:read', 'me:read'])]
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    #[Groups(['user:read', 'me:read'])]
    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    #[Groups(['user:read', 'me:read'])]
    #[SerializedName('isActive')]
    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    #[Groups(['user:read', 'me:read'])]
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // Clear any temporary, sensitive data stored on the user here.
    }
}
