<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class WorkspaceInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';
}
