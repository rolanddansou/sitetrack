<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\DTO\MonitorDto;

interface SmtpTesterInterface
{
    public function sendTestMail(MonitorDto $monitor, string $token): void;
}
