<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_client_profile')]
#[ORM\UniqueConstraint(name: 'uniq_todatempo_client_profile_booking_email', columns: ['booking_email'])]
#[ORM\HasLifecycleCallbacks]
class ClientProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'booking_email', length: 180)]
    private string $bookingEmail;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'first_name', length: 100)]
    private string $firstName = '';

    #[ORM\Column(name: 'last_name', length: 100)]
    private string $lastName = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'visible_notes', type: Types::TEXT, nullable: true)]
    private ?string $visibleNotes = null;

    #[ORM\Column(name: 'internal_notes', type: Types::TEXT, nullable: true)]
    private ?string $internalNotes = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $allergies = null;

    #[ORM\Column(name: 'contraindications', type: Types::TEXT, nullable: true)]
    private ?string $contraindications = null;

    /** @var array<string, bool> */
    #[ORM\Column(type: Types::JSON)]
    private array $consents = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'consent_history', type: Types::JSON)]
    private array $consentHistory = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email)
    {
        $this->bookingEmail = $this->email = mb_strtolower(trim($email));
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBookingEmail(): string { return $this->bookingEmail; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $value): void { $this->email = mb_strtolower(trim($value)); }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $value): void { $this->firstName = trim($value); }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $value): void { $this->lastName = trim($value); }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $value): void { $this->phone = $value !== null && trim($value) !== '' ? trim($value) : null; }
    public function getVisibleNotes(): ?string { return $this->visibleNotes; }
    public function setVisibleNotes(?string $value): void { $this->visibleNotes = $this->cleanText($value); }
    public function getInternalNotes(): ?string { return $this->internalNotes; }
    public function setInternalNotes(?string $value): void { $this->internalNotes = $this->cleanText($value); }
    /** @return list<string> */
    public function getTags(): array { return $this->tags; }
    /** @param list<string> $value */
    public function setTags(array $value): void { $this->tags = array_values(array_unique(array_filter(array_map('trim', $value)))); }
    public function getAllergies(): ?string { return $this->allergies; }
    public function setAllergies(?string $value): void { $this->allergies = $this->cleanText($value); }
    public function getContraindications(): ?string { return $this->contraindications; }
    public function setContraindications(?string $value): void { $this->contraindications = $this->cleanText($value); }
    /** @return array<string, bool> */
    public function getConsents(): array { return $this->consents; }
    /** @return list<array<string, mixed>> */
    public function getConsentHistory(): array { return $this->consentHistory; }

    public function recordConsent(string $type, bool $granted, string $actor): void
    {
        $this->consents[$type] = $granted;
        $this->consentHistory[] = ['type' => $type, 'granted' => $granted, 'recordedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'recordedBy' => $actor];
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    private function cleanText(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
