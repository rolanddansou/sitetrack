<?php

declare(strict_types=1);

namespace App\Infrastructure\Mailer;

use App\Domain\DTO\MonitorDto;
use App\Domain\Service\PasswordEncryptorInterface;
use App\Domain\Service\SmtpTesterInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class SmtpTester implements SmtpTesterInterface
{
    private string $testEmailRecipient;

    public function __construct(
        private PasswordEncryptorInterface $passwordEncryptor,
        string $testEmailRecipient = 'verify@sitetrack.io'
    ) {
        $this->testEmailRecipient = $testEmailRecipient;
    }

    public function sendTestMail(MonitorDto $monitor, string $token): void
    {
        $parts = explode(':', $monitor->target);
        $host = $parts[0];
        $port = isset($parts[1]) ? (int) $parts[1] : 587;

        $scheme = $monitor->smtpSecure === 'ssl' ? 'smtps' : 'smtp';
        $password = $this->passwordEncryptor->decrypt($monitor->smtpPasswordEncrypted ?? '');

        // Construct DSN dynamically
        $dsn = sprintf('%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($monitor->smtpUsername ?? ''),
            rawurlencode($password),
            $host,
            $port
        );

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        // Build the validation email
        $email = (new Email())
            ->from($monitor->smtpUsername ?? 'test@sitetrack.io')
            ->to($this->testEmailRecipient)
            ->subject(sprintf('SiteTrack Deliverability Check [%s]', $token))
            ->text(sprintf("This is a SiteTrack automated deliverability check email.\nToken: %s\nSent at: %s", $token, date('Y-m-d H:i:s')))
            ->html(sprintf("<p>This is a SiteTrack automated deliverability check email.</p><p>Token: <strong>%s</strong></p>", $token));

        // Inject the tracking token header
        $email->getHeaders()->addTextHeader('X-SiteTrack-Token', $token);

        $mailer->send($email);
    }
}
