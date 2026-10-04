<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Provider\NotificationDelivery;
use App\Domain\Provider\NotificationProvider;
use App\Domain\Provider\ProviderException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpNotificationProvider implements NotificationProvider
{
    /**
     * @param array<string, string> $endpoints
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private array $endpoints,
        private ?string $apiKey,
        private float $timeoutSeconds,
    ) {
    }

    public function name(): string
    {
        return 'http';
    }

    public function send(NotificationDelivery $delivery): void
    {
        $url = $this->endpoints[$delivery->channel] ?? '';

        if ($url === '') {
            throw new ProviderException(
                sprintf('Endpoint for channel "%s" is not configured.', $delivery->channel),
            );
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Idempotency-Key' => sprintf(
                '%s:%s',
                $delivery->eventId,
                $delivery->channel,
            ),
        ];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = sprintf('Bearer %s', $this->apiKey);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $url,
                [
                    'headers' => $headers,
                    'json' => [
                        'externalId' => $delivery->notificationId,
                        'eventId' => $delivery->eventId,
                        'channel' => $delivery->channel,
                        'recipient' => $delivery->recipient,
                        'template' => $delivery->template,
                        'variables' => $delivery->variables,
                    ],
                    'timeout' => $this->timeoutSeconds,
                    'max_duration' => $this->timeoutSeconds + 1,
                ],
            );

            $statusCode = $response->getStatusCode();
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProviderException(
                sprintf(
                    'Provider transport failed for channel "%s".',
                    $delivery->channel,
                ),
                previous: $exception,
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ProviderException(
                sprintf(
                    'Provider returned HTTP %d for channel "%s".',
                    $statusCode,
                    $delivery->channel,
                ),
            );
        }
    }
}
