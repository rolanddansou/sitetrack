<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\DTO\MonitorDto;
use App\Domain\Entity\CheckResult;

interface PingServiceInterface
{
    public function ping(MonitorDto $monitor): CheckResult;
}
