<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class AlertRuleInputDto
{
    #[Assert\NotBlank]
    public string $conditionType = 'down_count';

    #[Assert\Range(min: 1)]
    public int $threshold = 1;

    #[Assert\NotBlank]
    public string $channel = 'email';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $recipient = '';

    #[Assert\Range(min: 1)]
    public int $cooldownMinutes = 60;
}
