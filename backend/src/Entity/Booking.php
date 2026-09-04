<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'momeo_booking')]
#[ORM\Index(name: 'idx_momeo_booking_status', columns: ['status'])]
#[ORM\Index(name: 'idx_momeo_booking_start', columns: ['slot_start'])]
#[ORM\Index(name: 'idx_momeo_booking_service', columns: ['service_code'])]
#[ORM\Index(name: 'idx_momeo_booking_staff', columns: ['staff_member_id'])]
#[ORM\Index(name: 'idx_momeo_booking_planning', columns: ['planning_code'])]
#[ORM\Index(name: 'idx_momeo_booking_resource', columns: ['resource_code'])]
#[ORM\UniqueConstraint(name: 'uniq_momeo_booking_reference', columns: ['reference'])]
#[ORM\UniqueConstraint(name: 'uniq_momeo_booking_public_token', columns: ['public_token'])]
#[ORM\UniqueConstraint(name: 'uniq_momeo_booking_staff_start', columns: ['staff_member_id', 'slot_start'])]
#[ORM\HasLifecycleCallbacks]
class Booking
{
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_POSTPONED = 'postponed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $reference;

    #[ORM\Column(name: 'public_token', length: 32, unique: true)]
    private string $publicToken;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column(length: 20)]
    private string $source = 'direct';

    #[ORM\Column(name: 'service_code', length: 255)]
    private string $serviceCode;

    #[ORM\Column(name: 'service_name', length: 255)]
    private string $serviceName;

    #[ORM\Column(name: 'planning_code', length: 255, nullable: true)]
    private ?string $planningCode = null;

    #[ORM\Column(name: 'resource_code', length: 255, nullable: true)]
    private ?string $resourceCode = null;

    #[ORM\ManyToOne(targetEntity: StaffMember::class)]
    #[ORM\JoinColumn(name: 'staff_member_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?StaffMember $staffMember = null;

    #[ORM\Column(name: 'staff_name', length: 255, nullable: true)]
    private ?string $staffName = null;

    #[ORM\Column(name: 'customer_first_name', length: 100)]
    private string $customerFirstName;

    #[ORM\Column(name: 'customer_last_name', length: 100)]
    private string $customerLastName;

    #[ORM\Column(name: 'customer_email', length: 180)]
    private string $customerEmail;

    #[ORM\Column(name: 'customer_phone', length: 40, nullable: true)]
    private ?string $customerPhone = null;

    #[ORM\Column(name: 'customer_notes', type: Types::TEXT, nullable: true)]
    private ?string $customerNotes = null;

    #[ORM\Column(name: 'slot_start', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $slotStart;

    #[ORM\Column(name: 'slot_end', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $slotEnd;

    #[ORM\Column(name: 'order_number', length: 255, nullable: true)]
    private ?string $orderNumber = null;

    #[ORM\Column(name: 'voucher_code', length: 32, nullable: true)]
    private ?string $voucherCode = null;

    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    #[ORM\Column(nullable: true)]
    private ?int $amount = null;

    #[ORM\Column(name: 'total_amount', nullable: true)]
    private ?int $totalAmount = null;

    #[ORM\Column(name: 'balance_due', nullable: true)]
    private ?int $balanceDue = null;

    #[ORM\Column(name: 'currency_code', length: 3)]
    private string $currencyCode = 'EUR';

    #[ORM\Column(name: 'payment_state', length: 30, nullable: true)]
    private ?string $paymentState = null;

    #[ORM\Column(name: 'postponed_reason', type: Types::TEXT, nullable: true)]
    private ?string $postponedReason = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'change_history', type: Types::JSON)]
    private array $changeHistory = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): void { $this->reference = $reference; }
    public function getPublicToken(): string { return $this->publicToken; }
    public function setPublicToken(string $publicToken): void { $this->publicToken = $publicToken; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): void { $this->source = $source; }
    public function getServiceCode(): string { return $this->serviceCode; }
    public function setServiceCode(string $serviceCode): void { $this->serviceCode = $serviceCode; }
    public function getServiceName(): string { return $this->serviceName; }
    public function setServiceName(string $serviceName): void { $this->serviceName = $serviceName; }
    public function getPlanningCode(): ?string { return $this->planningCode; }
    public function setPlanningCode(?string $planningCode): void { $this->planningCode = $planningCode; }
    public function getResourceCode(): ?string { return $this->resourceCode; }
    public function setResourceCode(?string $resourceCode): void { $this->resourceCode = $resourceCode; }
    public function getStaffMember(): ?StaffMember { return $this->staffMember; }
    public function setStaffMember(?StaffMember $staffMember): void { $this->staffMember = $staffMember; }
    public function getStaffName(): ?string { return $this->staffName; }
    public function setStaffName(?string $staffName): void { $this->staffName = $staffName; }
    public function getCustomerFirstName(): string { return $this->customerFirstName; }
    public function setCustomerFirstName(string $value): void { $this->customerFirstName = $value; }
    public function getCustomerLastName(): string { return $this->customerLastName; }
    public function setCustomerLastName(string $value): void { $this->customerLastName = $value; }
    public function getCustomerEmail(): string { return $this->customerEmail; }
    public function setCustomerEmail(string $value): void { $this->customerEmail = $value; }
    public function getCustomerPhone(): ?string { return $this->customerPhone; }
    public function setCustomerPhone(?string $value): void { $this->customerPhone = $value; }
    public function getCustomerNotes(): ?string { return $this->customerNotes; }
    public function setCustomerNotes(?string $value): void { $this->customerNotes = $value; }
    public function getSlotStart(): \DateTimeImmutable { return $this->slotStart; }
    public function setSlotStart(\DateTimeImmutable $value): void { $this->slotStart = $value; }
    public function getSlotEnd(): \DateTimeImmutable { return $this->slotEnd; }
    public function setSlotEnd(\DateTimeImmutable $value): void { $this->slotEnd = $value; }
    public function getOrderNumber(): ?string { return $this->orderNumber; }
    public function setOrderNumber(?string $value): void { $this->orderNumber = $value; }
    public function getVoucherCode(): ?string { return $this->voucherCode; }
    public function setVoucherCode(?string $value): void { $this->voucherCode = $value; }
    public function getOptions(): array { return $this->options; }
    public function setOptions(array $value): void { $this->options = $value; }
    public function getAmount(): ?int { return $this->amount; }
    public function setAmount(?int $value): void { $this->amount = $value; }
    public function getTotalAmount(): ?int { return $this->totalAmount; }
    public function setTotalAmount(?int $value): void { $this->totalAmount = $value; }
    public function getBalanceDue(): ?int { return $this->balanceDue; }
    public function setBalanceDue(?int $value): void { $this->balanceDue = $value; }
    public function getCurrencyCode(): string { return $this->currencyCode; }
    public function setCurrencyCode(string $value): void { $this->currencyCode = $value; }
    public function getPaymentState(): ?string { return $this->paymentState; }
    public function setPaymentState(?string $value): void { $this->paymentState = $value; }
    public function getPostponedReason(): ?string { return $this->postponedReason; }
    public function setPostponedReason(?string $value): void { $this->postponedReason = $value; }
    /** @return list<array<string, mixed>> */
    public function getChangeHistory(): array { return $this->changeHistory; }
    /** @param array<string, mixed> $change */
    public function recordChange(array $change): void { $this->changeHistory[] = $change; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
