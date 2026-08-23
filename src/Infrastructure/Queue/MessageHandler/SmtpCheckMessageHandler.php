<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\MessageHandler;

use App\Domain\UseCase\RunSmtpCheckUseCase;
use App\Infrastructure\Queue\Message\SmtpCheckMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SmtpCheckMessageHandler
{
    public function __construct(private RunSmtpCheckUseCase $runSmtpCheck) {}

    public function __invoke(SmtpCheckMessage $message): void
    {
        $this->runSmtpCheck->execute($message->getMonitorId(), $message->getToken(), $message->getNow());
    }
}
