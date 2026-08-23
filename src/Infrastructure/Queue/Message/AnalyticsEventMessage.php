<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\Message;

use App\Domain\DTO\AnalyticsEventDto;

class AnalyticsEventMessage
{
    public function __construct(private AnalyticsEventDto $dto) {}

    public function getDto(): AnalyticsEventDto
    {
        return $this->dto;
    }
}
