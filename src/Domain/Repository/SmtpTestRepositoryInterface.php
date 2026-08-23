<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\SmtpTest;

interface SmtpTestRepositoryInterface
{
    public function save(SmtpTest $smtpTest): void;

    public function find(string $id): ?SmtpTest;

    /**
     * @return SmtpTest[]
     */
    public function findExpiredSentTests(\DateTimeImmutable $before): array;
}
