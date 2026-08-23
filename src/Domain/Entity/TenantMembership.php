<?php

declare(strict_types=1);

namespace App\Domain\Entity;

class TenantMembership
{
    private ?int $id = null;
    private int $tenantId;
    private int $identityId;
    private string $role;
    private \DateTimeImmutable $createdAt;

    public function __construct(int $tenantId, int $identityId, string $role = 'owner')
    {
        $this->tenantId = $tenantId;
        $this->identityId = $identityId;
        $this->role = $role;
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

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getIdentityId(): int
    {
        return $this->identityId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
