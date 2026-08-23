<?php

declare(strict_types=1);

namespace App\Domain\Entity;

class SmtpTest
{
    private string $id; // Token
    private int $monitorId;
    private string $status; // 'sent', 'delivered', 'failed', 'timeout'
    private \DateTimeImmutable $sentAt;
    private ?\DateTimeImmutable $receivedAt = null;
    private ?int $deliveryTimeSeconds = null;
    private ?float $spamScore = null;
    private ?bool $spfPassed = null;
    private ?bool $dkimPassed = null;
    private ?bool $dmarcPassed = null;
    private ?string $errorMessage = null;

    public function __construct(
        string $id,
        int $monitorId,
        string $status = 'sent'
    ) {
        $this->id = $id;
        $this->monitorId = $monitorId;
        $this->status = $status;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMonitorId(): int
    {
        return $this->monitorId;
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

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(?\DateTimeImmutable $receivedAt): self
    {
        $this->receivedAt = $receivedAt;
        return $this;
    }

    public function getDeliveryTimeSeconds(): ?int
    {
        return $this->deliveryTimeSeconds;
    }

    public function setDeliveryTimeSeconds(?int $deliveryTimeSeconds): self
    {
        $this->deliveryTimeSeconds = $deliveryTimeSeconds;
        return $this;
    }

    public function getSpamScore(): ?float
    {
        return $this->spamScore;
    }

    public function setSpamScore(?float $spamScore): self
    {
        $this->spamScore = $spamScore;
        return $this;
    }

    public function getSpfPassed(): ?bool
    {
        return $this->spfPassed;
    }

    public function setSpfPassed(?bool $spfPassed): self
    {
        $this->spfPassed = $spfPassed;
        return $this;
    }

    public function getDkimPassed(): ?bool
    {
        return $this->dkimPassed;
    }

    public function setDkimPassed(?bool $dkimPassed): self
    {
        $this->dkimPassed = $dkimPassed;
        return $this;
    }

    public function getDmarcPassed(): ?bool
    {
        return $this->dmarcPassed;
    }

    public function setDmarcPassed(?bool $dmarcPassed): self
    {
        $this->dmarcPassed = $dmarcPassed;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->status === 'delivered';
    }

    public function isFailure(): bool
    {
        return $this->status === 'failed' || $this->status === 'timeout';
    }
}
