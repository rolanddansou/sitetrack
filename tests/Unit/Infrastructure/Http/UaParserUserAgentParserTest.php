<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\UaParserUserAgentParser;
use PHPUnit\Framework\TestCase;

class UaParserUserAgentParserTest extends TestCase
{
    private const DESKTOP_CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private const MOBILE_SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const TABLET_SAFARI_IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const MOBILE_CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

    public function testReturnsEmptyForNullUserAgent(): void
    {
        $parser = new UaParserUserAgentParser();

        $result = $parser->parse(null);

        $this->assertSame(
            ['device' => null, 'browser' => null, 'browserVersion' => null, 'os' => null, 'osVersion' => null],
            $result
        );
    }

    public function testReturnsEmptyForEmptyUserAgent(): void
    {
        $parser = new UaParserUserAgentParser();

        $result = $parser->parse('');

        $this->assertSame(
            ['device' => null, 'browser' => null, 'browserVersion' => null, 'os' => null, 'osVersion' => null],
            $result
        );
    }

    public function testParsesDesktopChromeOnWindows(): void
    {
        $parser = new UaParserUserAgentParser();

        $result = $parser->parse(self::DESKTOP_CHROME_WINDOWS);

        $this->assertSame('desktop', $result['device']);
        $this->assertSame('Chrome', $result['browser']);
        $this->assertStringStartsWith('120.', $result['browserVersion']);
        $this->assertSame('Windows', $result['os']);
    }

    public function testParsesMobileSafariOnIphoneAsMobile(): void
    {
        $parser = new UaParserUserAgentParser();

        $result = $parser->parse(self::MOBILE_SAFARI_IPHONE);

        $this->assertSame('mobile', $result['device']);
        $this->assertSame('iOS', $result['os']);
    }

    public function testParsesSafariOnIpadAsTablet(): void
    {
        $parser = new UaParserUserAgentParser();

        $result = $parser->parse(self::TABLET_SAFARI_IPAD);

        $this->assertSame('tablet', $result['device']);
    }

    public function testParsesChromeOnAndroidAsMobile(): void
    {
        $parser = new UaParserUserAgentParser();

        $result = $parser->parse(self::MOBILE_CHROME_ANDROID);

        $this->assertSame('mobile', $result['device']);
        $this->assertSame('Android', $result['os']);
    }
}
