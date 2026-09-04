<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WaitlistRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WaitlistRequestRepository::class)]
#[ORM\Table(name: 'todatempo_waitlist_request')]
#[ORM\Index(name: 'idx_waitlist_matching', columns: ['status', 'service_code', 'period_start', 'period_end'])]
#[ORM\UniqueConstraint(name: 'uniq_waitlist_unsubscribe_token', columns: ['unsubscribe_token'])]
class WaitlistRequest
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'unsubscribe_token', length: 64, unique: true)]
    private string $unsubscribeToken;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'service_code', length: 255)]
    private string $serviceCode;

    #[ORM\Column(name: 'service_name', length: 255)]
    private string $serviceName;

    #[ORM\Column(name: 'customer_first_name', length: 100)]
    private string $customerFirstName;

    #[ORM\Column(name: 'customer_last_name', length: 100)]
    private string $customerLastName;

    #[ORM\Column(name: 'customer_email', length: 180)]
    private string $customerEmail;

    #[ORM\Column(name: 'period_start', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'unsubscribed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $unsubscribedAt = null;

    public function __construct()
    {
        $this->unsubscribeToken = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUnsubscribeToken(): string { return $this->unsubscribeToken; }
    public function getStatus(): string { return $this->status; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function unsubscribe(): void { $this->status = self::STATUS_UNSUBSCRIBED; $this->unsubscribedAt ??= new \DateTimeImmutable(); }
    public function getServiceCode(): string { return $this->serviceCode; }
    public function setServiceCode(string $value): void { $this->serviceCode = $value; }
    public function getServiceName(): string { return $this->serviceName; }
    public function setServiceName(string $value): void { $this->serviceName = $value; }
    public function getCustomerFirstName(): string { return $this->customerFirstName; }
    public function setCustomerFirstName(string $value): void { $this->customerFirstName = $value; }
    public function getCustomerLastName(): string { return $this->customerLastName; }
    public function setCustomerLastName(string $value): void { $this->customerLastName = $value; }
    public function getCustomerEmail(): string { return $this->customerEmail; }
    public function setCustomerEmail(string $value): void { $this->customerEmail = $value; }
    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $value): void { $this->periodStart = $value; }
    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $value): void { $this->periodEnd = $value; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUnsubscribedAt(): ?\DateTimeImmutable { return $this->unsubscribedAt; }
}
