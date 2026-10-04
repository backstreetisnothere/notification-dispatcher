<?php

declare(strict_types=1);

namespace App\Domain\Provider;

interface NotificationProvider
{
    public function name(): string;

    public function send(NotificationDelivery $delivery): void;
}
