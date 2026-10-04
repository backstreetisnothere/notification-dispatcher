<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Notification\NotificationStatusStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class DoctrineNotificationStatusStore implements NotificationStatusStore
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function markProviderAccepted(
        string $notificationId,
        DateTimeImmutable $now,
    ): void {
        $utc = new DateTimeZone('UTC');

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE notifications
                SET
                    status = 'provider_accepted',
                    updated_at = :updated_at
                WHERE id = :id
                SQL,
            [
                'id' => $notificationId,
                'updated_at' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        );
    }
}
