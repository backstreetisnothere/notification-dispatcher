<?php

declare(strict_types=1);

namespace App\Domain\Notification\Event;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.domain_event_subscriber')]
interface DomainEventSubscriberInterface
{
    /** @return list<class-string> */
    public function subscribedEvents(): array;

    public function handle(object $event): void;
}
