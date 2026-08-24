<?php

namespace App\Infrastructure\Monitoring;

use App\Infrastructure\Security\IdentityUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Subscriber pour monitorer et logger les erreurs avec contexte enrichi.
 * Catégorise les erreurs selon leur criticité et leur type.
 */
final readonly class ErrorMonitoringSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private LoggerInterface $securityLogger,
        private LoggerInterface $businessLogger,
        private Security $security,
        private string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Contexte enrichi pour tous les logs
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'url' => $request->getUri(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'referer' => $request->headers->get('referer'),
        ];

        // Ajouter l'utilisateur si authentifié
        try {
            $user = $this->security->getUser();
            if ($user instanceof IdentityUser) {
                $context['user_id'] = $user->getIdentity()->getId();
                $context['user_email'] = $user->getUserIdentifier();
            }
        } catch (\Throwable $e) {
            // Silently fail if security is not available
        }

        // Ajouter le corps de la requête pour les erreurs API
        if ($request->getContentTypeFormat() === 'json' && $request->getContent()) {
            $context['request_body'] = $request->getContent();
        }

        // Catégoriser et logger selon le type d'exception
        $this->categorizeAndLog($exception, $context);
    }

    private function categorizeAndLog(\Throwable $exception, array $context): void
    {
        // Erreurs de sécurité (authentification, autorisation)
        if ($exception instanceof AuthenticationException || $exception instanceof AccessDeniedException) {
            $this->securityLogger->warning('Security exception occurred', $context);
            return;
        }

        // Erreurs HTTP standards (404, 405, etc.)
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $context['status_code'] = $statusCode;

            // 404, 405, 410 ne sont pas critiques
            if (in_array($statusCode, [404, 405, 410], true)) {
                $this->logger->info('HTTP exception occurred', $context);
                return;
            }

            // 4xx = erreur client (warning)
            if ($statusCode >= 400 && $statusCode < 500) {
                $this->logger->warning('Client error occurred', $context);
                return;
            }

            // 5xx = erreur serveur (error)
            if ($statusCode >= 500) {
                $this->logger->error('Server error occurred', $context);
                return;
            }
        }

        // Erreurs business/métier (vos exceptions custom)
        if ($this->isBusinessException($exception)) {
            $this->businessLogger->error('Business logic exception occurred', $context);
            return;
        }

        // Erreurs base de données (CRITICAL)
        if ($this->isDatabaseException($exception)) {
            $context['critical_type'] = 'database';
            $this->logger->critical('Database exception occurred', $context);
            return;
        }

        // Autres erreurs non catégorisées = CRITICAL
        $this->logger->critical('Unhandled exception occurred', $context);
    }

    /**
     * Vérifie si l'exception est une erreur métier (vos exceptions custom)
     */
    private function isBusinessException(\Throwable $exception): bool
    {
        $exceptionClass = get_class($exception);

        // Vos namespaces d'exceptions métier
        return str_contains($exceptionClass, 'App\\Feature\\')
            && str_contains($exceptionClass, '\\Exception\\');
    }

    /**
     * Vérifie si l'exception est liée à la base de données
     */
    private function isDatabaseException(\Throwable $exception): bool
    {
        $exceptionClass = get_class($exception);

        return str_contains($exceptionClass, 'Doctrine\\DBAL')
            || str_contains($exceptionClass, 'Doctrine\\ORM')
            || str_contains($exceptionClass, 'PDOException')
            || str_contains($exception->getMessage(), 'database')
            || str_contains($exception->getMessage(), 'connection');
    }
}

