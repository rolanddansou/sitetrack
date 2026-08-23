<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\MessageHandler;

use App\Domain\UseCase\RunUptimeCheckUseCase;
use App\Infrastructure\Queue\Message\UptimeCheckMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UptimeCheckMessageHandler
{
    public function __construct(private RunUptimeCheckUseCase $runUptimeCheck) {}

    public function __invoke(UptimeCheckMessage $message): void
    {
        $this->runUptimeCheck->execute($message->getMonitorId(), $message->getNow());
    }
}
