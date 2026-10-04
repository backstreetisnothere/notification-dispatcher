<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Outbox\OutboxPublisher;
use App\Domain\Notification\Event\NotificationCreated;
use App\Infrastructure\Messenger\NotificationRequested;
use App\Outbox\OutboxMessage;
use App\Outbox\OutboxStore;
use App\Outbox\RetryStrategy;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OutboxPublisherTest extends TestCase
{
    public function testItPublishesClaimedEventAndMarksItPublished(): void
    {
        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $message = $this->outboxMessage($now);

        $store = $this->createMock(OutboxStore::class);
        $store
            ->expects(self::once())
            ->method('claimBatch')
            ->with(
                $now,
                10,
                $now->modify('+30 seconds'),
                3,
            )
            ->willReturn([$message]);

        $store
            ->expects(self::once())
            ->method('markPublished')
            ->with($message->id, $message->claimToken, $now)
            ->willReturn(true);

        $store
            ->expects(self::never())
            ->method('markFailed');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(NotificationRequested::class))
            ->willReturnCallback(
                static fn (NotificationRequested $message): Envelope => new Envelope($message),
            );

        $publisher = $this->publisher($store, $messageBus, $now);

        self::assertSame(1, $publisher->publishBatch(10));
    }

    public function testItSchedulesExponentialRetryAfterPublicationFailure(): void
    {
        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $message = $this->outboxMessage($now, 1);

        $store = $this->createMock(OutboxStore::class);
        $store
            ->expects(self::once())
            ->method('claimBatch')
            ->willReturn([$message]);

        $store
            ->expects(self::never())
            ->method('markPublished');

        $store
            ->expects(self::once())
            ->method('markFailed')
            ->with(
                $message->id,
                $message->claimToken,
                'RabbitMQ unavailable.',
                $now->modify('+1 second'),
                $now,
            )
            ->willReturn(true);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new RuntimeException('RabbitMQ unavailable.'));

        $publisher = $this->publisher($store, $messageBus, $now);

        self::assertSame(1, $publisher->publishBatch(10));
    }

    public function testItMovesEventToDeadStateAfterMaximumAttempts(): void
    {
        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $message = $this->outboxMessage($now, 3);

        $store = $this->createMock(OutboxStore::class);
        $store
            ->expects(self::once())
            ->method('claimBatch')
            ->willReturn([$message]);

        $store
            ->expects(self::once())
            ->method('markFailed')
            ->with(
                $message->id,
                $message->claimToken,
                'RabbitMQ unavailable.',
                null,
                $now,
            )
            ->willReturn(true);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new RuntimeException('RabbitMQ unavailable.'));

        $publisher = $this->publisher($store, $messageBus, $now);

        self::assertSame(1, $publisher->publishBatch(10));
    }

    private function publisher(
        OutboxStore $store,
        MessageBusInterface $messageBus,
        DateTimeImmutable $now,
    ): OutboxPublisher {
        return new OutboxPublisher(
            store: $store,
            messageBus: $messageBus,
            retryStrategy: new RetryStrategy(
                baseDelayMilliseconds: 1000,
                maxDelayMilliseconds: 60000,
                multiplier: 2.0,
                jitterRatio: 0.0,
            ),
            clock: new FrozenClock($now),
            logger: new NullLogger(),
            leaseSeconds: 30,
            maxAttempts: 3,
        );
    }

    private function outboxMessage(
        DateTimeImmutable $now,
        int $attempt = 1,
    ): OutboxMessage {
        return new OutboxMessage(
            id: '019430bd-7a10-7000-8000-000000000001',
            eventType: NotificationCreated::NAME,
            payload: [
                'notificationId' => '019430bd-7a10-7000-8000-000000000002',
                'destinations' => [
                    'email' => 'john.doe@example.test',
                ],
                'template' => 'welcome',
                'variables' => [
                    'firstName' => 'John',
                ],
            ],
            occurredAt: $now,
            attempt: $attempt,
            claimToken: '019430bd-7a10-7000-8000-000000000003',
        );
    }
}
