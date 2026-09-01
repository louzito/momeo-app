<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StaffMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StaffMemberRepository::class)]
#[ORM\Table(name: 'momeo_staff_member')]
#[ORM\Index(name: 'idx_momeo_staff_active', columns: ['active'])]
#[ORM\HasLifecycleCallbacks]
class StaffMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'first_name', length: 100)]
    private string $firstName = '';

    #[ORM\Column(name: 'last_name', length: 100)]
    private string $lastName = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'job_title', length: 120, nullable: true)]
    private ?string $jobTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 7)]
    private string $color = '#1f5c57';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $bookable = true;

    /** @var list<string> */
    #[ORM\Column(name: 'service_codes', type: Types::JSON)]
    private array $serviceCodes = [];

    /** @var array<string, array{enabled: bool, start: string, end: string}> */
    #[ORM\Column(name: 'working_hours', type: Types::JSON)]
    private array $workingHours = [];

    #[ORM\Column]
    private int $position = 0;

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
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): void { $this->email = $email; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): void { $this->phone = $phone; }
    public function getJobTitle(): ?string { return $this->jobTitle; }
    public function setJobTitle(?string $jobTitle): void { $this->jobTitle = $jobTitle; }
    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): void { $this->bio = $bio; }
    public function getColor(): string { return $this->color; }
    public function setColor(string $color): void { $this->color = $color; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
    public function isBookable(): bool { return $this->bookable; }
    public function setBookable(bool $bookable): void { $this->bookable = $bookable; }
    /** @return list<string> */
    public function getServiceCodes(): array { return $this->serviceCodes; }
    /** @param list<string> $serviceCodes */
    public function setServiceCodes(array $serviceCodes): void { $this->serviceCodes = array_values(array_unique($serviceCodes)); }
    /** @return array<string, array{enabled: bool, start: string, end: string}> */
    public function getWorkingHours(): array { return $this->workingHours; }
    /** @param array<string, array{enabled: bool, start: string, end: string}> $workingHours */
    public function setWorkingHours(array $workingHours): void { $this->workingHours = $workingHours; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): void { $this->position = $position; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
