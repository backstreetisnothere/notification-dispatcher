<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Notification\DeliveryDeduplicationStore;
use App\Application\Notification\DeduplicationInProgressException;
use App\Application\Notification\NotificationStatusStore;
use App\Domain\Provider\NotificationDelivery;
use App\Domain\Provider\NotificationProvider;
use App\Shared\Clock;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

#[AsMessageHandler]
final readonly class NotificationRequestedHandler
{
    public function __construct(
        private NotificationProvider $provider,
        private DeliveryDeduplicationStore $deduplication,
        private NotificationStatusStore $statusStore,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $lockTtlMilliseconds,
        private int $deliveredTtlSeconds,
    ) {
    }

    public function __invoke(
        NotificationRequested $message,
        Envelope $envelope,
    ): void {
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        $deliveryAttempt = ($redeliveryStamp?->getRetryCount() ?? 0) + 1;

        foreach ($message->destinations as $channel => $recipient) {
            $token = bin2hex(random_bytes(16));

            if ($this->deduplication->isDelivered($message->eventId, $channel)) {
                $this->logger->info('Notification delivery skipped as already completed.', [
                    'eventId' => $message->eventId,
                    'channel' => $channel,
                ]);

                continue;
            }

            if (
                !$this->deduplication->acquire(
                    eventId: $message->eventId,
                    channel: $channel,
                    token: $token,
                    lockTtlMilliseconds: $this->lockTtlMilliseconds,
                )
            ) {
                throw DeduplicationInProgressException::forDelivery(
                    $message->eventId,
                    $channel,
                );
            }

            try {
                if ($this->deduplication->isDelivered($message->eventId, $channel)) {
                    continue;
                }

                $this->provider->send(
                    new NotificationDelivery(
                        eventId: $message->eventId,
                        notificationId: $message->notificationId,
                        channel: $channel,
                        recipient: $recipient,
                        template: $message->template,
                        variables: $message->variables,
                        deliveryAttempt: $deliveryAttempt,
                    ),
                );

                if (
                    !$this->deduplication->markDelivered(
                        eventId: $message->eventId,
                        channel: $channel,
                        token: $token,
                        deliveredTtlSeconds: $this->deliveredTtlSeconds,
                    )
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Deduplication lock was lost for event "%s" and channel "%s".',
                            $message->eventId,
                            $channel,
                        ),
                    );
                }
            } finally {
                try {
                    $this->deduplication->release(
                        eventId: $message->eventId,
                        channel: $channel,
                        token: $token,
                    );
                } catch (\Throwable $exception) {
                    $this->logger->warning('Failed to release deduplication lock.', [
                        'eventId' => $message->eventId,
                        'channel' => $channel,
                        'exception' => $exception,
                    ]);
                }
            }
        }

        $this->statusStore->markProviderAccepted(
            notificationId: $message->notificationId,
            now: $this->clock->now(),
        );
    }
}
