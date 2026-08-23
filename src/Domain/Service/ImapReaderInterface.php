<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\DTO\InboundEmailDto;

interface ImapReaderInterface
{
    /**
     * Fetch all unread test emails, parse tokens, delete them from server, and return DTOs.
     *
     * @return InboundEmailDto[]
     */
    public function fetchAndCleanTestEmails(): array;
}
