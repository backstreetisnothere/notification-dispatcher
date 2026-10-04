<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Provider\NotificationDelivery;
use App\Domain\Provider\NotificationProvider;
use App\Resilience\CircuitBreaker\CircuitBreaker;

final readonly class CircuitBreakingNotificationProvider implements NotificationProvider
{
    public function __construct(
        private NotificationProvider $inner,
        private CircuitBreaker $circuitBreaker,
    ) {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function send(NotificationDelivery $delivery): void
    {
        $circuitName = sprintf('%s:%s', $this->name(), $delivery->channel);

        $this->circuitBreaker->execute(
            name: $circuitName,
            operation: function () use ($delivery): void {
                $this->inner->send($delivery);
            },
        );
    }
}
