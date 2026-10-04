<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

final readonly class NotificationRequested
{
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
        public string $occurredAt,
        public int $publishedAttempt,
    ) {
    }
}
