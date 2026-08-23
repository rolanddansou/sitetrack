<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\MessageHandler;

use App\Infrastructure\Queue\Message\DispatchChecksMessage;
use App\Infrastructure\Scheduler\DispatchDueChecksService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DispatchChecksMessageHandler
{
    public function __construct(private DispatchDueChecksService $dispatchDueChecks) {}

    public function __invoke(DispatchChecksMessage $message): void
    {
        $this->dispatchDueChecks->dispatchDueChecks(new \DateTimeImmutable());
    }
}
