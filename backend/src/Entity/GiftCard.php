<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\Customer\Customer;

/** Crédit monétaire ; les GiftVoucher historiques restent indépendants. */
#[ORM\Entity(repositoryClass: \App\Repository\GiftCardRepository::class)]
#[ORM\Table(name: 'todatempo_gift_card')]
#[ORM\UniqueConstraint(name: 'gift_card_purchase_line', columns: ['purchase_order_number', 'purchase_line'])]
class GiftCard
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 32, unique: true)]
    private string $code;
    #[ORM\Column(length: 20)]
    private string $status = 'active';
    #[ORM\Column]
    private int $available;
    #[ORM\Column]
    private int $reserved = 0;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'beneficiary_id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $beneficiary = null;

    public function __construct(
        #[ORM\Column(length: 100)] private string $establishment,
        #[ORM\Column(name: 'channel_code', length: 255)] private string $channelCode,
        #[ORM\Column(length: 3)] private string $currency,
        #[ORM\Column(name: 'initial_amount')] private int $initialAmount,
        #[ORM\Column(name: 'purchase_order_number', length: 255)] private string $purchaseOrderNumber,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')] private \DateTimeImmutable $expiresAt,
    ) {
        self::positive($initialAmount);
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || $establishment === '' || $channelCode === '' || $purchaseOrderNumber === '') {
            throw new \InvalidArgumentException('Les informations de la carte sont incomplètes.');
        }
        $this->createdAt = new \DateTimeImmutable();
        if ($expiresAt <= $this->createdAt) throw new \InvalidArgumentException('La date d’expiration doit être future.');
        $this->code = strtoupper(bin2hex(random_bytes(16)));
        $this->available = $initialAmount;
    }

    #[ORM\Column(name: 'purchase_line', options: ['default' => 0])]
    private int $purchaseLine = 0;
    public function getPurchaseLine(): int { return $this->purchaseLine; }
    public function setPurchaseLine(int $line): void { if ($line < 0) throw new \InvalidArgumentException('Ligne invalide.'); $this->purchaseLine = $line; }

    public function getBeneficiary(): ?Customer { return $this->beneficiary; }

    public function claim(Customer $customer): void
    {
        if ($customer->getId() === null) throw new \DomainException('Compte client introuvable.');
        if ($this->beneficiary !== null && $this->beneficiary->getId() !== $customer->getId()) {
            throw new \DomainException('Cette carte ne peut pas être rattachée à ce compte.');
        }
        $this->beneficiary = $customer;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getAvailable(): int { return $this->available; }
    public function getReserved(): int { return $this->reserved; }
    public function getInitialAmount(): int { return $this->initialAmount; }
    public function getCurrency(): string { return $this->currency; }
    public function getEstablishment(): string { return $this->establishment; }
    public function getChannelCode(): string { return $this->channelCode; }
    public function getPurchaseOrderNumber(): string { return $this->purchaseOrderNumber; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStatus(): string { return $this->status === 'active' && $this->expiresAt <= new \DateTimeImmutable() ? 'expired' : $this->status; }
    public function deactivate(): void { $this->status = 'inactive'; }

    public function assertShop(string $establishment, string $channel, string $currency): void
    {
        if ($this->establishment !== $establishment || $this->channelCode !== $channel || $this->currency !== $currency) {
            throw new \DomainException('Cette carte ne peut pas être utilisée dans cette boutique ou cette devise.');
        }
    }

    public function reserve(int $amount): void
    {
        $this->assertUsable();
        self::positive($amount);
        if ($amount > $this->available) throw new \DomainException('Le solde disponible est insuffisant.');
        $this->available -= $amount;
        $this->reserved += $amount;
    }

    public function debit(int $amount): void
    {
        // Le crédit a été validé à la réservation ; honorer un paiement confirmé
        // même si son webhook est reçu après l’expiration de la carte.
        if ($this->status !== 'active') throw new \DomainException('Cette carte est inactive.');
        $this->assertReserved($amount);
        $this->reserved -= $amount;
    }

    /** Restitution autorisée même après expiration, sans prolongation de validité. */
    public function release(int $amount): void
    {
        $this->assertReserved($amount);
        $this->reserved -= $amount;
        $this->available += $amount;
    }

    public function refund(int $amount): void
    {
        self::positive($amount);
        if ($amount > $this->initialAmount - $this->available - $this->reserved) throw new \DomainException('Le montant dépasse le solde débité.');
        $this->available += $amount;
    }

    private function assertUsable(): void
    {
        if ($this->getStatus() !== 'active') throw new \DomainException('Cette carte est expirée ou inactive.');
    }

    private function assertReserved(int $amount): void
    {
        self::positive($amount);
        if ($amount > $this->reserved) throw new \DomainException('Le montant dépasse le solde réservé.');
    }

    private static function positive(int $amount): void
    {
        if ($amount <= 0 || $amount > 2147483647) throw new \InvalidArgumentException('Le montant en centimes est invalide.');
    }
}
