<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated input for the profile update endpoint (PATCH /api/me).
 * plainPassword is optional: when provided, the password is re-hashed.
 */
final class MePayload
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\Length(min: 8, max: 4096)]
    public ?string $plainPassword = null;
}
