<?php

declare(strict_types=1);

namespace App\Infrastructure\HttpClient;

use App\Domain\DTO\MonitorDto;
use App\Domain\Entity\CheckResult;
use App\Domain\Service\PingServiceInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PingService implements PingServiceInterface
{
    public function __construct(private HttpClientInterface $httpClient) {}

    public function ping(MonitorDto $monitor): CheckResult
    {
        $startTime = microtime(true);
        $status = 'down';
        $errorMessage = null;

        try {
            $response = $this->httpClient->request('GET', $monitor->target, [
                'timeout' => 10,
                'max_redirects' => 5,
            ]);

            $statusCode = $response->getStatusCode();
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $expectedStatus = $monitor->expectedStatus ?? 200;
            if ($statusCode === $expectedStatus) {
                $status = 'up';

                if ($monitor->regexCheck !== null && $monitor->regexCheck !== '') {
                    $content = $response->getContent(false);
                    // Match pattern
                    if (@preg_match($monitor->regexCheck, $content) !== 1) {
                        $status = 'down';
                        $errorMessage = sprintf("Regex pattern '%s' not found in response body.", $monitor->regexCheck);
                    }
                }
            } else {
                $errorMessage = sprintf("Expected status %d, got %d.", $expectedStatus, $statusCode);
            }
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $status = 'down';
            $errorMessage = $e->getMessage();
        }

        return new CheckResult(
            $monitor->id ?? 0,
            $status,
            $responseTimeMs,
            new \DateTimeImmutable(),
            $errorMessage
        );
    }
}
