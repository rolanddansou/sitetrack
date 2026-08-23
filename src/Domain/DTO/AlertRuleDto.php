<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use App\Domain\Entity\AlertRule;

class AlertRuleDto
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $monitorId,
        public readonly string $conditionType,
        public readonly int $threshold,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly int $cooldownMinutes
    ) {}

    public static function fromEntity(AlertRule $entity): self
    {
        return new self(
            $entity->getId(),
            $entity->getMonitorId(),
            $entity->getConditionType(),
            $entity->getThreshold(),
            $entity->getChannel(),
            $entity->getRecipient(),
            $entity->getCooldownMinutes()
        );
    }
}
