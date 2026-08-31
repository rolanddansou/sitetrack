<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Analytics;

use App\Infrastructure\Analytics\AnalyticsIconResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnalyticsIconResolverTest extends TestCase
{
    #[DataProvider('provideCountryCases')]
    public function testResolveCountryIcon(?string $country, ?string $expected): void
    {
        $resolver = new AnalyticsIconResolver();

        $this->assertSame($expected, $resolver->resolveCountryIcon($country));
    }

    public static function provideCountryCases(): array
    {
        return [
            'known code' => ['FR', 'flags:fr'],
            'another known code' => ['US', 'flags:us'],
            'null' => [null, null],
            'lowercase' => ['fr', null],
            'three letters' => ['FRA', null],
            'well-formed but unmapped' => ['ZZ', null],
        ];
    }

    #[DataProvider('provideBrowserCases')]
    public function testResolveBrowserIcon(?string $browser, ?string $expected): void
    {
        $resolver = new AnalyticsIconResolver();

        $this->assertSame($expected, $resolver->resolveBrowserIcon($browser));
    }

    public static function provideBrowserCases(): array
    {
        return [
            'chrome' => ['Chrome', 'logos:chrome'],
            'chrome mobile aliases to chrome' => ['Chrome Mobile', 'logos:chrome'],
            'firefox' => ['Firefox', 'logos:firefox'],
            'mobile safari aliases to safari' => ['Mobile Safari', 'logos:safari'],
            'null' => [null, null],
            'unmapped long-tail browser' => ['Vivaldi', null],
        ];
    }

    #[DataProvider('provideOsCases')]
    public function testResolveOsIcon(?string $os, ?string $expected): void
    {
        $resolver = new AnalyticsIconResolver();

        $this->assertSame($expected, $resolver->resolveOsIcon($os));
    }

    public static function provideOsCases(): array
    {
        return [
            'windows' => ['Windows', 'logos:windows'],
            'mac os x' => ['Mac OS X', 'logos:apple'],
            'ios aliases to apple too' => ['iOS', 'logos:apple'],
            'null' => [null, null],
            'unmapped long-tail os' => ['FreeBSD', null],
        ];
    }

    #[DataProvider('provideDeviceCases')]
    public function testResolveDeviceIconAlwaysResolves(string $device, string $expected): void
    {
        $resolver = new AnalyticsIconResolver();

        $this->assertSame($expected, $resolver->resolveDeviceIcon($device));
    }

    public static function provideDeviceCases(): array
    {
        return [
            'desktop' => ['desktop', 'heroicons:computer-desktop'],
            'mobile' => ['mobile', 'heroicons:device-phone-mobile'],
            'tablet' => ['tablet', 'heroicons:device-tablet'],
        ];
    }

    /**
     * Permanent regression guard: a resolver entry pointing at a local SVG
     * that was never copied into assets/icons/ would only surface as a dev
     * 500 on /analytics (ignore_not_found: false there) — this test catches
     * it in CI instead.
     */
    public function testEveryPossibleIconHasABackingFile(): void
    {
        $resolver = new AnalyticsIconResolver();
        $iconDir = __DIR__ . '/../../../../assets/icons';

        $icons = [];
        foreach (self::provideCountryCases() as [$input, ]) {
            if ($input !== null) {
                $icons[] = $resolver->resolveCountryIcon($input);
            }
        }
        foreach (self::provideBrowserCases() as [$input, ]) {
            if ($input !== null) {
                $icons[] = $resolver->resolveBrowserIcon($input);
            }
        }
        foreach (self::provideOsCases() as [$input, ]) {
            if ($input !== null) {
                $icons[] = $resolver->resolveOsIcon($input);
            }
        }
        foreach (self::provideDeviceCases() as [$input, ]) {
            $icons[] = $resolver->resolveDeviceIcon($input);
        }

        // Also check every value in the resolver's internal maps directly,
        // not just the ones exercised by the data providers above.
        $ref = new \ReflectionClass(AnalyticsIconResolver::class);
        $icons = array_merge(
            $icons,
            array_values($ref->getConstant('BROWSER_ICONS')),
            array_values($ref->getConstant('OS_ICONS')),
            array_values($ref->getConstant('DEVICE_ICONS')),
            array_map(static fn (string $code) => 'flags:' . strtolower($code), $ref->getConstant('KNOWN_COUNTRY_CODES'))
        );

        $missing = [];
        foreach (array_unique(array_filter($icons)) as $icon) {
            if (str_starts_with($icon, 'heroicons:')) {
                continue; // served via ux-icons' Iconify fallback, not a local file — out of scope here.
            }
            $path = $iconDir . '/' . str_replace(':', '/', $icon) . '.svg';
            if (!file_exists($path)) {
                $missing[] = $icon . ' (' . $path . ')';
            }
        }

        $this->assertSame([], $missing, 'Every non-heroicon icon the resolver can return must have a backing SVG file.');
    }
}
