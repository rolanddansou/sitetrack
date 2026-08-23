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
        private RateLimiterFactory $analyticsCollectLimiter
    ) {}

    #[Route('/api/event', name: 'analytics_api_event', methods: ['POST'])]
    public function recordEvent(Request $request, MessageBusInterface $messageBus): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $siteId = $data['site_id'] ?? null;
        $path = $data['path'] ?? null;
        if ($siteId === null || $path === null) {
            $response = new JsonResponse(['error' => 'Missing site_id or path'], Response::HTTP_BAD_REQUEST);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            return $response;
        }

        $limit = $this->analyticsCollectLimiter->create((string) $siteId)->consume();
        if (!$limit->isAccepted()) {
            $response = new JsonResponse(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Retry-After', (string) ($limit->getRetryAfter()->getTimestamp() - time()));
            return $response;
        }

        $referrer = $data['referrer'] ?? null;

        $ip = $request->getClientIp() ?? '127.0.0.1';
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
            utmSource: $data['utm_source'] ?? null,
            utmMedium: $data['utm_medium'] ?? null,
            utmCampaign: $data['utm_campaign'] ?? null,
            device: $ua['device'],
            browser: $ua['browser'],
            browserVersion: $ua['browserVersion'],
            os: $ua['os'],
            osVersion: $ua['osVersion'],
            eventType: $data['event_type'] ?? 'pageview',
            eventName: $data['event_name'] ?? null,
            eventProps: is_array($eventProps) ? $eventProps : null
        );

        $messageBus->dispatch(new AnalyticsEventMessage($dto));

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }
}
