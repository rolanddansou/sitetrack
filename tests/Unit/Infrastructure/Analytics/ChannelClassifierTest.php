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
            'google search' => ['https://www.google.com/search?q=sitetrack', null, null, 'Recherche organique'],
            'bing search' => ['https://www.bing.com/search?q=sitetrack', null, null, 'Recherche organique'],
            'duckduckgo search' => ['https://duckduckgo.com/', null, null, 'Recherche organique'],
            'twitter/x referrer' => ['https://x.com/someone/status/1', null, null, 'Social organique'],
            't.co shortlink referrer' => ['https://t.co/abc123', null, null, 'Social organique'],
            'linkedin referrer' => ['https://www.linkedin.com/feed/', null, null, 'Social organique'],
            'utm_medium=social overrides referrer' => ['https://news.ycombinator.com', 'hn', 'social', 'Social organique'],
            'utm_medium=Social-Media case-insensitive' => [null, 'ig', 'Social-Media', 'Social organique'],
            'unrelated blog referrer is Référent' => ['https://news.ycombinator.com/item?id=123', null, null, 'Référent'],
            'unrelated referrer with utm_source but no social medium' => ['https://example.com', 'newsletter', 'email', 'Référent'],
        ];
    }
}
