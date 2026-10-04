<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Notification\DeduplicationInProgressException;
use App\Domain\Provider\NotificationProvider;
use App\Infrastructure\Messenger\NotificationRequested;
use App\Infrastructure\Messenger\NotificationRequestedHandler;
use App\Infrastructure\Provider\CircuitBreakingNotificationProvider;
use App\Infrastructure\Provider\HttpNotificationProvider;
use App\Infrastructure\Redis\RedisCircuitStateStore;
use App\Infrastructure\Redis\RedisDeliveryDeduplicationStore;
use App\Resilience\CircuitBreaker\CircuitBreaker;
use App\Resilience\CircuitBreaker\CircuitOpenException;
use App\Resilience\CircuitBreaker\CircuitState;
use App\Shared\RedisFactory;
use App\Shared\SystemClock;
use App\Tests\Support\InMemoryNotificationStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;

final class ProviderFailureIntegrationTest extends TestCase
{
    public function testFailedProviderOpensCircuitBeforeRabbitMqRetryExhaustion(): void
    {
        $redis = (new RedisFactory())->create(
            getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379/15',
        );

        $prefix = sprintf(
            'notification-outbox:test:%s',
            bin2hex(random_bytes(8)),
        );

        $circuitStore = new RedisCircuitStateStore($redis, $prefix);
        $deduplicationStore = new RedisDeliveryDeduplicationStore(
            redis: $redis,
            prefix: $prefix,
        );

        $clock = new SystemClock();
        $circuitBreaker = new CircuitBreaker(
            store: $circuitStore,
            clock: $clock,
            failureThreshold: 2,
            recoverySeconds: 60,
            probeLockMilliseconds: 5000,
        );

        $httpCalls = 0;
        $httpClient = new MockHttpClient(
            static function (
                string $method,
                string $url,
                array $options,
            ) use (&$httpCalls): MockResponse {
                $httpCalls++;

                self::assertSame('POST', $method);
                self::assertSame('https://email-provider.test/send', $url);
                self::assertArrayHasKey('Idempotency-Key', $options['headers']);

                return new MockResponse(
                    '{"error":"provider unavailable"}',
                    [
                        'http_code' => 503,
                        'response_headers' => [
                            'content-type' => 'application/json',
                        ],
                    ],
                );
            },
        );

        $innerProvider = new HttpNotificationProvider(
            httpClient: $httpClient,
            endpoints: [
                'email' => 'https://email-provider.test/send',
            ],
            apiKey: 'synthetic-api-key',
            timeoutSeconds: 1.0,
        );

        self::assertInstanceOf(NotificationProvider::class, $innerProvider);

        $provider = new CircuitBreakingNotificationProvider(
            inner: $innerProvider,
            circuitBreaker: $circuitBreaker,
        );

        $statusStore = new InMemoryNotificationStatusStore();

        $handler = new NotificationRequestedHandler(
            provider: $provider,
            deduplication: $deduplicationStore,
            statusStore: $statusStore,
            clock: $clock,
            logger: new NullLogger(),
            lockTtlMilliseconds: 10000,
            deliveredTtlSeconds: 3600,
        );

        $message = new NotificationRequested(
            eventId: '019430bd-7a10-7000-8000-000000000010',
            notificationId: '019430bd-7a10-7000-8000-000000000011',
            destinations: [
                'email' => 'john.doe@example.test',
            ],
            template: 'welcome',
            variables: [
                'firstName' => 'John',
            ],
            occurredAt: '2025-01-01T00:00:00+00:00',
            publishedAttempt: 1,
        );

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $handler($message, new Envelope($message));
                self::fail('Provider failure was not propagated to Messenger.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('HTTP 503', $exception->getMessage());
            }
        }

        self::assertSame(2, $httpCalls);
        self::assertSame(
            CircuitState::Open,
            $circuitBreaker->state('http:email'),
        );
        self::assertFalse(
            $deduplicationStore->isDelivered(
                $message->eventId,
                'email',
            ),
        );
        self::assertSame(0, $statusStore->calls);

        try {
            $handler($message, new Envelope($message));
            self::fail('Open circuit did not stop the third HTTP request.');
        } catch (CircuitOpenException $exception) {
            self::assertStringContainsString('http:email', $exception->getMessage());
        }

        self::assertSame(2, $httpCalls);
        self::assertSame(0, $statusStore->calls);
    }
}
