<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\Message;

class SmtpCheckMessage
{
    public function __construct(
        private int $monitorId,
        private string $token,
        private \DateTimeImmutable $now
    ) {}

    public function getMonitorId(): int
    {
        return $this->monitorId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getNow(): \DateTimeImmutable
    {
        return $this->now;
    }
}
