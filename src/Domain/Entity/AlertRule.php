<?php

declare(strict_types=1);

namespace App\Domain\Entity;

class AlertRule
{
    private ?int $id = null;
    private int $monitorId;
    private string $conditionType; // 'down_count', 'smtp_fail_count', 'latency_threshold'
    private int $threshold;
    private string $channel; // 'email', 'telegram', 'slack'
    private string $recipient; // email address, chat id, or webhook URL
    private int $cooldownMinutes;

    public function __construct(
        int $monitorId,
        string $conditionType,
        int $threshold,
        string $channel,
        string $recipient,
        int $cooldownMinutes = 60
    ) {
        $this->monitorId = $monitorId;
        $this->conditionType = $conditionType;
        $this->threshold = $threshold;
        $this->channel = $channel;
        $this->recipient = $recipient;
        $this->cooldownMinutes = $cooldownMinutes;
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

    public function getConditionType(): string
    {
        return $this->conditionType;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getCooldownMinutes(): int
    {
        return $this->cooldownMinutes;
    }
}
