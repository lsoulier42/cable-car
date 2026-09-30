<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated input for the public registration endpoint (POST /api/register).
 * The plain password is hashed by the controller; it is never stored as-is.
 */
#[UniqueEntity(fields: ['email'], entityClass: User::class, message: 'register.email.already_used')]
final class RegisterInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    public string $plainPassword = '';

    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\Length(max: 80)]
    public ?string $lastName = null;
}
