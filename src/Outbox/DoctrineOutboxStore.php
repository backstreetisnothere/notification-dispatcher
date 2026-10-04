<?php

declare(strict_types=1);

namespace App\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class DoctrineOutboxStore implements OutboxStore
{
    private const string SELECT_CLAIMABLE = <<<'SQL'
        SELECT
            id,
            aggregate_type,
            aggregate_id,
            event_type,
            payload,
            occurred_at,
            attempts
        FROM notification_outbox
        WHERE (
            status = 'pending'
            AND available_at <= :now
            AND attempts < :max_attempts
        )
        OR (
            status = 'processing'
            AND locked_until <= :now
            AND attempts < :max_attempts
        )
        ORDER BY occurred_at ASC, id ASC
        LIMIT %d
        FOR UPDATE SKIP LOCKED
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function claimBatch(
        DateTimeImmutable $now,
        int $limit,
        DateTimeImmutable $leaseUntil,
        int $maxAttempts,
    ): array {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Outbox batch limit must be between 1 and 1000.');
        }

        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Outbox max attempts must be positive.');
        }

        $claimToken = Uuid::v7()->toRfc4122();
        $utc = new DateTimeZone('UTC');
        $query = sprintf(
            self::SELECT_CLAIMABLE,
            $limit,
        );

        $this->connection->beginTransaction();

        try {
            $rows = $this->connection->fetchAllAssociative(
                $query,
                [
                    'now' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'max_attempts' => $maxAttempts,
                ],
            );

            $messages = [];

            foreach ($rows as $row) {
                $updated = $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE notification_outbox
                        SET
                            status = 'processing',
                            attempts = attempts + 1,
                            locked_until = :lease_until,
                            lock_token = :claim_token,
                            updated_at = :now
                        WHERE id = :id
                          AND status IN ('pending', 'processing')
                        SQL,
                    [
                        'id' => $row['id'],
                        'lease_until' => $leaseUntil->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'claim_token' => $claimToken,
                        'now' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
                    ],
                );

                if ($updated !== 1) {
                    continue;
                }

                /** @var array<string, mixed> $payload */
                $payload = json_decode(
                    (string) $row['payload'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                $messages[] = new OutboxMessage(
                    id: (string) $row['id'],
                    eventType: (string) $row['event_type'],
                    payload: $payload,
                    occurredAt: new DateTimeImmutable(
                        (string) $row['occurred_at'],
                        $utc,
                    ),
                    attempt: ((int) $row['attempts']) + 1,
                    claimToken: $claimToken,
                );
            }

            $this->connection->commit();

            return $messages;
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function markPublished(
        string $id,
        string $claimToken,
        DateTimeImmutable $now,
    ): bool {
        $utc = new DateTimeZone('UTC');

        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE notification_outbox
                SET
                    status = 'published',
                    published_at = :now,
                    locked_until = NULL,
                    lock_token = NULL,
                    last_error = NULL,
                    updated_at = :now
                WHERE id = :id
                  AND status = 'processing'
                  AND lock_token = :claim_token
                SQL,
            [
                'id' => $id,
                'claim_token' => $claimToken,
                'now' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        ) === 1;
    }

    public function markFailed(
        string $id,
        string $claimToken,
        string $error,
        ?DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): bool {
        $utc = new DateTimeZone('UTC');
        $status = $nextAttemptAt === null ? 'dead' : 'pending';
        $availableAt = $nextAttemptAt ?? $now;

        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE notification_outbox
                SET
                    status = :status,
                    available_at = :available_at,
                    locked_until = NULL,
                    lock_token = NULL,
                    last_error = :last_error,
                    updated_at = :now
                WHERE id = :id
                  AND status = 'processing'
                  AND lock_token = :claim_token
                SQL,
            [
                'id' => $id,
                'claim_token' => $claimToken,
                'status' => $status,
                'available_at' => $availableAt->setTimezone($utc)->format('Y-m-d H:i:s'),
                'last_error' => substr($error, 0, 4000),
                'now' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        ) === 1;
    }
}
