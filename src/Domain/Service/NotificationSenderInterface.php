<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;

interface NotificationSenderInterface
{
    public function sendAlert(AlertRuleDto $rule, MonitorDto $monitor, string $message): void;

    public function sendRecovery(AlertRuleDto $rule, MonitorDto $monitor, string $message): void;
}
