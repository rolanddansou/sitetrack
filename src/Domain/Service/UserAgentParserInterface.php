<?php

declare(strict_types=1);

namespace App\Domain\Service;

interface UserAgentParserInterface
{
    /**
     * @return array{device: ?string, browser: ?string, browserVersion: ?string, os: ?string, osVersion: ?string}
     */
    public function parse(?string $userAgent): array;
}
