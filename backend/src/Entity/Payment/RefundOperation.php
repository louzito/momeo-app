<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use App\Entity\Order\Order;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_refund_operation')]
#[ORM\UniqueConstraint(name: 'uniq_todatempo_refund_key', columns: ['idempotency_key'])]
final class RefundOperation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(name: 'idempotency_key', length: 100, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 30)]
    private string $status = 'pending';

    #[ORM\Column(length: 50)]
    private string $provider;

    #[ORM\Column(name: 'provider_reference', length: 255, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(name: 'credit_note_number', length: 60, nullable: true)]
    private ?string $creditNoteNumber = null;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Payment $payment, Order $order, string $key, int $amount, string $currency, string $provider, string $actor, ?string $reason)
    {
        $this->payment = $payment; $this->order = $order; $this->idempotencyKey = $key;
        $this->amount = $amount; $this->currency = $currency; $this->provider = $provider;
        $this->actor = $actor; $this->reason = $reason; $this->createdAt = new \DateTimeImmutable();
    }

    public function complete(string $reference, string $creditNote): void
    {
        $this->status = 'completed'; $this->providerReference = $reference;
        $this->creditNoteNumber = $creditNote; $this->completedAt = new \DateTimeImmutable();
    }
    public function fail(): void { $this->status = 'failed'; }
    public function getStatus(): string { return $this->status; }
    public function getAmount(): int { return $this->amount; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function belongsTo(Payment $payment): bool { return $this->payment === $payment; }
    /** @return array<string, mixed> */
    public function normalize(): array { return ['id' => $this->id, 'idempotencyKey' => $this->idempotencyKey, 'amount' => $this->amount, 'currency' => $this->currency, 'status' => $this->status, 'provider' => $this->provider, 'providerReference' => $this->providerReference, 'creditNoteNumber' => $this->creditNoteNumber, 'actor' => $this->actor, 'reason' => $this->reason, 'createdAt' => $this->createdAt->format(DATE_ATOM), 'completedAt' => $this->completedAt?->format(DATE_ATOM)]; }
}
