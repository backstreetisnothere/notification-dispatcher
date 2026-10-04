<?php

declare(strict_types=1);

namespace App\Domain\Provider;

final readonly class NotificationDelivery
{
    /** @param array<string, mixed> $variables */
    public function __construct(
        public string $eventId,
        public string $notificationId,
        public string $channel,
        public string $recipient,
        public string $template,
        public array $variables,
        public int $deliveryAttempt,
    ) {
    }
}
