<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\AlertEvent;

interface AlertEventRepositoryInterface
{
    public function save(AlertEvent $event): void;

    public function findActiveAlert(int $ruleId): ?AlertEvent;

    public function findLastTriggeredAlert(int $ruleId): ?AlertEvent;
}
