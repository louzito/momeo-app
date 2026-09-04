<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookableResourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookableResourceRepository::class)]
#[ORM\Table(name: 'todatempo_bookable_resource')]
#[ORM\UniqueConstraint(name: 'uniq_todatempo_resource_code', columns: ['code'])]
#[ORM\Index(name: 'idx_todatempo_resource_active', columns: ['active'])]
#[ORM\HasLifecycleCallbacks]
class BookableResource
{
    public const TYPES = ['cabin', 'room', 'machine', 'chair'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $code = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 20)]
    private string $type = 'room';

    #[ORM\Column]
    private int $capacity = 1;

    /** @var array<string, list<array{start: string, end: string}>> */
    #[ORM\Column(type: Types::JSON)]
    private array $calendar = [];

    #[ORM\Column]
    private bool $active = true;

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
    public function getType(): string { return $this->type; }
    public function setType(string $value): void { $this->type = $value; }
    public function getCapacity(): int { return $this->capacity; }
    public function setCapacity(int $value): void { $this->capacity = $value; }
    /** @return array<string, list<array{start: string, end: string}>> */
    public function getCalendar(): array { return $this->calendar; }
    /** @param array<string, list<array{start: string, end: string}>> $value */
    public function setCalendar(array $value): void { $this->calendar = $value; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): void { $this->active = $value; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
