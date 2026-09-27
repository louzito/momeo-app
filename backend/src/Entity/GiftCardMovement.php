<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Journal comptable traçable, jamais utilisé pour reconstruire le solde de la carte. */
#[ORM\Entity(repositoryClass: \App\Repository\GiftCardMovementRepository::class)]
#[ORM\Table(name: 'todatempo_gift_card_movement')]
#[ORM\UniqueConstraint(name: 'uniq_gift_card_operation', columns: ['card_id', 'operation_key'])]
#[ORM\Index(name: 'idx_gift_card_order', columns: ['order_number'])]
class GiftCardMovement
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'available_after')]
    private int $availableAfter;
    #[ORM\Column(name: 'reserved_after')]
    private int $reservedAfter;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GiftCard::class), ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private GiftCard $card,
        #[ORM\Column(name: 'operation_key', length: 64)] private string $operationKey,
        #[ORM\Column(length: 10)] private string $kind,
        #[ORM\Column(name: 'order_number', length: 255)] private string $orderNumber,
        #[ORM\Column] private int $amount,
        #[ORM\Column(length: 255, nullable: true)] private ?string $reference = null,
    ) {
        if ($amount <= 0 || !in_array($kind, ['issue', 'reserve', 'debit', 'release', 'refund'], true) || !preg_match('/^[a-f0-9]{64}$/D', $operationKey) || $orderNumber === '') {
            throw new \InvalidArgumentException('Mouvement de carte invalide.');
        }
        $this->createdAt = new \DateTimeImmutable();
        $this->availableAfter = $card->getAvailable();
        $this->reservedAfter = $card->getReserved();
    }

    public function getId(): ?int { return $this->id; }
    public function getCard(): GiftCard { return $this->card; }
    public function getOperationKey(): string { return $this->operationKey; }
    public function getReference(): ?string { return $this->reference; }
    public function getKind(): string { return $this->kind; }
    public function getOrderNumber(): string { return $this->orderNumber; }
    public function getAmount(): int { return $this->amount; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAvailableAfter(): int { return $this->availableAfter; }
    public function getReservedAfter(): int { return $this->reservedAfter; }
}
