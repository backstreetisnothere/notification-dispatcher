<?php

declare(strict_types=1);

namespace App\Domain\Notification\Event;

use App\Outbox\Entity\Outbox;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class OutboxNotificationCreatedListener implements DomainEventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function subscribedEvents(): array
    {
        return [NotificationCreated::class];
    }

    public function handle(object $event): void
    {
        if (!$event instanceof NotificationCreated) {
            throw new InvalidArgumentException(
                sprintf('Unsupported domain event "%s".', $event::class),
            );
        }

        $outbox = new Outbox(
            id: $event->eventId,
            aggregateType: 'notification',
            aggregateId: $event->notificationId,
            eventType: NotificationCreated::NAME,
            payload: [
                'notificationId' => $event->notificationId,
                'destinations' => $event->destinations,
                'template' => $event->template,
                'variables' => $event->variables,
            ],
            occurredAt: $event->occurredAt,
        );

        $this->entityManager->persist($outbox);
    }
}
