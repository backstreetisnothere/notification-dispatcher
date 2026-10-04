<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Provider\NotificationDelivery;
use App\Domain\Provider\NotificationProvider;
use Psr\Log\LoggerInterface;

final readonly class LogNotificationProvider implements NotificationProvider
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'sandbox';
    }

    public function send(NotificationDelivery $delivery): void
    {
        $this->logger->info('Synthetic notification delivered.', [
            'eventId' => $delivery->eventId,
            'notificationId' => $delivery->notificationId,
            'channel' => $delivery->channel,
            'recipient' => $delivery->recipient,
            'template' => $delivery->template,
            'deliveryAttempt' => $delivery->deliveryAttempt,
        ]);
    }
}
