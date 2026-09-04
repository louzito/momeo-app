<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_waitlist_notification')]
#[ORM\UniqueConstraint(name: 'uniq_waitlist_notification_key', columns: ['idempotency_key'])]
class WaitlistNotification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WaitlistRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private WaitlistRequest $request;

    #[ORM\Column(name: 'idempotency_key', length: 64, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'slot_start', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $slotStart;

    #[ORM\Column(name: 'slot_end', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $slotEnd;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(WaitlistRequest $request, \DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($request->getId() === null) throw new \LogicException('La demande doit être enregistrée.');
        $this->request = $request;
        $this->slotStart = $start;
        $this->slotEnd = $end;
        $this->idempotencyKey = hash('sha256', $request->getId().'|'.$start->format('U').'|'.$end->format('U'));
    }

    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function markSent(): void { $this->sentAt = new \DateTimeImmutable(); }
}
