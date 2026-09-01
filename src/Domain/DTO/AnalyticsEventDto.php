<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class AnalyticsEventDto
{
    public function __construct(
        public readonly string $siteId,
        public readonly string $path,
        public readonly ?string $referrer,
        public readonly ?string $country,
        public readonly string $sessionId,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $region = null,
        public readonly ?string $city = null,
        public readonly ?string $utmSource = null,
        public readonly ?string $utmMedium = null,
        public readonly ?string $utmCampaign = null,
        public readonly ?string $device = null,
        public readonly ?string $browser = null,
        public readonly ?string $browserVersion = null,
        public readonly ?string $os = null,
        public readonly ?string $osVersion = null,
        public readonly string $eventType = 'pageview',
        public readonly ?string $eventName = null,
        public readonly ?array $eventProps = null,
        public readonly ?int $screenWidth = null,
        public readonly ?int $screenHeight = null
    ) {}
}
