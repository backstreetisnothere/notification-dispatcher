<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Notification\NotificationStatusStore;
use DateTimeImmutable;

final class InMemoryNotificationStatusStore implements NotificationStatusStore
{
    public int $calls = 0;

    public function markProviderAccepted(
        string $notificationId,
        DateTimeImmutable $now,
    ): void {
        $this->calls++;
    }
}
