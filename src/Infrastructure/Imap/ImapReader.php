<?php

declare(strict_types=1);

namespace App\Infrastructure\Imap;

use App\Domain\DTO\InboundEmailDto;
use App\Domain\Service\ImapReaderInterface;

class ImapReader implements ImapReaderInterface
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $secure;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $secure = 'ssl'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->secure = $secure;
    }

    public function fetchAndCleanTestEmails(): array
    {
        $address = ($this->secure === 'ssl' ? 'ssl://' : '') . $this->host;
        $socket = @fsockopen($address, $this->port, $errno, $errstr, 15);
        if (!$socket) {
            // Log warning or fallback silently
            return [];
        }

        $this->readLine($socket); // read greeting

        $this->writeLine($socket, "A1 LOGIN " . $this->escape($this->username) . " " . $this->escape($this->password));
        $loginRes = $this->readUntilTag($socket, "A1");
        if (!str_contains($loginRes, 'OK')) {
            fclose($socket);
            return [];
        }

        $this->writeLine($socket, "A2 SELECT INBOX");
        $selectRes = $this->readUntilTag($socket, "A2");
        if (!str_contains($selectRes, 'OK')) {
            fclose($socket);
            return [];
        }

        $this->writeLine($socket, "A3 SEARCH UNSEEN");
        $searchRes = $this->readUntilTag($socket, "A3");

        $msgIds = [];
        foreach (explode("\r\n", $searchRes) as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $ids = explode(' ', substr($line, 8));
                $msgIds = array_filter(array_map('trim', $ids));
            }
        }

        $emails = [];

        foreach ($msgIds as $id) {
            if ($id === '') {
                continue;
            }

            $this->writeLine($socket, "A4 FETCH $id (BODY[HEADER.FIELDS (SUBJECT DATE X-SITETRACK-TOKEN AUTHENTICATION-RESULTS)])");
            $fetchRes = $this->readUntilTag($socket, "A4");

            $token = null;
            $receivedAt = new \DateTimeImmutable();
            $spfPassed = null;
            $dkimPassed = null;
            $dmarcPassed = null;

            foreach (explode("\r\n", $fetchRes) as $line) {
                if (stripos($line, 'X-SiteTrack-Token:') === 0) {
                    $token = trim(substr($line, 18));
                } elseif (stripos($line, 'Subject:') === 0) {
                    $subject = trim(substr($line, 8));
                    if (preg_match('/\[([a-f0-9\-]+)\]/i', $subject, $matches)) {
                        $token = $token ?? $matches[1];
                    }
                } elseif (stripos($line, 'Date:') === 0) {
                    $dateStr = trim(substr($line, 5));
                    try {
                        $receivedAt = new \DateTimeImmutable($dateStr);
                    } catch (\Throwable) {
                        $receivedAt = new \DateTimeImmutable();
                    }
                } elseif (stripos($line, 'Authentication-Results:') === 0) {
                    $authRes = strtolower($line);
                    if (str_contains($authRes, 'spf=pass')) {
                        $spfPassed = true;
                    } elseif (str_contains($authRes, 'spf=fail')) {
                        $spfPassed = false;
                    }
                    if (str_contains($authRes, 'dkim=pass')) {
                        $dkimPassed = true;
                    } elseif (str_contains($authRes, 'dkim=fail')) {
                        $dkimPassed = false;
                    }
                    if (str_contains($authRes, 'dmarc=pass')) {
                        $dmarcPassed = true;
                    } elseif (str_contains($authRes, 'dmarc=fail')) {
                        $dmarcPassed = false;
                    }
                }
            }

            if ($token !== null) {
                $emails[] = new InboundEmailDto(
                    token: $token,
                    receivedAt: $receivedAt,
                    spfPassed: $spfPassed,
                    dkimPassed: $dkimPassed,
                    dmarcPassed: $dmarcPassed
                );

                $this->writeLine($socket, "A5 STORE $id +FLAGS (\\Deleted)");
                $this->readUntilTag($socket, "A5");
            }
        }

        $this->writeLine($socket, "A6 EXPUNGE");
        $this->readUntilTag($socket, "A6");

        $this->writeLine($socket, "A7 LOGOUT");
        $this->readUntilTag($socket, "A7");

        fclose($socket);

        return $emails;
    }

    private function readLine($socket): string
    {
        $line = fgets($socket);
        return $line === false ? '' : $line;
    }

    private function readUntilTag($socket, string $tag): string
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (str_starts_with($line, $tag . ' ')) {
                break;
            }
        }
        return $response;
    }

    private function writeLine($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
    }

    private function escape(string $string): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
    }
}
