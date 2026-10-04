<?php

declare(strict_types=1);

namespace App\Domain\Notification;

enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
}
