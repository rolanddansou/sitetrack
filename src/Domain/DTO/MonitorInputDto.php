<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class MonitorInputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['http', 'smtp'])]
    public string $type = 'http';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $target = '';

    #[Assert\Range(min: 1, max: 1440)]
    public int $interval = 5;

    public ?int $expectedStatus = 200;

    public ?string $regexCheck = null;

    public ?string $smtpUsername = null;

    public ?string $smtpPassword = null;

    public ?string $smtpSecure = null;
}
