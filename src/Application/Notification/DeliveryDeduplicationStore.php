<?php

declare(strict_types=1);

namespace App\Application\Notification;

interface DeliveryDeduplicationStore
{
    public function isDelivered(string $eventId, string $channel): bool;

    public function acquire(
        string $eventId,
        string $channel,
        string $token,
        int $lockTtlMilliseconds,
    ): bool;

    public function markDelivered(
        string $eventId,
        string $channel,
        string $token,
        int $deliveredTtlSeconds,
    ): bool;

    public function release(
        string $eventId,
        string $channel,
        string $token,
    ): void;
}
