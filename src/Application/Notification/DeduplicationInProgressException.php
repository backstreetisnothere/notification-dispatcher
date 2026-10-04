<?php

declare(strict_types=1);

namespace App\Application\Notification;

use RuntimeException;

final class DeduplicationInProgressException extends RuntimeException
{
    public static function forDelivery(string $eventId, string $channel): self
    {
        return new self(
            sprintf(
                'Delivery for event "%s" and channel "%s" is already locked.',
                $eventId,
                $channel,
            ),
        );
    }
}
