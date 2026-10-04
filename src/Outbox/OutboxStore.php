<?php

declare(strict_types=1);

namespace App\Outbox;

use DateTimeImmutable;

interface OutboxStore
{
    /** @return list<OutboxMessage> */
    public function claimBatch(
        DateTimeImmutable $now,
        int $limit,
        DateTimeImmutable $leaseUntil,
        int $maxAttempts,
    ): array;

    public function markPublished(
        string $id,
        string $claimToken,
        DateTimeImmutable $now,
    ): bool;

    public function markFailed(
        string $id,
        string $claimToken,
        string $error,
        ?DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): bool;
}
