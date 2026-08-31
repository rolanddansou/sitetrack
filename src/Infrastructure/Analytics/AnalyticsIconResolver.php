<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

/**
 * Maps a raw country/browser/OS/device value to a ux_icon()-ready string
 * (e.g. 'flags:fr', 'logos:chrome'), or null when there's no confident
 * mapping. Pure and stateless — no I/O, unit-tested in isolation, same
 * convention as ChannelClassifier.
 *
 * Browser/OS values come straight from ua-parser's family taxonomy, which
 * has dozens of possible values (long-tail browsers/OS forks) — the curated
 * lists below cover common cases only and are expected to return null for
 * anything else, permanently, the same trade-off already made by
 * globe_controller.js's COUNTRY_CENTROIDS table ("unmapped codes are simply
 * skipped").
 */
class AnalyticsIconResolver
{
    /**
     * Exactly the codes with a real SVG under assets/icons/flags/ — kept as
     * an explicit allow-list (not just a `^[A-Z]{2}$` regex) so a malformed
     * or spoofed X-Country-Code header value can never produce an icon path
     * with no backing file.
     */
    private const KNOWN_COUNTRY_CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CP', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DG', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD',
        'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY',
        'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'IC', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS',
        'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF',
        'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX',
        'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA',
        'PC', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE',
        'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM',
        'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK',
        'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA',
        'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'XK', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** @var array<string, string> */
    private const BROWSER_ICONS = [
        'Chrome' => 'logos:chrome',
        'Chrome Mobile' => 'logos:chrome',
        'Chrome Mobile iOS' => 'logos:chrome',
        'Chrome Mobile WebView' => 'logos:chrome',
        'Firefox' => 'logos:firefox',
        'Firefox Mobile' => 'logos:firefox',
        'Firefox iOS' => 'logos:firefox',
        'Safari' => 'logos:safari',
        'Mobile Safari' => 'logos:safari',
        'Edge' => 'logos:edge',
        'Edge Mobile' => 'logos:edge',
        'Opera' => 'logos:opera',
        'Opera Mobi' => 'logos:opera',
        'IE' => 'logos:internet-explorer',
    ];

    /** @var array<string, string> */
    private const OS_ICONS = [
        'Windows' => 'logos:windows',
        'Mac OS X' => 'logos:apple',
        'iOS' => 'logos:apple',
        'Linux' => 'logos:linux',
        'Ubuntu' => 'logos:ubuntu',
        'Android' => 'logos:android',
    ];

    /** @var array<string, string> */
    private const DEVICE_ICONS = [
        'desktop' => 'heroicons:computer-desktop',
        'mobile' => 'heroicons:device-phone-mobile',
        'tablet' => 'heroicons:device-tablet',
    ];

    public function resolveCountryIcon(?string $country): ?string
    {
        if ($country === null || !preg_match('/^[A-Z]{2}$/', $country) || !in_array($country, self::KNOWN_COUNTRY_CODES, true)) {
            return null;
        }

        return 'flags:' . strtolower($country);
    }

    public function resolveBrowserIcon(?string $browser): ?string
    {
        return $browser !== null ? (self::BROWSER_ICONS[$browser] ?? null) : null;
    }

    public function resolveOsIcon(?string $os): ?string
    {
        return $os !== null ? (self::OS_ICONS[$os] ?? null) : null;
    }

    /**
     * Unlike the other resolvers, this always returns a real icon — $device
     * is the app's own classifyDevice() enum ('desktop'|'mobile'|'tablet'),
     * never an open-ended external value.
     */
    public function resolveDeviceIcon(string $device): string
    {
        return self::DEVICE_ICONS[$device] ?? 'heroicons:computer-desktop';
    }
}
