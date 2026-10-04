<?php

declare(strict_types=1);

namespace App\Domain\Notification\Entity;

use App\Domain\Notification\Channel;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
final class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $destinations;

    #[ORM\Column(length: 128)]
    private string $template;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $variables;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, string> $destinations
     * @param array<string, mixed> $variables
     */
    public function __construct(
        string $id,
        array $destinations,
        string $template,
        array $variables,
        DateTimeImmutable $now,
    ) {
        $this->id = $id;
        $this->destinations = $destinations;
        $this->template = $template;
        $this->variables = $variables;
        $this->status = 'queued';
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string, string> */
    public function destinations(): array
    {
        return $this->destinations;
    }

    public function template(): string
    {
        return $this->template;
    }

    /** @return array<string, mixed> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function status(): string
    {
        return $this->status;
    }
}
