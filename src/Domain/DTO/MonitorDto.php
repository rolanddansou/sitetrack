<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use App\Domain\Entity\Monitor;

class MonitorDto
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly string $type,
        public readonly string $target,
        public readonly int $interval,
        public readonly ?int $expectedStatus,
        public readonly ?string $regexCheck,
        public readonly ?string $smtpUsername,
        public readonly ?string $smtpPasswordEncrypted,
        public readonly ?string $smtpSecure,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt
    ) {}

    public static function fromEntity(Monitor $entity): self
    {
        return new self(
            $entity->getId(),
            $entity->getTenantId(),
            $entity->getName(),
            $entity->getType(),
            $entity->getTarget(),
            $entity->getInterval(),
            $entity->getExpectedStatus(),
            $entity->getRegexCheck(),
            $entity->getSmtpUsername(),
            $entity->getSmtpPasswordEncrypted(),
            $entity->getSmtpSecure(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt()
        );
    }
}
