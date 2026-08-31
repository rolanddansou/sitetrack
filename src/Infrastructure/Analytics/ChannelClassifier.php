<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

/**
 * Classifies a pageview into a traffic channel from its referrer/UTM data.
 * Pure and stateless — no I/O, unit-tested in isolation.
 */
class ChannelClassifier
{
    private const SEARCH_ENGINE_HOSTS = ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'baidu.com', 'yandex.', 'ecosia.org'];

    private const SOCIAL_HOSTS = ['twitter.com', 'x.com', 't.co', 'facebook.com', 'linkedin.com', 'instagram.com', 'reddit.com', 'pinterest.com', 'tiktok.com'];

    public function classify(?string $referrer, ?string $utmSource, ?string $utmMedium): string
    {
        if ($utmMedium !== null && in_array(strtolower($utmMedium), ['social', 'social-media', 'social_media'], true)) {
            return 'Social organique';
        }

        if ($referrer === null || $referrer === '') {
            return 'Direct';
        }

        $host = strtolower((string) (parse_url($referrer, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return 'Direct';
        }

        foreach (self::SOCIAL_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'Social organique';
            }
        }

        foreach (self::SEARCH_ENGINE_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'Recherche organique';
            }
        }

        return 'Référent';
    }
}
