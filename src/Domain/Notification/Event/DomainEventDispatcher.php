<?php

declare(strict_types=1);

namespace App\Domain\Notification\Event;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class DomainEventDispatcher
{
    /**
     * @param iterable<DomainEventSubscriberInterface> $subscribers
     */
    public function __construct(
        #[TaggedIterator('app.domain_event_subscriber')]
        private iterable $subscribers,
    ) {
    }

    public function dispatch(object $event): void
    {
        foreach ($this->subscribers as $subscriber) {
            if (in_array($event::class, $subscriber->subscribedEvents(), true)) {
                $subscriber->handle($event);
            }
        }
    }
}
