<?php

declare(strict_types=1);

namespace App\Infrastructure\Monitoring;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Counts DBAL queries for the current request. Wired as the logger behind
 * a Doctrine\DBAL\Logging\Middleware service (see config/services.yaml) —
 * that middleware calls log() once per query, so counting log() calls is
 * equivalent to counting queries. Exists because Doctrine\DBAL\Configuration
 * ::getSQLLogger()/setSQLLogger(), the pre-4.0 way of doing this, was
 * removed in DBAL 4.
 */
class QueryCounter extends AbstractLogger
{
    private int $count = 0;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        ++$this->count;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
