<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Domain\Notification\Event\NotificationCreated;
use App\Infrastructure\Messenger\NotificationRequested;
use App\Outbox\OutboxMessage;
use App\Outbox\OutboxStore;
use App\Outbox\RetryStrategy;
use App\Shared\Clock;
use DateInterval;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use UnexpectedValueException;

final readonly class OutboxPublisher
{
    public function __construct(
        private OutboxStore $store,
        private MessageBusInterface $messageBus,
        private RetryStrategy $retryStrategy,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $leaseSeconds,
        private int $maxAttempts,
    ) {
        if ($leaseSeconds < 1 || $maxAttempts < 1) {
            throw new \InvalidArgumentException('Invalid Outbox publisher configuration.');
        }
    }

    public function publishBatch(int $limit): int
    {
        $now = $this->clock->now();
        $messages = $this->store->claimBatch(
            now: $now,
            limit: $limit,
            leaseUntil: $now->add(new DateInterval(sprintf('PT%dS', $this->leaseSeconds))),
            maxAttempts: $this->maxAttempts,
        );

        foreach ($messages as $message) {
            $this->publish($message);
        }

        return count($messages);
    }

    private function publish(OutboxMessage $outboxMessage): void
    {
        try {
            $this->messageBus->dispatch(
                $this->toMessengerMessage($outboxMessage),
            );

            if (
                !$this->store->markPublished(
                    id: $outboxMessage->id,
                    claimToken: $outboxMessage->claimToken,
                    now: $this->clock->now(),
                )
            ) {
                $this->logger->warning('Outbox claim was lost after publishing.', [
                    'outboxId' => $outboxMessage->id,
                    'claimToken' => $outboxMessage->claimToken,
                ]);
            }
        } catch (Throwable $exception) {
            $now = $this->clock->now();
            $nextAttemptAt = $outboxMessage->attempt >= $this->maxAttempts
                ? null
                : $this->retryStrategy->nextAttemptAt($now, $outboxMessage->attempt);

            $updated = $this->store->markFailed(
                id: $outboxMessage->id,
                claimToken: $outboxMessage->claimToken,
                error: $exception->getMessage(),
                nextAttemptAt: $nextAttemptAt,
                now: $now,
            );

            $this->logger->error('Outbox event publication failed.', [
                'outboxId' => $outboxMessage->id,
                'eventType' => $outboxMessage->eventType,
                'attempt' => $outboxMessage->attempt,
                'maxAttempts' => $this->maxAttempts,
                'nextAttemptAt' => $nextAttemptAt?->format(DATE_ATOM),
                'claimUpdated' => $updated,
                'exception' => $exception,
            ]);
        }
    }

    private function toMessengerMessage(OutboxMessage $outboxMessage): NotificationRequested
    {
        if ($outboxMessage->eventType !== NotificationCreated::NAME) {
            throw new UnexpectedValueException(
                sprintf('Unsupported Outbox event type "%s".', $outboxMessage->eventType),
            );
        }

        /** @var string $notificationId */
        $notificationId = $outboxMessage->payload['notificationId'] ?? null;

        /** @var array<string, string> $destinations */
        $destinations = $outboxMessage->payload['destinations'] ?? null;

        /** @var string $template */
        $template = $outboxMessage->payload['template'] ?? null;

        /** @var array<string, mixed> $variables */
        $variables = $outboxMessage->payload['variables'] ?? null;

        if (
            $notificationId === null
            || $destinations === null
            || $template === null
            || $variables === null
        ) {
            throw new UnexpectedValueException(
                sprintf('Malformed payload for Outbox event "%s".', $outboxMessage->id),
            );
        }

        return new NotificationRequested(
            eventId: $outboxMessage->id,
            notificationId: $notificationId,
            destinations: $destinations,
            template: $template,
            variables: $variables,
            occurredAt: $outboxMessage->occurredAt->format(DATE_ATOM),
            publishedAttempt: $outboxMessage->attempt,
        );
    }
}
