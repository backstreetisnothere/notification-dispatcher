<?php

declare(strict_types=1);

namespace App\Outbox\Entity;

use App\Outbox\OutboxStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_outbox')]
final class Outbox
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $aggregateType;

    #[ORM\Column(type: Types::GUID)]
    private string $aggregateId;

    #[ORM\Column(length: 128)]
    private string $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $availableAt;

    #[ORM\Column(enumType: OutboxStatus::class, length: 16)]
    private OutboxStatus $status;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $lockToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $id,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        DateTimeImmutable $occurredAt,
    ) {
        $this->id = $id;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt;
        $this->availableAt = $occurredAt;
        $this->status = OutboxStatus::Pending;
        $this->updatedAt = $occurredAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): OutboxStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
