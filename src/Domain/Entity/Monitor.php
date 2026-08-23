<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Symfony\Component\Uid\Uuid;

class Monitor
{
    private ?int $id = null;
    private string $publicId;
    private int $tenantId;
    private string $name;
    private string $type; // 'http' or 'smtp'
    private string $target;
    private int $interval; // in minutes
    private ?int $expectedStatus = null;
    private ?string $regexCheck = null;
    private ?string $smtpUsername = null;
    private ?string $smtpPasswordEncrypted = null;
    private ?string $smtpSecure = null;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        int $tenantId,
        string $name,
        string $type,
        string $target,
        int $interval = 5
    ) {
        $this->publicId = Uuid::v4()->toRfc4122();
        $this->tenantId = $tenantId;
        $this->name = $name;
        $this->type = $type;
        $this->target = $target;
        $this->interval = $interval;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): self
    {
        $this->target = $target;
        return $this;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function setInterval(int $interval): self
    {
        $this->interval = $interval;
        return $this;
    }

    public function getExpectedStatus(): ?int
    {
        return $this->expectedStatus;
    }

    public function setExpectedStatus(?int $expectedStatus): self
    {
        $this->expectedStatus = $expectedStatus;
        return $this;
    }

    public function getRegexCheck(): ?string
    {
        return $this->regexCheck;
    }

    public function setRegexCheck(?string $regexCheck): self
    {
        $this->regexCheck = $regexCheck;
        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $smtpUsername): self
    {
        $this->smtpUsername = $smtpUsername;
        return $this;
    }

    public function getSmtpPasswordEncrypted(): ?string
    {
        return $this->smtpPasswordEncrypted;
    }

    public function setSmtpPasswordEncrypted(?string $smtpPasswordEncrypted): self
    {
        $this->smtpPasswordEncrypted = $smtpPasswordEncrypted;
        return $this;
    }

    public function getSmtpSecure(): ?string
    {
        return $this->smtpSecure;
    }

    public function setSmtpSecure(?string $smtpSecure): self
    {
        $this->smtpSecure = $smtpSecure;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
