<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReminderDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReminderDeliveryRepository::class)]
#[ORM\Table(name: 'todatempo_reminder_delivery')]
#[ORM\UniqueConstraint(name: 'uniq_reminder_idempotency_key', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_reminder_status', columns: ['status'])]
class ReminderDelivery
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Booking::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    #[ORM\Column(name: 'idempotency_key', length: 64)]
    private string $idempotencyKey;

    #[ORM\Column(length: 10)]
    private string $channel;

    #[ORM\Column(name: 'hours_before')]
    private int $hoursBefore;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(name: 'provider_reference', length: 255, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'delivered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    public function __construct(Booking $booking, string $channel, int $hoursBefore)
    {
        $this->booking = $booking;
        $this->channel = $channel;
        $this->hoursBefore = $hoursBefore;
        $this->createdAt = new \DateTimeImmutable();
        $this->idempotencyKey = hash('sha256', implode('|', [
            $booking->getReference(), $booking->getSlotStart()->format('U'), $channel, (string) $hoursBefore,
        ]));
    }

    public function getId(): ?int { return $this->id; }
    public function getBooking(): Booking { return $this->booking; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getChannel(): string { return $this->channel; }
    public function getHoursBefore(): int { return $this->hoursBefore; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getProviderReference(): ?string { return $this->providerReference; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function markAttempt(): void { ++$this->attempts; }
    public function markSent(?string $reference = null): void { $this->status = self::STATUS_SENT; $this->providerReference = $reference; $this->lastError = null; $this->deliveredAt = new \DateTimeImmutable(); }
    public function markSkipped(string $reason): void { $this->status = self::STATUS_SKIPPED; $this->lastError = $reason; }
    public function markError(string $error): void { $this->status = self::STATUS_ERROR; $this->lastError = mb_substr($error, 0, 4000); }
}
