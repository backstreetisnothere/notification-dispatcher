<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Notification\Channel;
use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Event\DomainEventDispatcher;
use App\Domain\Notification\Event\NotificationCreated;
use App\Shared\Clock;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateNotificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DomainEventDispatcher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, string> $destinations
     * @param array<string, mixed> $variables
     */
    public function create(
        array $destinations,
        string $template,
        array $variables = [],
    ): string {
        $this->validateDestinations($destinations);
        $this->validateTemplate($template);
        $this->validateVariables($variables);

        $now = $this->clock->now();
        $notificationId = Uuid::v7()->toRfc4122();
        $eventId = Uuid::v7()->toRfc4122();

        /** @var string */
        return $this->entityManager->wrapInTransaction(
            function () use (
                $notificationId,
                $eventId,
                $destinations,
                $template,
                $variables,
                $now,
            ): string {
                $notification = new Notification(
                    id: $notificationId,
                    destinations: $destinations,
                    template: $template,
                    variables: $variables,
                    now: $now,
                );

                $this->entityManager->persist($notification);

                $this->events->dispatch(
                    new NotificationCreated(
                        eventId: $eventId,
                        notificationId: $notificationId,
                        destinations: $destinations,
                        template: $template,
                        variables: $variables,
                        occurredAt: $now,
                    ),
                );

                return $notificationId;
            },
        );
    }

    /** @param array<string, string> $destinations */
    private function validateDestinations(array $destinations): void
    {
        if ($destinations === []) {
            throw new DomainValidationException('At least one destination is required.');
        }

        foreach ($destinations as $name => $destination) {
            if (!is_string($name) || !is_string($destination) || trim($destination) === '') {
                throw new DomainValidationException('Invalid notification destination.');
            }

            $channel = Channel::tryFrom($name);

            if ($channel === null) {
                throw new DomainValidationException(
                    sprintf('Unsupported channel "%s".', $name),
                );
            }

            $valid = match ($channel) {
                Channel::Email => filter_var($destination, FILTER_VALIDATE_EMAIL) !== false,
                Channel::Sms => preg_match('/^\+[1-9][0-9]{7,14}$/', $destination) === 1,
                Channel::Push => strlen($destination) >= 8 && strlen($destination) <= 512,
            };

            if (!$valid) {
                throw new DomainValidationException(
                    sprintf('Invalid destination for channel "%s".', $channel->value),
                );
            }
        }
    }

    private function validateTemplate(string $template): void
    {
        if (
            $template === ''
            || strlen($template) > 128
            || preg_match('/^[a-zA-Z0-9._:-]+$/', $template) !== 1
        ) {
            throw new DomainValidationException('Invalid notification template name.');
        }
    }

    /** @param array<string, mixed> $variables */
    private function validateVariables(array $variables): void
    {
        try {
            $encoded = json_encode(
                $variables,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new DomainValidationException(
                'Template variables must be JSON serializable.',
                previous: $exception,
            );
        }

        if (strlen($encoded) > 65535) {
            throw new DomainValidationException('Template variables exceed 64 KiB.');
        }
    }
}
