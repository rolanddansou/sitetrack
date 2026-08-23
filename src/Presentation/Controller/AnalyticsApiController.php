<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\DTO\AnalyticsEventDto;
use App\Domain\Service\GeoIpResolverInterface;
use App\Domain\Service\UserAgentParserInterface;
use App\Infrastructure\Queue\Message\AnalyticsEventMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class AnalyticsApiController extends AbstractController
{
    public function __construct(
        private GeoIpResolverInterface $geoIpResolver,
        private UserAgentParserInterface $userAgentParser,
        private RateLimiterFactory $analyticsCollectLimiter,
        private RateLimiterFactory $analyticsCollectIpLimiter
    ) {}

    #[Route('/api/event', name: 'analytics_api_event', methods: ['POST'])]
    public function recordEvent(Request $request, MessageBusInterface $messageBus): Response
    {
        $decoded = json_decode($request->getContent(), true);
        $data = is_array($decoded) ? $decoded : [];

        $siteId = $this->stringField($data, 'site_id', 100);
        $path = $this->stringField($data, 'path', 255);
        if ($siteId === null || $path === null) {
            return $this->corsResponse(new JsonResponse(['error' => 'Missing site_id or path'], Response::HTTP_BAD_REQUEST));
        }

        $ip = $request->getClientIp() ?? '127.0.0.1';

        // Two independent limiters: per-site protects one site's bucket from
        // being singled out, per-IP stops a client from bypassing that by
        // simply sending a different site_id on every request.
        foreach ([$this->analyticsCollectLimiter->create($siteId), $this->analyticsCollectIpLimiter->create($ip)] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                $response = $this->corsResponse(new JsonResponse(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS));
                $response->headers->set('Retry-After', (string) max(0, $limit->getRetryAfter()->getTimestamp() - time()));
                return $response;
            }
        }

        $referrer = $this->stringField($data, 'referrer', 255);

        $geo = $this->geoIpResolver->resolve($ip);
        $country = $request->headers->get('CF-IPCountry') ?? $request->headers->get('X-Country-Code') ?? $geo['country'];

        $ua = $this->userAgentParser->parse($request->headers->get('User-Agent'));

        // Generate GDPR-compliant cookie-less session id (daily rotation)
        $today = date('Y-m-d');
        $sessionId = hash('sha256', $ip . ($request->headers->get('User-Agent') ?? '') . $siteId . $today);

        $eventProps = $data['props'] ?? null;

        $dto = new AnalyticsEventDto(
            siteId: $siteId,
            path: $path,
            referrer: $referrer,
            country: $country,
            sessionId: $sessionId,
            occurredAt: new \DateTimeImmutable(),
            region: $geo['region'],
            city: $geo['city'],
            utmSource: $this->stringField($data, 'utm_source', 255),
            utmMedium: $this->stringField($data, 'utm_medium', 255),
            utmCampaign: $this->stringField($data, 'utm_campaign', 255),
            device: $ua['device'],
            browser: $ua['browser'],
            browserVersion: $ua['browserVersion'],
            os: $ua['os'],
            osVersion: $ua['osVersion'],
            eventType: $this->stringField($data, 'event_type', 20) ?? 'pageview',
            eventName: $this->stringField($data, 'event_name', 255),
            eventProps: is_array($eventProps) ? $eventProps : null
        );

        $messageBus->dispatch(new AnalyticsEventMessage($dto));

        return $this->corsResponse(new Response('', Response::HTTP_NO_CONTENT));
    }

    private function stringField(array $data, string $key, int $maxLength): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function corsResponse(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }
}
