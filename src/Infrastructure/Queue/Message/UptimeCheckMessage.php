<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\Message;

class UptimeCheckMessage
{
    public function __construct(
        private int $monitorId,
        private \DateTimeImmutable $now
    ) {}

    public function getMonitorId(): int
    {
        return $this->monitorId;
    }

    public function getNow(): \DateTimeImmutable
    {
        return $this->now;
    }
}
