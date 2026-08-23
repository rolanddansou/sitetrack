<?php

declare(strict_types=1);

namespace App\Domain\Entity;

class CheckResult
{
    private ?int $id = null;
    private int $monitorId;
    private string $status; // 'up', 'down', 'timeout'
    private int $responseTimeMs;
    private \DateTimeImmutable $checkedAt;
    private ?string $errorMessage = null;

    public function __construct(
        int $monitorId,
        string $status,
        int $responseTimeMs,
        \DateTimeImmutable $checkedAt,
        ?string $errorMessage = null
    ) {
        $this->monitorId = $monitorId;
        $this->status = $status;
        $this->responseTimeMs = $responseTimeMs;
        $this->checkedAt = $checkedAt;
        $this->errorMessage = $errorMessage;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getMonitorId(): int
    {
        return $this->monitorId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResponseTimeMs(): int
    {
        return $this->responseTimeMs;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
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
