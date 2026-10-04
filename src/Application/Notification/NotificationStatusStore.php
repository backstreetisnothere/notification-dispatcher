<?php

declare(strict_types=1);

namespace App\Application\Notification;

use DateTimeImmutable;

interface NotificationStatusStore
{
    public function markProviderAccepted(
        string $notificationId,
        DateTimeImmutable $now,
    ): void;
}
