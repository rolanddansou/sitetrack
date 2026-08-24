<?php

namespace App\Infrastructure\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Monitore les performances de chaque requête HTTP.
 *
 * Logue automatiquement les requêtes lentes, memory leaks,
 * et queries excessives pour identifier les bottlenecks
 * en production sur hébergement partagé.
 */
class PerformanceSubscriber implements EventSubscriberInterface
{
    private float $startTime = 0.0;
    private int $startMemory = 0;

    public function __construct(
        private readonly LoggerInterface $performanceLogger,
        private readonly QueryCounter $queryCounter,
        private readonly Security $security,
        private readonly bool $debug = false
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    /**
     * Capturé au début de chaque requête.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
        $this->queryCounter->reset();
    }

    /**
     * Capturé à la fin de chaque requête.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->startTime === 0.0) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Calcul des métriques
        $duration = microtime(true) - $this->startTime;
        $memoryPeak = memory_get_peak_usage(true);
        $memoryUsed = max(0, $memoryPeak - $this->startMemory);
        $memoryPeakMB = round($memoryPeak / 1024 / 1024, 2);
        $memoryUsedMB = round($memoryUsed / 1024 / 1024, 2);

        $queries = $this->queryCounter->count();

        // Contexte de log
        $context = [
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'route' => $request->attributes->get('_route'),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration * 1000, 2),
            'memory_peak_mb' => $memoryPeakMB,
            'memory_used_mb' => $memoryUsedMB,
            'queries' => $queries,
        ];

        // Ajouter l'utilisateur pour le debugging
        try {
            $user = $this->security->getUser();
            if ($user) {
                $context['user'] = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : (string) $user;
            }
        } catch (\Throwable $e) {
            // Accès sécu impossible (ex: trop tôt ou erreur sécu)
        }

        // Seuils d'alerte
        $isSlowRequest = $duration > 1.5;  // Augmenté un peu le seuil
        $isMemoryIntensive = $memoryPeakMB > 128;
        $hasExcessiveQueries = $queries > 30;

        if ($isSlowRequest || $isMemoryIntensive || $hasExcessiveQueries) {
            $warnings = [];
            if ($isSlowRequest)
                $warnings[] = sprintf('SLOW (%.2fs)', $duration);
            if ($isMemoryIntensive)
                $warnings[] = sprintf('MEM (%sMB)', $memoryPeakMB);
            if ($hasExcessiveQueries)
                $warnings[] = sprintf('SQL (%d)', $queries);

            $this->performanceLogger->warning(
                'Performance issue: ' . implode(' | ', $warnings),
                $context
            );
        } elseif ($this->debug) {
            $this->performanceLogger->info('Request completed', $context);
        }

        if ($this->debug) {
            $response->headers->set('X-Performance-Duration', (string) round($duration * 1000, 2) . 'ms');
            $response->headers->set('X-Performance-Queries', (string) $queries);
        }
    }
}
