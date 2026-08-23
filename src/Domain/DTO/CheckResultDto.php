<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use App\Domain\Entity\CheckResult;

class CheckResultDto
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $monitorId,
        public readonly string $status,
        public readonly int $responseTimeMs,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly ?string $errorMessage
    ) {}

    public static function fromEntity(CheckResult $entity): self
    {
        return new self(
            $entity->getId(),
            $entity->getMonitorId(),
            $entity->getStatus(),
            $entity->getResponseTimeMs(),
            $entity->getCheckedAt(),
            $entity->getErrorMessage()
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'up';
    }

    public function isFailure(): bool
    {
        return !$this->isSuccess();
    }
}
