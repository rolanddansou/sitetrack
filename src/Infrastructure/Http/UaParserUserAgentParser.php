<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Service\UserAgentParserInterface;
use UAParser\Parser;

/**
 * Browser/OS come from ua-parser (the same regex ruleset used across most
 * languages' UA parsers). Device category (mobile/tablet/desktop) is kept
 * as a simple regex classification instead of ua-parser's raw device family
 * (e.g. "iPhone", "Generic Smartphone") since that's what's actually useful
 * for visitor-type breakdowns.
 */
class UaParserUserAgentParser implements UserAgentParserInterface
{
    private ?Parser $parser = null;

    public function parse(?string $userAgent): array
    {
        $empty = ['device' => null, 'browser' => null, 'browserVersion' => null, 'os' => null, 'osVersion' => null];

        if ($userAgent === null || $userAgent === '') {
            return $empty;
        }

        try {
            $client = $this->getParser()->parse($userAgent);
        } catch (\Throwable) {
            return $empty;
        }

        return [
            'device' => $this->classifyDevice($userAgent),
            'browser' => $this->normalise($client->ua->family),
            'browserVersion' => $this->normalise($client->ua->toVersion()),
            'os' => $this->normalise($client->os->family),
            'osVersion' => $this->normalise($client->os->toVersion()),
        ];
    }

    private function getParser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = Parser::create();
        }

        return $this->parser;
    }

    private function classifyDevice(string $userAgent): string
    {
        if (preg_match('/Tablet|iPad/i', $userAgent) === 1) {
            return 'tablet';
        }

        if (preg_match('/Mobi|Android/i', $userAgent) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function normalise(?string $value): ?string
    {
        return ($value !== null && $value !== '' && $value !== 'Other') ? $value : null;
    }
}
