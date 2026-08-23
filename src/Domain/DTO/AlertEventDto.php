<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use App\Domain\Entity\AlertEvent;

class AlertEventDto
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $ruleId,
        public readonly string $status,
        public readonly \DateTimeImmutable $triggeredAt,
        public readonly ?\DateTimeImmutable $resolvedAt,
        public readonly bool $notified
    ) {}

    public static function fromEntity(AlertEvent $entity): self
    {
        return new self(
            $entity->getId(),
            $entity->getRuleId(),
            $entity->getStatus(),
            $entity->getTriggeredAt(),
            $entity->getResolvedAt(),
            $entity->isNotified()
        );
    }
}
