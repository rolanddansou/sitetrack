<?php

declare(strict_types=1);

namespace App\Domain\Entity;

class AlertEvent
{
    private ?int $id = null;
    private int $ruleId;
    private string $status; // 'triggered', 'resolved'
    private \DateTimeImmutable $triggeredAt;
    private ?\DateTimeImmutable $resolvedAt = null;
    private bool $notified;

    public function __construct(
        int $ruleId,
        string $status,
        \DateTimeImmutable $triggeredAt,
        bool $notified = false
    ) {
        $this->ruleId = $ruleId;
        $this->status = $status;
        $this->triggeredAt = $triggeredAt;
        $this->notified = $notified;
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

    public function getRuleId(): int
    {
        return $this->ruleId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getTriggeredAt(): \DateTimeImmutable
    {
        return $this->triggeredAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function isNotified(): bool
    {
        return $this->notified;
    }

    public function setNotified(bool $notified): self
    {
        $this->notified = $notified;
        return $this;
    }
}
