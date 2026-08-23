<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;
use App\Domain\Service\NotificationSenderInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NotificationSender implements NotificationSenderInterface
{
    private string $senderEmail;

    public function __construct(
        private MailerInterface $mailer,
        private HttpClientInterface $httpClient,
        string $senderEmail = 'noreply@sitetrack.io'
    ) {
        $this->senderEmail = $senderEmail;
    }

    public function sendAlert(AlertRuleDto $rule, MonitorDto $monitor, string $message): void
    {
        $this->dispatch($rule, sprintf("[SiteTrack] ALERT: %s", $monitor->name), $message);
    }

    public function sendRecovery(AlertRuleDto $rule, MonitorDto $monitor, string $message): void
    {
        $this->dispatch($rule, sprintf("[SiteTrack] RECOVERED: %s", $monitor->name), $message);
    }

    private function dispatch(AlertRuleDto $rule, string $subject, string $message): void
    {
        if ($rule->channel === 'email') {
            try {
                $email = (new Email())
                    ->from($this->senderEmail)
                    ->to($rule->recipient)
                    ->subject($subject)
                    ->text($message);
                $this->mailer->send($email);
            } catch (\Throwable) {
                // Fail silently to prevent worker lockup
            }
        } elseif ($rule->channel === 'slack') {
            try {
                $this->httpClient->request('POST', $rule->recipient, [
                    'json' => ['text' => sprintf("*%s*\n%s", $subject, $message)],
                ]);
            } catch (\Throwable) {
                // Fail silently
            }
        } elseif ($rule->channel === 'telegram') {
            $parts = explode(':', $rule->recipient, 2);
            if (count($parts) === 2) {
                $botToken = $parts[0];
                $chatId = $parts[1];
                $url = sprintf("https://api.telegram.org/bot%s/sendMessage", $botToken);
                try {
                    $this->httpClient->request('POST', $url, [
                        'json' => [
                            'chat_id' => $chatId,
                            'text' => sprintf("%s\n\n%s", $subject, $message),
                        ],
                    ]);
                } catch (\Throwable) {
                    // Fail silently
                }
            }
        }
    }
}
