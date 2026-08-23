<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Analytics;

use App\Infrastructure\Analytics\ChannelClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChannelClassifierTest extends TestCase
{
    #[DataProvider('provideCases')]
    public function testClassify(?string $referrer, ?string $utmSource, ?string $utmMedium, string $expected): void
    {
        $classifier = new ChannelClassifier();

        $this->assertSame($expected, $classifier->classify($referrer, $utmSource, $utmMedium));
    }

    public static function provideCases(): array
    {
        return [
            'no referrer, no utm' => [null, null, null, 'Direct'],
            'empty referrer' => ['', null, null, 'Direct'],
            'google search' => ['https://www.google.com/search?q=sitetrack', null, null, 'Organic Search'],
            'bing search' => ['https://www.bing.com/search?q=sitetrack', null, null, 'Organic Search'],
            'duckduckgo search' => ['https://duckduckgo.com/', null, null, 'Organic Search'],
            'twitter/x referrer' => ['https://x.com/someone/status/1', null, null, 'Organic Social'],
            't.co shortlink referrer' => ['https://t.co/abc123', null, null, 'Organic Social'],
            'linkedin referrer' => ['https://www.linkedin.com/feed/', null, null, 'Organic Social'],
            'utm_medium=social overrides referrer' => ['https://news.ycombinator.com', 'hn', 'social', 'Organic Social'],
            'utm_medium=Social-Media case-insensitive' => [null, 'ig', 'Social-Media', 'Organic Social'],
            'unrelated blog referrer is Referral' => ['https://news.ycombinator.com/item?id=123', null, null, 'Referral'],
            'unrelated referrer with utm_source but no social medium' => ['https://example.com', 'newsletter', 'email', 'Referral'],
        ];
    }
}
