<?php

declare(strict_types=1);

namespace App\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Dead = 'dead';
}
