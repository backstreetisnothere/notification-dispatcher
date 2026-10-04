<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Provider\NotificationProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NotificationProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $mode,
        private string $emailUrl,
        private string $smsUrl,
        private string $pushUrl,
        private string $apiKey,
        private float $timeoutSeconds,
    ) {
    }

    public function create(): NotificationProvider
    {
        return match (strtolower(trim($this->mode))) {
            'log' => new LogNotificationProvider($this->logger),
            'http' => new HttpNotificationProvider(
                httpClient: $this->httpClient,
                endpoints: [
                    'email' => trim($this->emailUrl),
                    'sms' => trim($this->smsUrl),
                    'push' => trim($this->pushUrl),
                ],
                apiKey: trim($this->apiKey) === '' ? null : trim($this->apiKey),
                timeoutSeconds: $this->timeoutSeconds,
            ),
            default => throw new \InvalidArgumentException(
                sprintf('Unsupported PROVIDER_MODE "%s".', $this->mode),
            ),
        };
    }
}
