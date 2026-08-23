<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use App\Domain\Entity\SmtpTest;

class SmtpTestDto
{
    public function __construct(
        public readonly string $id,
        public readonly int $monitorId,
        public readonly string $status,
        public readonly \DateTimeImmutable $sentAt,
        public readonly ?\DateTimeImmutable $receivedAt,
        public readonly ?int $deliveryTimeSeconds,
        public readonly ?float $spamScore,
        public readonly ?bool $spfPassed,
        public readonly ?bool $dkimPassed,
        public readonly ?bool $dmarcPassed,
        public readonly ?string $errorMessage
    ) {}

    public static function fromEntity(SmtpTest $entity): self
    {
        return new self(
            $entity->getId(),
            $entity->getMonitorId(),
            $entity->getStatus(),
            $entity->getSentAt(),
            $entity->getReceivedAt(),
            $entity->getDeliveryTimeSeconds(),
            $entity->getSpamScore(),
            $entity->getSpfPassed(),
            $entity->getDkimPassed(),
            $entity->getDmarcPassed(),
            $entity->getErrorMessage()
        );
    }
}
