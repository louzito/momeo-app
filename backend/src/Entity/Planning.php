<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningRepository::class)]
#[ORM\Table(name: 'momeo_planning')]
#[ORM\UniqueConstraint(name: 'uniq_momeo_planning_code', columns: ['code'])]
#[ORM\Index(name: 'idx_momeo_planning_active', columns: ['active'])]
#[ORM\HasLifecycleCallbacks]
class Planning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $code = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Paris';

    #[ORM\ManyToOne(targetEntity: StaffMember::class)]
    #[ORM\JoinColumn(name: 'staff_member_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?StaffMember $staffMember = null;

    /** @var array<string, list<array{start: string, end: string}>> */
    #[ORM\Column(type: Types::JSON)]
    private array $days = [];

    #[ORM\Column]
    private int $capacity = 1;

    /** @var list<string> */
    #[ORM\Column(name: 'service_codes', type: Types::JSON)]
    private array $serviceCodes = [];

    #[ORM\Column]
    private bool $active = true;

    /** Original taxon configuration, kept so date-based historical schedules lose no data. */
    #[ORM\Column(name: 'legacy_config', type: Types::JSON, nullable: true)]
    private ?array $legacyConfig = null;

    #[ORM\Column(name: 'legacy_taxon_code', length: 255, nullable: true, unique: true)]
    private ?string $legacyTaxonCode = null;

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
    public function getCode(): string { return $this->code; }
    public function setCode(string $value): void { $this->code = $value; }
    public function getName(): string { return $this->name; }
    public function setName(string $value): void { $this->name = $value; }
    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(string $value): void { $this->timezone = $value; }
    public function getStaffMember(): ?StaffMember { return $this->staffMember; }
    public function setStaffMember(?StaffMember $value): void { $this->staffMember = $value; }
    /** @return array<string, list<array{start: string, end: string}>> */
    public function getDays(): array { return $this->days; }
    /** @param array<string, list<array{start: string, end: string}>> $value */
    public function setDays(array $value): void { $this->days = $value; }
    public function getCapacity(): int { return $this->capacity; }
    public function setCapacity(int $value): void { $this->capacity = $value; }
    /** @return list<string> */
    public function getServiceCodes(): array { return $this->serviceCodes; }
    /** @param list<string> $value */
    public function setServiceCodes(array $value): void { $this->serviceCodes = array_values(array_unique($value)); }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): void { $this->active = $value; }
    /** @return array<string, mixed>|null */
    public function getLegacyConfig(): ?array { return $this->legacyConfig; }
    /** @param array<string, mixed>|null $value */
    public function setLegacyConfig(?array $value): void { $this->legacyConfig = $value; }
    public function getLegacyTaxonCode(): ?string { return $this->legacyTaxonCode; }
    public function setLegacyTaxonCode(?string $value): void { $this->legacyTaxonCode = $value; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
