<?php

declare(strict_types=1);

namespace App\Domain\Notification\Event;

use DateTimeImmutable;

final readonly class NotificationCreated
{
    public const string NAME = 'notification.created';

    /**
     * @param array<string, string> $destinations
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public string $eventId,
        public string $notificationId,
        public array $destinations,
        public string $template,
        public array $variables,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
