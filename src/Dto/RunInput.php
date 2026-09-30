<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated input for POST /api/agent/runs.
 *
 * The workspace is a *name* resolved against the configured workspaces root:
 * the API never accepts an arbitrary host path.
 */
final class RunInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 190)]
    public string $workspace = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max: 4000)]
    public string $task = '';

    #[Assert\Length(max: 120)]
    public ?string $model = null;
}
