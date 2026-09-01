<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\MessageHandler;

use App\Infrastructure\Queue\Message\AnalyticsEventMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class AnalyticsEventMessageHandler
{
    public function __construct(private Connection $connection) {}

    public function __invoke(AnalyticsEventMessage $message): void
    {
        $dto = $message->getDto();

        $this->connection->insert('analytics_events', [
            'site_id' => $dto->siteId,
            'path' => $dto->path,
            'referrer' => $dto->referrer,
            'country' => $dto->country,
            'session_id' => $dto->sessionId,
            'occurred_at' => $dto->occurredAt->format('Y-m-d H:i:s'),
            'region' => $dto->region,
            'city' => $dto->city,
            'utm_source' => $dto->utmSource,
            'utm_medium' => $dto->utmMedium,
            'utm_campaign' => $dto->utmCampaign,
            'device' => $dto->device,
            'browser' => $dto->browser,
            'browser_version' => $dto->browserVersion,
            'os' => $dto->os,
            'os_version' => $dto->osVersion,
            'event_type' => $dto->eventType,
            'event_name' => $dto->eventName,
            'event_props' => $dto->eventProps !== null ? json_encode($dto->eventProps) : null,
            'screen_width' => $dto->screenWidth,
            'screen_height' => $dto->screenHeight,
        ]);
    }
}
