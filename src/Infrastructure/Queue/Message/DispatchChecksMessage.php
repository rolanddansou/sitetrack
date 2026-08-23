<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\Message;

/**
 * Recurring marker message attached to the scheduler (see App\Schedule).
 * Carries no data — its handler just triggers a due-checks sweep.
 */
class DispatchChecksMessage
{
}
