<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Monitoring;

use App\Domain\Entity\Identity;
use App\Domain\Entity\UserCredentials;
use App\Infrastructure\Monitoring\ErrorMonitoringSubscriber;
use App\Infrastructure\Security\IdentityUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ErrorMonitoringSubscriberTest extends TestCase
{
    public function testAttachesTheAuthenticatedIdentitysIdAndEmailToTheLogContext(): void
    {
        $identity = (new Identity('user@example.test'))->setId(42);
        $user = new IdentityUser($identity, new UserCredentials(42, 'hash'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool => $context['user_id'] === 42 && $context['user_email'] === 'user@example.test')
            );

        $subscriber = new ErrorMonitoringSubscriber($logger, $logger, $logger, $security, 'test');
        $subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('boom')));
    }

    public function testDoesNotAttachUserContextWhenAnonymous(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool => !array_key_exists('user_id', $context))
            );

        $subscriber = new ErrorMonitoringSubscriber($logger, $logger, $logger, $security, 'test');
        $subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('boom')));
    }

    private function exceptionEvent(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/anything'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );
    }
}
