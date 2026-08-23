<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class InboundEmailDto
{
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly ?bool $spfPassed = null,
        public readonly ?bool $dkimPassed = null,
        public readonly ?bool $dmarcPassed = null,
        public readonly ?float $spamScore = null
    ) {}
}
