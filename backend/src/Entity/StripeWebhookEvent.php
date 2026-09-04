<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_stripe_webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_webhook_event_id', columns: ['event_id'])]
final class StripeWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_id', length: 255, unique: true)]
    private string $eventId;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $eventId, string $type)
    {
        $this->eventId = $eventId;
        $this->type = $type;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getEventId(): string { return $this->eventId; }
}
