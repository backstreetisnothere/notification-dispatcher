<?php

declare(strict_types=1);

namespace App\Outbox;

use DateTimeImmutable;

final readonly class OutboxMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $eventType,
        public array $payload,
        public DateTimeImmutable $occurredAt,
        public int $attempt,
        public string $claimToken,
    ) {
    }
}
